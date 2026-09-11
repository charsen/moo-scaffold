<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

/**
 * 字段名派生规则（2026-09-11 收口）。
 *
 * 背景：「隐藏字段」判定原先在 Model / Resource / Controller 里逐字复制了 5 份，
 * 「snake_case → StudlyCase」在 Model / Controller 里复制了 4 份。集中到一处，
 * 避免以后改一处、漏四处。本类只做**字符串判定与派生**，不读配置、不碰 I/O。
 *
 * ⚠️ 刻意**不**合并同族里另外两个变体 —— 它们与 isHidden() 意图不同，合并会改生成产物：
 *   - faker 假值链（`CreateModelGenerator` 造 seeder 规则、`ApiController` 填调试占位值）用的是
 *     `$name === 'password' || str_contains($name, '_password')`。这是那条链自身与
 *     `_address` / `_mobile` / `_email` 平行的局部写法，且刻意比 isHidden() 窄：描述"该给什么样的
 *     假值"，不该让 `password_hash` / `password_token` 落进 `fake()->password` 分支。
 *   - `Foundation\FormRequest` 的控件类型判定只认 `str_contains($name, 'password')`，
 *     因为 `_` 前缀字段本就到不了那一步，加上前缀判断会凭空给 `_` 字段加 password 控件。
 */
final class FieldName
{
    /**
     * 「隐藏字段」：`_` 前缀（内部字段）或名字含 `password`。
     *
     * 用于 Model `$hidden` 数组、Resource 字段输出、Controller 列表/详情查询字段。
     */
    public static function isHidden(string $name): bool
    {
        return str_starts_with($name, '_') || str_contains($name, 'password');
    }

    /**
     * snake_case → StudlyCase：Enum 类名、模型访问器方法名。
     *
     * 刻意保留原表达式而不换成 `Str::studly()`：`Str::studly()` 额外把 `-` 也当分隔符，
     * 对本仓历史产物不是字节等价的替换（schema 校验虽只放 snake_case，但生成器不该靠上游
     * 校验来保证输出稳定）。收口的目的是去重复，不是换实现。
     */
    public static function studly(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
    }
}
