<?php

namespace Mooeen\Scaffold\Foundation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

class BaseResource extends JsonResource
{
    protected mixed $withoutFields = [];

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        if (is_null($this->resource)) {
            return [];
        }

        $resource = is_array($this->resource) || ($this->resource instanceof Model) ? collect($this->resource) : $this->resource;

        return $this->filterFields($resource);
    }

    /**
     * Create a new resource collection.
     *
     * @param  mixed  $resource
     */
    public static function collection($resource): BaseResourceCollection
    {
        return tap(new BaseResourceCollection($resource), function ($collection) {
            $collection->collects = __CLASS__;
        });
    }

    /**
     * @url https://learnku.com/laravel/t/7625/dynamically-hide-the-api-field-in-laravel
     * Set the keys that are supposed to be filtered out.
     */
    public function hide(string|array $fields): self
    {
        if (! is_array($fields)) {
            $fields = str_replace([', ', ' ,', ' , '], ',', $fields);
            $fields = explode(',', $fields);
        }

        $this->withoutFields = $fields;

        return $this;
    }

    /**
     * Remove the filtered keys.
     */
    protected function filterFields($resource): array
    {
        return $resource->forget($this->withoutFields)->toArray();
    }
}
