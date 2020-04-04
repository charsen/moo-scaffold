<?php

namespace Charsen\Scaffold\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BaseResource extends JsonResource
{

    protected $withoutFields = [];

    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return parent::toArray($request);
    }

    /**
     * @url https://learnku.com/laravel/t/7625/dynamically-hide-the-api-field-in-laravel
     *
     * Set the keys that are supposed to be filtered out.
     *  设置需要隐藏过滤掉的键
     *
     * @param array $fields
     * @return $this
     */
    public function hide(array $fields)
    {
        $this->withoutFields = $fields;

        return $this;
    }

    /**
     * Remove the filtered keys.
     * 删除隐藏的键
     *
     * @param $array
     * @return array
     */
    protected function filterFields($array)
    {
        return collect($array)->forget($this->withoutFields)->toArray();
    }
}
