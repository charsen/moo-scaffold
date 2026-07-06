<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2025-07-29 16:39
 * @Description: Base Resource Collection
 */

namespace Mooeen\Scaffold\Foundation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class BaseResourceCollection extends ResourceCollection
{
    use BaseResourceTrait;

    protected mixed $customFields = [];

    protected bool $hide = true;

    protected bool $trashed = false;

    /**
     * 将资源集合转换为数组
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->processCollection($request);
    }

    /**
     *  将隐藏字段通过 BaseResource 处理集合
     *
     * @return array<string, mixed>
     */
    protected function processCollection(Request $request): array
    {
        return $this->collection->map(function (BaseResource $resource) use ($request) {
            return (! $this->hide)
                ? $resource->show($this->customFields)->trashed($this->trashed)->toArray($request)
                : $resource->hide($this->customFields)->trashed($this->trashed)->toArray($request); // except()
        })->all();
    }

    /**
     * custom pagination information
     */
    public function paginationInformation($request, $paginated, $default): array
    {
        // 2026-05-24 audit P1:$default['meta'] 在非分页 collection 上可能缺失,加 ?? null 守护
        $meta = $default['meta'] ?? [];

        return [
            'meta' => [
                'page'       => $meta['current_page'] ?? null,
                'per_page'   => $meta['per_page']     ?? null,
                'total'      => $meta['total']        ?? null,
                'total_page' => $meta['last_page']    ?? null,
            ],
        ];
    }
}
