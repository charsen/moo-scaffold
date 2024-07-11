<?php

namespace Mooeen\Scaffold\Foundation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Str;

class FormWidgetCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     * - required       : 是否必填，默认为 true
     * - field_name     : 字段名
     * - label          : label, 默认从多语言 validation.attributes 中取值
     * - type           : 建议的控件类型，默认为 input, 可选 {select, multi select, radio, checkbox, cascader, textarea}
     * - hidden         : 是否隐藏
     * - disabled       : 是否禁用
     * - default        : 默认值，默认为空
     * - placeholder    : 默认为空
     * - tip            : 控件附加的提示文本内容
     * - append         : 其它附加属性
     * - options        : 数据，可能是 select options ，radio, checkbox ...
     */
    public function toArray(Request $request): array
    {
        $this->collection = $this->collection->map(function ($item, $key) {
            $item['required'] = $item['required'] ?? true;
            $item['keep_id']  = $item['keep_id']  ?? false;
            $item['label']    = $item['label']    ?? __('validation.attributes.' . $key);
            $item['type']     = $item['type']     ?? 'input';

            // 不保留 ID 字符
            if ( ! $item['keep_id']) {
                $item['label'] = trim(str_replace('ID', '', $item['label']));

                if (str_contains($item['label'], ' Ids')) {
                    $item['label'] = trim(str_replace(' Ids', '', $item['label']));
                    $item['label'] = Str::plural($item['label']);   // 单数变复数
                }
            }

            $item['placeholder'] = $item['placeholder'] ?? __('validation.attributes.please_enter') . $item['label'];
            if (in_array($item['type'], ['cascader', 'date', 'select', 'radio', 'checkbox'])) {
                $item['placeholder'] = $item['placeholder'] ?? __('validation.attributes.please_select') . $item['label'];
            }

            // 对 级联选择器 的属性设置
            if ($item['type'] === 'cascader') {
                // 在创建时返回默认值，让 vue 组件获取到的是一个 vm 对象，确保 element 组件绑定 v-model 成功!
                // 前端已解决!!!
                //$item['default']  = $item['default'] ?? [null];

                // 不能去掉，在 多选时 ，选择完，输入其它文本框时，原选择的内容会被清空
                $item['multiple'] = $item['multiple'] ?? false;

                // 是否严格的遵守父子节点不互相关联
                $item['strictly'] = $item['strictly'] ?? false;
                // 前端返回数据时，是否为数组，= false 时为最后一项的值
                $item['array'] = $item['array'] ?? false;
            } // 对 编辑器 的属性设置
            elseif ($item['type'] === 'editor') {
                $item['imageUploadUrl'] = trim(action(config('image.editor'), [], false), '/');
                $item['default']        = $item['default'] ?? '';
            } elseif ($item['type'] === 'cropper-image' or $item['type'] === 'upload-image') {
                $item['tip'] = $item['tip'] ?? '图片最小尺寸为： ' . $item['width'] . 'px * ' . $item['height'] . 'px';
            } elseif ($item['type'] === 'select') {
                $item['multiple'] = $item['multiple'] ?? false;
                $item['limit']    = $item['limit']    ?? 0;
            }

            // model dictionary 处理
            if (isset($item['options'], $item['dictionary']) && $item['dictionary']) {
                unset($item['dictionary']);
                $item['options'] = collect($item['options'])->map(function ($item, $key) {
                    return [
                        'label' => $item,
                        'value' => $key,
                    ];
                });

                $item['options'] = $item['options']->toArray();
            }

            // 为前端做过滤处理
            if (! empty($item['filter'])) {
                if ($item['filter'][0] === 'label-value' or $item['filter'][0] === 'labelValue') {
                    $item['options'] = toLabelValue($item['options'], $item['filter'][1], $item['filter'][2], $item['filter'][3] ?? '', $item['filter'][4] ?? []);
                }

                unset($item['filter']);
            }

            return $item;
        });

        return array_values($this->collection->all());
    }
}
