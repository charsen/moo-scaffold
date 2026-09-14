<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

/**
 * 验证规则 → 前端校验规则的单一来源（2026-09-11 收口）。
 *
 * 原实现是 `Foundation\FormRequest::getFrontendRules()`（private），只能被生成式表单用到；
 * 动态字段的消费方（运行时 schema，例如自定义字段小应用）同样需要产出**同形状**的
 * `[{rule, msg}]`，否则前端要养两套校验提示，因此提到公共层。
 *
 * 现状语义（从 FormRequest 原样搬来，输出逐字节保持）：
 * - 跳过非字符串规则 —— `Rule::in()` / `Rule::unique()`（经 `getUnique()` 构造）等对象规则不下发；
 * - 跳过字符串里含 `$this->get`（延迟构造）或 `exists:`（主键存在性）的规则；
 * - 规则名取冒号前的部分（`max:10` → `validation.max`），`nullable` 的 `msg` 固定为空串；
 * - `msg` 里的 `:attribute` 替换成 `validation.attributes.<字段>` 的译文；
 * - 译文返回数组时取 `['string']` 键（Laravel 对 `:attribute` 的复数/单数形态）；
 * - 通配子项（`a.*`）在父字段 `a` 也存在时**整条跳过**：子项规则只约束数组元素，
 *   不能套到聚合控件本身；仅通配定义（无父字段）时用去通配的字段名承载。
 */
final class FormFrontendRules
{
    /**
     * @param array<string, array<int|string, mixed>> $allRules 形如 `FormRequest::rules()` 的返回
     *
     * @return array<string, list<array{rule: string, msg: string}>>
     */
    public static function fromRules(array $allRules, array $labels = []): array
    {
        if (empty($allRules)) {
            return [];
        }

        $frontendRules = [];

        foreach ($allRules as $fieldName => $rules) {
            // 对 .* 规则的特殊处理
            $isWildcard = str_contains((string) $fieldName, '.*');
            $field      = $isWildcard ? str_replace('.*', '', (string) $fieldName) : (string) $fieldName;

            // 父字段存在时，子项规则只约束数组元素，不能套到聚合控件本身。
            if ($isWildcard && array_key_exists($field, $allRules)) {
                continue;
            }

            foreach ($rules as $rule) {
                if (! is_string($rule) || str_contains($rule, '$this->get') || str_contains($rule, 'exists:')) {
                    continue;
                }

                $key     = preg_replace('/\:.+/i', '', $rule);
                $message = $key === 'nullable' ? '' : __('validation.' . $key);
                $message = str_replace(':attribute', $labels[$field] ?? __('validation.attributes.' . $field), $message);

                $frontendRules[$field][] = ['rule' => $rule, 'msg' => is_array($message) ? $message['string'] : $message];
            }
        }

        return $frontendRules;
    }
}
