<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Foundation;

/**
 * 运行时表单 schema 的 Request 等价物（2026-09-11 新增）。
 *
 * 场景：字段来自数据库（自定义字段小应用）而**不是** scaffold 生成的 FormRequest 时，仍然要让
 * `FormWidgetCollection::makeForm()` / `makeSearch()`、`formLayout` 校验与 `/scaffold/api/request`
 * 的表单预览原样可用 —— 即把「动态 schema」当成同一个表单契约的**另一个生产者**，而不是另起一套链路。
 *
 * 用法（消费方的字段类型登记表先把字段编译成 Laravel 规则，再交给本类）：
 *
 * ```php
 * $request = RuntimeFormRequest::fromSchema($rules, $options, $layout);
 * $widgets = FormWidgetCollection::makeForm($request, base: $overrides);
 * ```
 *
 * 边界：
 * - 只承载「schema → rules / options / layout」，**不做字段类型编译**（那是消费方登记表的职责）；
 * - `rules()` 的输出必须与生成式 FormRequest 同形状；控件类型反推仍走 `Support\FormWidgetTypes::infer()`，
 *   前端规则仍走 `Support\FormFrontendRules::fromRules()`，需要显式类型/选项时用 `base` / `override` 传；
 * - 不含任何业务概念（部门、责任段、统计口径、记录写入守卫都由消费方实现）。
 */
class RuntimeFormRequest extends FormRequest
{
    /** @var array<string, array<int|string, mixed>> */
    private array $runtimeRules = [];

    /** @var array<string, array<int|string, mixed>> */
    private array $runtimeOptions = [];

    /** @var array<int, array> */
    private array $runtimeLayout = [];

    /**
     * 从运行时 schema 造一个 Request 实例。
     *
     * @param array<string, array<int|string, mixed>> $rules   字段 code => Laravel 规则数组（与生成式同形状）
     * @param array<string, array<int|string, mixed>> $options 字段 code => [值 => 标签]，供 radio/select 与 with_default
     * @param array<int, array>                       $layout  与 `FormRequest::formLayout()` 同形的布局（可选）
     */
    public static function fromSchema(array $rules, array $options = [], array $layout = []): self
    {
        $request = static::create('/', 'GET');

        $request->runtimeRules   = $rules;
        $request->runtimeOptions = $options;
        $request->runtimeLayout  = $layout;

        return $request;
    }

    public function rules(): array
    {
        return $this->runtimeRules;
    }

    public function options(string $field): array
    {
        return $this->runtimeOptions[$field] ?? [];
    }

    public function formLayout(): array
    {
        return $this->runtimeLayout;
    }
}
