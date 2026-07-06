<?php declare(strict_types=1);
/*
 * @Author: Charsen
 * @Date: 2026-05-30 08:17
 * @LastEditors: Charsen
 * @LastEditTime: 2026-05-30 16:15
 * @Description:
 */

namespace Mooeen\Scaffold\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * 中国大陆手机号验证（1[3-9]xxxxxxxxx）。
 *
 * 翻译 key：`validation.custom.mobile`。
 * scaffold 生成 Request 时给 `mobile` / `*_mobile` 字段注入此规则（CreateControllerGenerator 固定引用本类）。
 */
class Mobile implements ValidationRule
{
    /**
     * @param Closure(string): PotentiallyTranslatedString $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! preg_match('/^1[3-9]\d{9}$/', (string) $value)) {
            $fail('validation.custom.mobile')->translate();
        }
    }
}
