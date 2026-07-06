<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * 日期 / 日期数组验证 — 用于日期范围筛选（date-range，如 [起, 止]）等场景。
 *
 * - 单值：可被 strtotime 解析的日期时间字符串
 * - 数组：每个元素都是合法日期时间
 *
 * 翻译 key：`validation.custom.datetime_array`。
 * 取代旧版字符串规则 `datetime_array`：用单键 Rule 对象，避免 `field` + `field.*` 拆写时
 * form widget 把 `.*` 子规则（含 'date'）误判成 date-picker、覆盖掉基字段的 date-range 控件。
 */
class DatetimeArray implements ValidationRule
{
    /**
     * @param Closure(string): PotentiallyTranslatedString $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $check = static function ($val): bool {
            if (! is_string($val) && ! is_numeric($val)) {
                return false;
            }

            return strtotime((string) $val) !== false;
        };

        if (is_array($value)) {
            foreach ($value as $v) {
                if (! $check($v)) {
                    $fail('validation.custom.datetime_array')->translate();
                }
            }
        } elseif (! $check($value)) {
            $fail('validation.custom.datetime_array')->translate();
        }
    }
}
