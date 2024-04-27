<?php

namespace Mooeen\Scaffold\Foundation;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * resource collection.
 */
class BaseResourceCollection extends ResourceCollection
{
    protected mixed $withoutFields = [];

    /**
     * 将资源集合转换为数组
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return $this->processCollection($request);
    }

    /**
     * Set the keys that are supposed to be filtered out.
     *
     *
     * @return $this
     */
    public function hide(mixed $fields): self
    {
        $this->withoutFields = $fields;

        return $this;
    }

    /**
     *  将隐藏字段通过 BaseResource 处理集合
     *
     * @return array<string, mixed>
     */
    protected function processCollection($request): array
    {
        return $this->collection->map(function (BaseResource $resource) use ($request) {
            return $resource->hide($this->withoutFields)->toArray($request);
        })->all();
    }

    /**
     * 自定义分页信息
     */
    //    public function paginationInformation($request, $paginated, $default): array
    //    {
    //        return [
    //            'meta' => [
    //                'page'       => $paginated['current_page'],
    //                'per_page'   => $paginated['per_page'],
    //                'total'      => $paginated['total'],
    //                'total_page' => $paginated['last_page'],
    //            ],
    //        ];
    //    }
}
