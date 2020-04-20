<?php

namespace Charsen\Scaffold\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class FormWidgetCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * - required       : 是否必填，默认为 true
     * - label          : label, 默认从多语言 validation.attributes 中取值
     * - type           : 建议的控件类型，默认为 input, 可选 {select, multi select, radio, checkbox, cascader}
     * - status         : 控件状态，默认为 normal, 可选 { normal, readonly, disabled, hidden }
     * - default        : 默认值，默认为空
     * - placeholder    : 默认为空
     * - tip            : 控件附加的提示文本内容
     * - append_attr    : 其它附加属性
     * - options        : 数据，可能是 select options ，radio, checkbox ...
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $this->collection = $this->collection->map(function ($item, $key) {
            $item['required']      = $item['required'] ?? TRUE;
            $item['label']         = $item['label'] ?? __('validation.attributes.' . $key);
            $item['type']          = $item['type'] ?? 'input';
            $item['status']        = $item['status'] ?? 'normal';

            // 包含大写 ID 时，做替换处理
            if (stristr($item['label'], 'ID')) {
                $item['label'] = str_replace('ID', '', $item['label']);
            }

            if ($item['type'] == 'input')
            {
                $item['placeholder'] = $item['placeholder'] ?? '请输入' . $item['label'];
            }
            elseif (in_array($item['type'], ['cascader', 'date', 'select']))
            {
                $item['placeholder'] = $item['placeholder'] ?? '请选择' . $item['label'];
            }

            // 在创建时返回默认值，让 vue 组件获取到的是一个 vm 对象，确保 element 组件绑定 v-model 成功!
            // 不能去掉，在 多选时 ，选择完，输入其它文本框时，原选择的内容会被清空
            if ($item['type'] == 'cascader' && ! isset($item['default']))
            {
                $item['default'] = [];
            }

            // 对 级联选择器 的属性设置
            if ($item['type'] == 'cascader')
            {
                $item['strictly'] = $item['strictly'] ?? FALSE;
                $item['multiple'] = $item['multiple'] ?? FALSE;
                $item['array']    = $item['array'] ?? FALSE;
            }

            // model dictionary 处理
            if (isset($item['options']) && isset($item['dictionary']) && $item['dictionary'])
            {
                unset($item['dictionary']);
                $item['options'] = collect($item['options'])->map(function ($item, $key) {
                    return [
                        'label' => __('model.' . $item),
                        'value' => $key
                    ];
                })->toArray();
                //$item['default'] = $item['options']->first()['value'];
                //$item['options'] = $item['options']->toArray();
            }

            if ( ! empty($item['filter']))
            {
                if ($item['filter'][0] == 'lable-value')
                {
                    $item['options'] = $this->toLableValue($item['options'], $item['filter'][1], $item['filter'][2], $item['filter'][3] ?? '');
                }

                unset($item['filter']);
            }

            return $item;
        });

        return array_values($this->collection->all());
    }

    /**
     * 转换成 label , value 数组
     *
     * @param array $data
     * @param string $key_field
     * @param string $label_field
     * @param string $count_field
     * @return array
     */
    public function toLableValue($data, $key_field, $label_field, $count_field = '')
    {
        $res = [];
        foreach ($data as $one) {
            $tmp = ['value' => $one[$key_field], 'label' => $one[$label_field]];

            if ($count_field != '')
            {
                $tmp['count'] = $one[$count_field];
            }

            if (!empty($one['children']))
            {
                $tmp['children'] = $this->toLableValue($one['children'], $key_field, $label_field, $count_field);
            }

            $res[] = $tmp;
        }

        return $res;
    }
}
