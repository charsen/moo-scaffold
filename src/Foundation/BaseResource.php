<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2025-07-29 16:40
 * @Description: Base Resource
 */

namespace Mooeen\Scaffold\Foundation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

class BaseResource extends JsonResource
{
    use BaseResourceTrait;

    protected mixed $customFields = [];

    protected bool $hide = true;

    protected bool $trashed = false;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
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
     * @param mixed $resource
     */
    public static function collection($resource): BaseResourceCollection
    {
        return tap(static::newCollection($resource), function ($collection) {
            if (property_exists(static::class, 'preserveKeys')) {
                $collection->preserveKeys = (new static([]))->preserveKeys === true;
            }
        });
    }

    /**
     * Create a new resource collection instance.
     *
     * @param mixed $resource
     */
    protected static function newCollection($resource): AnonymousResourceCollection
    {
        return new AnonymousResourceCollection($resource, static::class);
    }

    /**
     * Remove the filtered keys.
     */
    protected function filterFields($resource): array
    {
        return (! $this->hide)
            ? $resource->only($this->customFields)->toArray()
            : $resource->forget($this->customFields)->toArray(); // except()
    }

    /**
     * Conditionally load the given attribute if the route name contains the specified string.
     */
    protected function whenRoute($request, $str, $value)
    {
        if (str_contains($request->route()->getName(), $str)) {
            return value($value);
        }

        return new MissingValue;
    }

    protected function whenDate($field, $format = 'Y-m-d H:i')
    {
        if (isset($this->resource->getAttributes()[$field])) {
            // if (array_key_exists($field, $this->resource->getAttributes())) {
            return $this->{$field}->format($format);
        }

        return new MissingValue;
    }

    protected function whenSelf($field)
    {
        if (isset($this->resource->getAttributes()[$field])) {
            // if (array_key_exists($field, $this->resource->getAttributes())) {
            return $this->{$field};
        }

        return new MissingValue;
    }

    /**
     * Conditionally load the given attribute if set trashed model.
     */
    protected function whenTrashed($value)
    {
        if ($this->trashed) {
            return $value;
        }

        return new MissingValue;
    }
}
