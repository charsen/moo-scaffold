<?php

namespace Mooeen\Scaffold\Foundation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class TableColumnsCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     * - label          : label, 默认从多语言 db. 中取值
     * - field          : 对应字段名称
     * - type           : 字段类型，默认为 txt, 可选 { href, image, slot }
     *                    = href 时，增加 {herf: '' , target: ''}
     *                    = image 时, 增加 {height: '60px', width: 'auto'}
     *                    = slot 时, v-slot:column_{field}
     * - fixed          : true, left, right
     * - align          : 默认值，left, 可选 {left, center, right}
     * - width          : 默认值 null,
     * - min-width      : 默认值 100,
     * - children       : { }
     *
     * @param  Request  $request
     */
    public function toArray($request): array
    {
        $columns = $this->collection->map(function ($item, $index) {
            $one = ['type' => 'txt', 'align' => 'left', 'key' => null, 'width' => null, 'minWidth' => null, 'fixed' => false];

            // 覆盖
            if (is_array($item)) {
                $field = $index;
                foreach ($item as $key => $value) {
                    if (in_array($key, ['width', 'minWidth']) && is_int($value)) {
                        $one[$key] = $value . 'px';
                    } else {
                        $one[$key] = $value;
                    }
                }
                //                $one['key'] = $item['key'] ?? NULL;
            } else {
                $field = $item;
            }

            // 对以 _txt 结尾的字段（对应 model 中的字典字段）
            $label = preg_match('/[\w]\_txt/', $field) ? substr($field, 0, strlen($field) - 4) : $field;

            // label 预设处理
            if (isset($one['label'])) {
                $one['label'] = str_contains(__('db.' . $one['label']), 'db.') ? $one['label'] : __('db.' . $one['label']);
            } else {
                $one['label'] = __('db.' . $label);
            }

            // 包含大写 ID 时，做替换处理
            if (str_contains($one['label'], 'ID')) {
                $one['label'] = str_replace('ID', '', $one['label']);
            }

            $one['field'] = $field;

            // 对齐处理
            if (in_array($field, ['options'])) {
                $one['align'] = 'left';
            } elseif (in_array($field, ['id'])) {
                $one['align'] = 'center';
            }

            // 操作列处理
            if ($field === 'options') {
                $one['type']  = $one['type'] === 'slot' ? 'slot' : 'options';
                $one['fixed'] = 'right';
            }

            return $one;
        });

        return array_values($columns->all());
    }
}
