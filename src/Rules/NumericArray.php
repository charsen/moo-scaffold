<?php declare(strict_types=1);
/*
 * @Author: Charsen
 * @Date: 2026-05-30 08:16
 * @LastEditors: Charsen
 * @LastEditTime: 2026-05-30 16:15
 * @Description:
 */

namespace Mooeen\Scaffold\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * 数字 / 数字数组验证 — 兼容 cascader 级联选择返回单数。
 *
 * - 数字：is_numeric（不用 is_int，bigint 会丢精度）
 * - 数组：递归验证每个元素
 *
 * 翻译 key：`validation.custom.numeric_array`。
 * scaffold 生成 Request 时给带 `_ids` 的字段注入此规则（CreateControllerGenerator 固定引用本类）。
 */
class NumericArray implements ValidationRule
{
    /**
     * @param Closure(string): PotentiallyTranslatedString $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $check = static fn ($val) => is_numeric($val);

        if (is_array($value)) {
            foreach ($value as $v) {
                if (is_array($v)) {
                    $this->validate($attribute, $v, $fail);
                } elseif (! $check($v)) {
                    $fail('validation.custom.numeric_array')->translate();
                }
            }
        } elseif (! $check($value)) {
            $fail('validation.custom.numeric_array')->translate();
        }
    }
}
