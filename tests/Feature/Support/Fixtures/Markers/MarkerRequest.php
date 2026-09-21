<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Tests\Feature\Support\Fixtures\Markers;

use Mooeen\Scaffold\Foundation\FormRequest;

/**
 * `FormContractMarkers::waivedMarkers()` 的解析夹具。
 *
 * 刻意放进三种形态：正常标记 / 字段名含 `.` 与 `*` / 无冒号因而不构成标记的一行。
 */
class MarkerRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],

            // @moo-waived legacy_field: 早期精简：nullable 字段暂不实现
            // 'legacy_field' => ['nullable', 'string'],

            // @moo-waived form_config.*: 通配符字段名也算
            // 'form_config.*' => [],

            // 下面这行没有冒号 ⇒ 不构成标记
            // @moo-waived

            // @moo-waived legacy_field_2: 第二个
            // 'legacy_field_2' => [],
        ];
    }
}
