<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2026-06-25 14:09
 * @Description: Table Columns Collection
 */

namespace Mooeen\Scaffold\Foundation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class TableColumnsCollection extends ResourceCollection
{
    /**
     * 构建列表表头（自动追加 updated_at/deleted_at + options 列）
     */
    public static function makeColumns(array $columns, string $action = 'index', int $optionsWidth = 110): self
    {
        if ($action === 'index') {
            $append = ['updated_at' => ['width' => 100], 'options' => ['width' => $optionsWidth]];
        } else {
            $append = ['deleted_at' => ['width' => 180], 'options' => ['width' => $optionsWidth]];
        }

        return static::make([...$columns, ...$append]);
    }

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
     * @param Request $request
     */
    public function toArray($request): array
    {
        $columns = $this->collection->map(function ($item, $index) {
            $one = ['type' => 'txt', 'align' => 'left', 'key' => null, 'width' => null, 'minWidth' => null, 'fixed' => false, 'keep_id' => false];

            // 覆盖
            if (is_array($item)) {
                $field = $index;
                foreach ($item as $key => $value) {
                    // width/minWidth 原样透传：int 输出裸数字（前端自行归一化），字符串（如 'auto'）保持不变
                    $one[$key] = $value;
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

            // 不保留 ID 字符
            if (! $one['keep_id']) {
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
                $one['fixed'] = 'end';
            }

            return $one;
        });

        return array_values($columns->all());
    }
}
