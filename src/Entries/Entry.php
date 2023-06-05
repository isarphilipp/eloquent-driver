<?php

namespace Statamic\Eloquent\Entries;

use Statamic\Eloquent\Entries\EntryModel as Model;
use Statamic\Entries\Entry as FileEntry;
use Statamic\Entries\EntryCollection;
use Statamic\Facades\Blink;
use Statamic\Facades\Entry as EntryFacade;

class Entry extends FileEntry
{

    protected $model;

    public static function fromModel(Model $model)
    {
        $entry = (new static())
            ->origin($model->origin_id)
            ->locale($model->site)
            ->slug($model->slug)
            ->collection($model->collection)
            ->data($model->data)
            ->blueprint($model->data['blueprint'] ?? null)
            ->published($model->published)
            ->model($model);

        if ($model->date && $entry->collection()->dated()) {
            $entry->date($model->date);
        }


        return $entry;
    }

    public function toModel()
    {
        $class = app('statamic.eloquent.entries.model');

        $data = $this->data();

        if ($this->blueprint && $this->collection()->entryBlueprints()->count() > 1)
        {
            $data['blueprint'] = $this->blueprint;
        }

        return $class::findOrNew($this->id())->fill([
            'id'         => $this->id(),
            'origin_id'  => $this->originId(),
            'site'       => $this->locale(),
            'slug'       => $this->slug(),
            'uri'        => $this->uri(),
            'date'       => $this->hasDate() ? $this->date() : null,
            'collection' => $this->collectionHandle(),
            'data'       => $data->except(EntryQueryBuilder::COLUMNS),
            'published'  => $this->published(),
            'status'     => $this->status(),
        ]);
    }

    public function model($model = null)
    {
        if (func_num_args() === 0)
        {
            return $this->model;
        }

        $this->model = $model;

        $this->id($model->id);

        return $this;
    }

    public function lastModified()
    {
        return $this->model->updated_at;
    }

    public function origin($origin = null)
    {
        if (func_num_args() > 0)
        {
            $this->origin = $origin;

            return $this;
        }

        if ($this->origin)
        {
            return $this->origin;
        }

        if ( ! $this->model->origin)
        {
            return null;
        }

        return self::fromModel($this->model->origin);
    }

    public function originId()
    {
        return optional($this->origin)->id() ?? optional($this->model)->origin_id;
    }

    public function hasOrigin()
    {
        return $this->originId() !== null;
    }

    public function descendants()
    {
        /**
         * Strongly opinionated change to increase performance for our use case:
         * We assume that there's max 3 layers of descendants (root -> origin -> another_origin -> entry)
         *
         * This function could need some cleanup to remove duplication. With a fresh mind, it's probably
         * refactorable into something recursive OR something using ->with(['descendants', 'descendants.descendants']) on eloquent
         */

        if(!$this->id()){
            return EntryCollection::make([]);
        }

        // First pass: get own localizations
        if ( ! $this->localizations)
        {
            $this->localizations = Blink::once("eloquent-builder::descendants::{$this->id()}", function () {
                return EntryFacade::query()
                    ->where('collection', $this->collectionHandle())
                    ->where('origin', $this->id())
                    ->get()
                    ->keyBy
                    ->locale();
            });
        }

        // Second pass: get localizations of localizations, but in one go
        $idsOfFirstLevel = $this->localizations
            ->map(function (Entry $localization) {
                return $localization->id();
            })
            ->unique();

        if ($idsOfFirstLevel->count() == 0)
        {
            return $this->localizations;
        }

        $hashedOriginIds = md5($idsOfFirstLevel->implode('-'));

        $childLocalizationsOfFirstLevel = Blink::once("eloquent-builder::descendants::{$hashedOriginIds}", function () use ($idsOfFirstLevel) {
            return EntryFacade::query()
                ->where('collection', $this->collectionHandle())
                ->whereIn('origin', $idsOfFirstLevel)
                ->get()
                ->keyBy
                ->locale();
        });

        $allLocalizations = $this->localizations->merge($childLocalizationsOfFirstLevel);


        // Third pass: get localizations of localizations of localizations, but in one go
        $idsOfSecondLevel = $childLocalizationsOfFirstLevel
            ->map(function (Entry $localization) {
                return $localization->id();
            })
            ->unique();

        if ($idsOfSecondLevel->count() == 0)
        {
            return $allLocalizations;
        }

        $hashedOriginIds = md5($idsOfSecondLevel->implode('-'));

        $childLocalizationsOfSecondLevel = Blink::once("eloquent-builder::descendants::{$hashedOriginIds}", function () use ($idsOfSecondLevel) {
            return EntryFacade::query()
                ->where('collection', $this->collectionHandle())
                ->whereIn('origin', $idsOfSecondLevel)
                ->get()
                ->keyBy
                ->locale();
        });

        $allLocalizations = $allLocalizations->merge($childLocalizationsOfSecondLevel);

        return $allLocalizations;
    }

}
