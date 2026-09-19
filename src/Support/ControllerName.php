<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

/**
 * controller 类名归一的**单一真源**（2026-09-19 自 `Utility` 外迁）。
 *
 * 历史上各端混用 `str_replace`（删全部出现）/ `Str::replaceLast`（删最后一次）两套语义，对病态名字
 * 结果发散；收敛后只保留两条互逆的纯函数，且**都只作用于末尾**：
 *   - `UserController` → `User`，而中间含 `Controller` 的名字不动（`ControllerManager` 原样返回）；
 *   - `FooControllerController` → `FooController`（只剥最末一层）。
 *
 * 短名 / FQCN 都可传（后缀在末尾，不受前缀影响）。本类不读配置、不碰 I/O ⇒ 与 `Paths` / `FieldName`
 * 同形：`final` + 全静态。
 *
 * 这两个规则原先挂在 `Utility` 上（一个持有 `Filesystem` 的有状态服务），是全仓最后一个「状态与服务
 * 混装」的类；外迁后 `Utility::stripControllerSuffix()` / `ensureControllerSuffix()` 只留 `@deprecated`
 * 转发，供尚未迁移的宿主过渡，新代码一律直调本类。
 */
final class ControllerName
{
    /**
     * 去掉末尾的 `Controller` 后缀。
     */
    public static function strip(string $class): string
    {
        return str_ends_with($class, 'Controller')
            ? substr($class, 0, -10) // strlen('Controller') === 10
            : $class;
    }

    /**
     * 保证以 `Controller` 结尾（缺则补，已有不重复，空串原样返回）。
     * 跟 {@see strip()} 互为逆操作。
     */
    public static function ensure(string $class): string
    {
        return $class === '' || str_ends_with($class, 'Controller')
            ? $class
            : $class . 'Controller';
    }
}
