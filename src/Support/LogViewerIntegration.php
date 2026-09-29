<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Mooeen\Scaffold\Auth\ScaffoldAuth;
use Mooeen\Scaffold\Http\Middleware\ScaffoldLogViewer;
use Mooeen\Scaffold\Http\Middleware\SecurityHeaders;
use Opcodes\LogViewer\Http\Middleware\AuthorizeLogViewer;

/** Scaffold 管入口与访问策略，Log Viewer 管日志读取与展示。 */
final class LogViewerIntegration
{
    public static function enabled(): bool
    {
        return (bool) config('scaffold.route.enabled', true)
            && (bool) config('log-viewer.enabled', true)
            && app(ScaffoldAuth::class)->isEnabled();
    }

    public function configure(): void
    {
        $prefix = trim((string) config('scaffold.route.prefix', 'scaffold'), '/');
        $path   = ($prefix === '' ? '' : $prefix . '/') . 'logs';
        // 与 Scaffold 登录保持相同的 Cookie 链；宿主关闭 web 中间件时，
        // 不能单独为日志再加 EncryptCookies，否则既有登录 Cookie 无法解析。
        $middleware = array_merge((array) config('scaffold.route.middleware', []), [
            SecurityHeaders::class,
            ScaffoldLogViewer::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            AuthorizeLogViewer::class,
        ]);

        config([
            'log-viewer.enabled'              => self::enabled(),
            'log-viewer.route_path'           => $path,
            'log-viewer.middleware'           => $middleware,
            'log-viewer.api_middleware'       => $middleware,
            'log-viewer.back_to_system_url'   => '/' . $prefix,
            'log-viewer.back_to_system_label' => '返回 Scaffold',
        ]);

        // 隐藏删除控件；日志中间件同时拒绝全部删除接口。
        Gate::define('deleteLogFile', fn () => false);
        Gate::define('deleteLogFolder', fn () => false);
    }

    public function configureMiddlewarePriority(): void
    {
        // web 展开后 CSRF 默认先于追加的认证中间件。只给日志认证指定
        // Cookie 解密之后的优先级，保证匿名写 API 返回 401，而非 419。
        // Router 的公开列表兼容 Laravel 10；后置到 booted 避免依赖 Provider
        // 配置 Kernel 时重新同步覆盖该顺序。
        $router   = app('router');
        $priority = array_values(array_diff($router->middlewarePriority, [ScaffoldLogViewer::class]));
        $index    = array_search(EncryptCookies::class, $priority, true);
        array_splice($priority, $index === false ? 0 : $index + 1, 0, [ScaffoldLogViewer::class]);
        $router->middlewarePriority = $priority;
    }
}
