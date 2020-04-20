<?php

namespace Charsen\Scaffold\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class TableColumsCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * - label          : label, 默认从多语言 db. 中取值
     * - value          : 对应字段名称
     * - type           : 字段类型，默认为 txt, 可选 { href, image, slot }
     *                    = href 时，增加 {herf: '' , target: ''}
     *                    = image 时, 增加 {height: '60px', width: 'auto'}
     *                    = slot 时
     * - fixed          : true, left, right
     * - align          : 默认值，left, 可选 {left, center, right}
     * - width          : 默认值 null,
     * - min-width      : 默认值 100,
     * - children       : { }
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $columns = $this->collection->map(function ($item, $index) {
            $one = ['type' => 'txt', 'align' => 'left', 'width' => null, 'minWidth' => null, 'fixed' => false];

            // 覆盖
            if (is_array($item))
            {
                $field = $index;
                foreach ($item as $key => $value)
                {
                    $one[$key] = $value;
                }
            }
            else
            {
                $field = $item;
            }

            // 对以 _txt 结尾的字段（对应 model 中的字典字段）
            $label        = preg_match('/[\w]\_txt/', $field) ? substr($field, 0, strlen($field) -4) : $field;
            // label 预设处理
            $one['label'] = isset($one['label']) ? __('db.' . $one['label']) : ($field == 'options' ? '' : __('db.' . $label));
            // 包含大写 ID 时，做替换处理
            if (stristr($one['label'], 'ID')) {
                $one['label']       = str_replace('ID', '', $one['label']);
            }

            $one['value'] = $field;

            // 对齐处理
            if (in_array($field, ['options']))
            {
                $one['align'] = 'right';
            }
            // elseif (in_array($field, ['id']))
            // {
            //     $one['align'] = 'center';
            // }

            // 操作列处理
            if ($field == 'options') {
                $one['type']        = 'options';
                $one['width']       = null;
                $one['minWidth']    = null;
                $one['fixed']       = 'right';
            }

            return $one;
        });

        return array_values($columns->all());
    }

}
