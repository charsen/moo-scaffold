<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

/**
 * 「scaffold 是否处于只读」的唯一判定口径。
 *
 * 两个独立来源，**任一为真即只读**：
 *   1. `APP_ENV=production` —— 生产环境一律只读。注意这不只是「隐藏按钮」：后端的写路由与
 *      文件 / 数据库 writer 都必须拒绝执行，所以判定要在「决策点」而不是「渲染点」取。
 *   2. `scaffold.config_ui.readonly = true`（`SCAFFOLD_CONFIG_READONLY`）—— 强制只读开关。
 *
 * **为什么收口**：这套判定原先在 Support 与 Http 两侧被抄了多份（其中
 * `DocsRepository::isReadonly()` 一份是全仓零调用的死代码），而且写法还不一致 ——
 * 有的带 `function_exists('app')` 守卫、有的不带；有的读注入的 `Repository`、有的读全局 `config()`。
 * 同一口径有两个答案，就会「改一处必漏另一处」；本仓的公开契约（生产只读）经不起这种漏。
 *
 * **为什么是静态方法而不是注入的服务**：判定的两个输入（`app()->environment()` 与 `config()`）
 * 都是框架全局。做成可注入服务只会让 Support / Controller / Middleware 三类调用方全部改构造，
 * 换不来任何可测性 —— 与 `Support\OperatorId` 同属「无状态的判定口径」形态。
 *
 * 锚点：`tests/Feature/Support/ReadonlyModeTest.php` 扫 `src/`，禁止 `scaffold.config_ui.readonly`
 * 与 `environment('production')` 两个字面在本类之外出现。
 */
final class ReadonlyMode
{
    /**
     * 生产环境（`APP_ENV=production`）。
     *
     * `function_exists('app')` 守卫是为了「没有 Laravel 容器」的上下文（纯单元测试 / 独立脚本）——
     * 那些场景下既不是生产、也没有配置，返回 false 即可。
     */
    public static function productionActive(): bool
    {
        return function_exists('app') && app()->environment('production');
    }

    /**
     * 强制只读开关（`scaffold.config_ui.readonly` / `SCAFFOLD_CONFIG_READONLY`）。
     */
    public static function configLocked(): bool
    {
        return (bool) config('scaffold.config_ui.readonly', false);
    }

    /**
     * 是否只读：生产 或 强制只读。
     */
    public static function active(): bool
    {
        return self::productionActive() || self::configLocked();
    }
}
