<?php

namespace App\Components\Tracker\Resources;

use App\Components\Tracker\Handlers\IssueCreator;
use App\Components\Tracker\Tracker;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\DelegatesToResource;
use Throwable;

abstract class BaseModelResource extends BaseResource
{
    use DelegatesToResource;

    protected $resource;

    /**
     * @param Model $model
     */
    public function __construct(Model $model)
    {
        $this->resource = $model;
    }

    public function getResource(): Model
    {
        return $this->resource;
    }

    /**
     * @param Tracker|null $tracker
     *
     * @return array
     * @throws Throwable
     * @throws BindingResolutionException
     */
    public function post(Tracker $tracker = null): array
    {
        if (is_null($tracker)) {
            $tracker = app(Tracker::class);
        }

        // создаем инцидент при ЛЮБОМ исключении
        // в идеале тут только TrackerRequestException|TrackerResponseException
        try {
            return parent::post($tracker);
        } catch (Throwable $e) {
            app()->make(IssueCreator::class)
                ->setEntity($this->resource)
                ->setMethod(static::getMethod())
                ->create();

            throw $e;
        }
    }
}
