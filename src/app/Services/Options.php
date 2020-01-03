<?php

namespace LaravelEnso\Select\app\Services;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class Options implements Responsable
{
    private const Limit = 100;

    private $queryAttributes;
    private $query;
    private $request;
    private $selected;
    private $trackBy;
    private $value;
    private $resource;

    public function __construct(Builder $query, string $trackBy, array $queryAttributes, ?string $resource)
    {
        $this->query = $query;
        $this->trackBy = $trackBy;
        $this->queryAttributes = $queryAttributes;
        $this->resource = $resource;
    }

    public function toResponse($request)
    {
        $this->request = $request;

        return $this->resource
            ? $this->resource::collection($this->data())
            : $this->data();
    }

    private function data()
    {
        return $this->value()
            ->params()
            ->pivotParams()
            ->selected()
            ->search()
            ->order()
            ->limit()
            ->get();
    }

    private function value()
    {
        $this->value = $this->request->has('value')
            ? (array) $this->request->get('value')
            : [];

        return $this;
    }

    private function params()
    {
        if (! $this->request->has('params')) {
            return $this;
        }

        collect(json_decode($this->request->get('params')))
            ->each(fn($value, $column) => (
                $value === null
                    ? $this->query->whereNull($column)
                    : $this->query->whereIn($column, (array) $value)
            ));

        return $this;
    }

    private function pivotParams()
    {
        if (! $this->request->has('pivotParams')) {
            return $this;
        }

        collect(json_decode($this->request->get('pivotParams')))
            ->each(fn($param, $relation) => (
                $this->query->whereHas($relation, fn($query) => (
                    collect($param)->each(fn($value, $attribute) => (
                        $query->whereIn($attribute, (array) $value)
                    ))
                ))
            ));

        return $this;
    }

    private function selected()
    {
        $query = clone $this->query;

        $this->selected = $query->whereIn($this->trackBy, $this->value)->get();

        return $this;
    }

    private function search()
    {
        if (! $this->request->filled('query')) {
            return $this;
        }

        $this->searchArguments()->each(fn($argument) => (
            $this->query->where(fn($query) => $this->matchArgument($query, $argument))
        ));

        return $this;
    }

    private function searchArguments()
    {
        return collect(explode(' ', $this->request->get('query')));
    }

    private function matchArgument($query, $argument)
    {
        collect($this->queryAttributes)->each(fn($attribute) => (
            $query->orWhere(fn($query) => (
                $this->matchAttribute($query, $attribute, $argument)
            ))
        ));
    }

    private function matchAttribute($query, $attribute, $argument)
    {
        $isNested = $this->isNested($attribute);

        $query->when($isNested, function ($query) use ($attribute, $argument) {
            $attributes = collect(explode('.', $attribute));

            $query->whereHas($attributes->shift(), fn($query) => (
                $this->matchAttribute($query, $attributes->implode('.'), $argument)
            ));
        })->when(! $isNested, fn($query) => (
            $query->where(
                $attribute, config('enso.select.comparisonOperator'), '%'.$argument.'%'
            )
        ));
    }

    private function order()
    {
        $attribute = collect($this->queryAttributes)->first();

        if (! $this->isNested($attribute)) {
            $this->query->orderBy($attribute);
        }

        return $this;
    }

    private function limit()
    {
        $limit = $this->request->get('paginate')
            ?? self::Limit - count($this->value);

        $this->query->limit($limit);

        return $this;
    }

    private function get()
    {
        return $this->query->get()
            ->merge($this->selected);
    }

    private function isNested($attribute)
    {
        return Str::contains($attribute, '.');
    }
}