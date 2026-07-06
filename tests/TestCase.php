<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Tests;

use Mooeen\Monitor\MonitorProvider;
use Mooeen\Scaffold\ScaffoldProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * plan-33 scaffold 包独立测试基类。
 *
 * Testbench 启最小 Laravel app(用 testbench-core/laravel 作为 base_path,
 * 自带 minimal config — 避开宿主 engine/config 里 third-party 包依赖冲突)。
 *
 * scaffold path 用 absolute(指向同 repo 的 engine,开发回归用)。Utility::getDatabasePath
 * 已支持 abs path(plan-33),不再被 base_path() prefix。
 *
 * 开源使用者 fork 后,要么在 phpunit.xml 改 path,要么自备 fixture yaml。
 */
abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        // MonitorProvider:监控链路来自 moo-monitor-laravel(宿主里由 composer
        // auto-discovery 注册;testbench 需显式列出),scaffold 的 cloud 页/首页面板依赖它。
        return [MonitorProvider::class, ScaffoldProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // testbench 默认不带 APP_KEY → Crypt(ScaffoldAuth cookie 加解密)抛 MissingAppKeyException。
        // 给一个固定测试 key(AES-256-CBC 需 32 byte),让加密相关测试可跑。
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));

        // 默认:需 schema 的测试各自 FixtureSchema::activate() 切到 bundled fixture
        // (tests/Feature/Designer/fixtures),套件零 env 即可全绿,无需全局配置。
        // 可选:显式设 PEST_HOST_SCAFFOLD_PATH 指向真实下游 engine scaffold 目录,针对真实 schema 跑回归。
        // 用法:PEST_HOST_SCAFFOLD_PATH=/path/to/your/laravel-root/scaffold composer test
        $hostScaffold = getenv('PEST_HOST_SCAFFOLD_PATH');
        if (is_string($hostScaffold) && $hostScaffold !== '' && is_dir($hostScaffold)) {
            $app['config']->set('scaffold.database.schema', $hostScaffold . '/database/');
            $app['config']->set('scaffold.api.schema', $hostScaffold . '/api/');
            // plan-36 后 baseline 走快照,不再依赖 database/migrations,但其他生成器仍读 — 保留
            $app->useDatabasePath(dirname($hostScaffold) . '/database');
        }
    }
}
