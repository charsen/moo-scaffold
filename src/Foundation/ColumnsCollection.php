<?php

namespace Mooeen\Scaffold\Foundation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ColumnsCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * - label          : label, 默认从多语言 db. 中取值
     * - field          : 对应字段名称
     * - type           : 字段类型，默认为 txt, 可选 { href, image, slot }
     *                    = href 时，增加 {href: '' , target: ''}
     *                    = image 时, 增加 { }
     *                    = slot 时, v-slot:shower_{field}
     *
     * @param  Request  $request
     */
    public function toArray($request): array
    {
        $columns = $this->collection->map(function ($item, $index) {
            $one = ['show' => true, 'type' => 'string', 'key' => null];

            // 覆盖
            if (is_array($item)) {
                $field = $index;
                foreach ($item as $key => $value) {
                    $one[$key] = $value;
                }
                $one['key'] = $item['key'] ?? $field;
            } else {
                $field = $item;
            }

            // 对以 _txt 结尾的字段（对应 model 中的字典字段）
            $label = preg_match('/[\w]\_txt/', $field) ? substr($field, 0, (strlen($field) - 4)) : $field;

            // label 预设处理
            $one['label'] = isset($one['label']) ? __('db.' . $one['label']) : __('db.' . $label);

            // 包含大写 ID 时，做替换处理
            if (str_contains($one['label'], 'ID')) {
                $one['label'] = str_replace('ID', '', $one['label']);
            }

            $one['field'] = $field;

            return $one;
        });

        return array_values($columns->all());
    }
}
