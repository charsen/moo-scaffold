<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

/**
 * 表单控件类型词表 + 规则反推（2026-09-11 收口）。
 *
 * 背景：接口调试器的 `public/javascript/pages/api-request.js` 曾内联一份
 * `KNOWN_WIDGET_TYPES`，注释写明“对齐下游 admin 前端 former/config.ts”，实际只能靠人工
 * 同步——2026-06-10 手工补过一次 `rate-picker`，且收口时两份清单已双向漂移。
 * 现改为本类单一来源，由 `api/request` 视图注入 `window.ScaffoldConfig.knownWidgetTypes`。
 *
 * 两个子集必须分开看，别混：
 * - `FORMER`：下游 admin 前端 `components/former/config.ts` 的 elComponents 注册表，
 *   即后端表单契约**可能下发**的 type（含只读展示控件 `text-amount`）。
 * - `DEBUGGER_RENDERABLE`：调试器预览 `renderControl()` 实际能画出来的 type；未命中的
 *   type 落到「未知 widget 类型」占位框——不报错、也不让整张表单预览静默消失。
 *
 * 现有差异是既成事实，不是待修漂移：
 * - `text-amount` 只在 `FORMER`：预览暂画不出，显示占位框；要画得先在 `renderControl()` 加分支。
 * - `date` / `cropper-image` 只在 `DEBUGGER_RENDERABLE`：历史 / 误用 type，下游注册表没有；
 *   留在可识别集合里，避免老 host 下发的字段让整张表单预览消失。
 *
 * 本类同时承载**规则 → 控件类型**的反推（`infer()`）：它是类型口径的方向之一，与词表同源，
 * 不再散在 `Foundation\FormRequest` 里。反向（type → 校验规则）由消费方（如动态字段小应用）
 * 按自己的字段类型登记产出，本类不预置。
 *
 * 约定：新增类型改这里，不再改 JS；反推规则也只在这里加。
 */
final class FormWidgetTypes
{
    /** 下游 admin 前端 `components/former/config.ts` 的 elComponents 注册表。 */
    public const FORMER = [
        'input', 'textarea', 'password', 'color-picker', 'rate', 'rate-picker',
        'month-day-picker', 'date-picker', 'datetime-picker', 'month-picker',
        'editor', 'select', 'cascader', 'checkbox', 'radio',
        'upload-file', 'upload-image', 'text-amount',
    ];

    /** 调试器预览 `renderControl()` 能渲染的 type。 */
    public const DEBUGGER_RENDERABLE = [
        'input', 'password', 'date', 'date-picker', 'datetime-picker',
        'month-picker', 'month-day-picker', 'color-picker', 'rate', 'rate-picker',
        'textarea', 'editor', 'radio', 'checkbox', 'select', 'cascader',
        'upload-image', 'cropper-image', 'upload-file',
    ];

    /**
     * 表单预览 shape 检测用的可识别集合（两者并集，保序去重）。
     *
     * 检测口径取并集：`FORMER` 保证新类型不会让整张表单预览静默消失，`DEBUGGER_RENDERABLE`
     * 兼容老 host 下发的历史 type。
     *
     * @return list<string>
     */
    public static function detectable(): array
    {
        return array_values(array_unique([...self::FORMER, ...self::DEBUGGER_RENDERABLE]));
    }

    /**
     * 从验证规则反推控件类型（2026-09-11 从 `FormRequest::formatFormConfig` 原样搬来，逐字节保持）。
     *
     * 现状语义：
     * - 规则里含精确字符串 `date` ⇒ `date-picker`（**优先于** password —— 原来就是 elseif 顺序）；
     * - 否则字段名含 `password` ⇒ `password`；
     * - 都不命中 ⇒ `null`，调用方**不写 `type` 键**（`input` 是 `FormWidgetCollection::toArray` 后补的）。
     *
     * 不在这里判断 `options()` 命中的 `radio`：那是「业务提供了选项集」的语义，由调用方在拿到
     * 本方法结果后**无条件覆盖**，不属于类型反推。
     *
     * @param array<int|string, mixed> $rules 单个字段的验证规则数组
     */
    public static function infer(array $rules, string $field): ?string
    {
        if (in_array('date', $rules, true)) {
            return 'date-picker';
        }

        if (str_contains($field, 'password')) {
            return 'password';
        }

        return null;
    }
}
