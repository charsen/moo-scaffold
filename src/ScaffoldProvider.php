<?php declare(strict_types=1);
/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2026-05-27 21:14
 * @Description: Scaffold Service Provider
 */

namespace Mooeen\Scaffold;

use Illuminate\Support\ServiceProvider;
use Mooeen\Scaffold\Command\AccountAddCommand;
use Mooeen\Scaffold\Command\AdderCommand;
use Mooeen\Scaffold\Command\CreateApiCommand;
use Mooeen\Scaffold\Command\CreateControllerCommand;
use Mooeen\Scaffold\Command\CreateMigrationCommand;
use Mooeen\Scaffold\Command\CreateModelCommand;
use Mooeen\Scaffold\Command\CreateResourceCommand;
use Mooeen\Scaffold\Command\CreateSchemaCommand;
use Mooeen\Scaffold\Command\CreateTestCommand;
use Mooeen\Scaffold\Command\CreateViewCommand;
use Mooeen\Scaffold\Command\DbAuditCommand;
use Mooeen\Scaffold\Command\FreeCommand;
use Mooeen\Scaffold\Command\FreshStorageCommand;
use Mooeen\Scaffold\Command\InitCommand;
use Mooeen\Scaffold\Command\ScaffoldMergeYamlCommand;
use Mooeen\Scaffold\Command\SnapshotInitCommand;
use Mooeen\Scaffold\Command\UpdateAuthorizationCommand;
use Mooeen\Scaffold\Command\UpdateMultilingualCommand;

class ScaffoldProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/config.php' => config_path('scaffold.php')], 'config');

            $this->publishes([__DIR__ . '/../public' => public_path('vendor/scaffold')], 'public');
        }

        // 运行时异常 / 慢 SQL 采集、云端推送、MCP 由 charsen/moo-monitor-laravel 提供
        // (composer 依赖自动带入,MonitorProvider 负责事件监听、reportable 钩子与调度)。
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/config.php', 'scaffold');

        // plan 19 数据库设计器:GitInspector 注入 base_path / TranslationService 注入 env 配置
        $this->app->singleton(\Mooeen\Scaffold\Designer\GitInspector::class, fn ($app) => new \Mooeen\Scaffold\Designer\GitInspector(
            cwd: $app->basePath(),
        ));

        // plan 36 designer baseline 快照存储
        $this->app->singleton(\Mooeen\Scaffold\Designer\SnapshotStore::class);

        // plan-53 扩展包自动发现(installed.json 扫 scaffold/database marker);单例缓存发现结果
        $this->app->singleton(\Mooeen\Scaffold\Support\PackageRegistry::class);

        // plan 49 migration 合并(compact)— 注入 cwd 给 git push 检测用
        $this->app->singleton(\Mooeen\Scaffold\Designer\MigrationCompacter::class, fn ($app) => new \Mooeen\Scaffold\Designer\MigrationCompacter(
            loader: $app->make(\Mooeen\Scaffold\Designer\SchemaLoader::class),
            writer: $app->make(\Mooeen\Scaffold\Designer\MigrationWriter::class),
            git: $app->make(\Mooeen\Scaffold\Designer\GitInspector::class),
            fs: $app->make(\Illuminate\Filesystem\Filesystem::class),
            cwd: $app->basePath(),
        ));

        // AI 翻译:配置从 AiSettingStore 读(scaffold/ai.yaml,入 git;GUI 在 /scaffold/config/ai 编辑)。
        // 运行时读 yaml 而非 env —— config:cache 安全,改完即时生效。
        $this->app->singleton(\Mooeen\Scaffold\Designer\TranslationService::class, function ($app) {
            $ai = $app->make(\Mooeen\Scaffold\Support\AiSettingStore::class)->load();

            return new \Mooeen\Scaffold\Designer\TranslationService(
                baseUrl: $ai['base_url'],
                apiKey: $ai['api_key'],
                model: $ai['model'],
                timeout: $ai['timeout'],
                temperature: $ai['temperature'],
                maxTokens: $ai['max_tokens'],
                connectTimeout: $ai['connect_timeout'],
            );
        });

        // ─────────────────────────────────────────────────────────────
        // SECURITY POLICY:全部 scaffold artisan 命令一律 console-only
        // ─────────────────────────────────────────────────────────────
        // 理由:
        //   1. 代码生成类(moo:model/resource/controller/view/api/free)会写源码 +
        //      触发 i18n / api yaml 链式更新,误触发会污染工作树
        //   2. 数据破坏类(*:prune)清理 yaml,误调不可恢复
        //   3. 初始化类(init / schema)动配置文件
        //   线上若被 web endpoint 触发(Artisan::call) → 攻击面立刻放大
        // 守则:
        //   - 这块 commands() 调用 *必须* 留在 runningInConsole() 条件内
        //   - 任何 Controller / Job / Listener 中出现 Artisan::call('moo:*')
        //     都属于违反 policy,review 时拒绝合并
        //   - Designer GUI 等 web 入口绝不暴露"一键生成代码"按钮
        //   - 唯一例外:Laravel 内置无害命令(如 config:clear)可以 web 调
        if ($this->app->runningInConsole()) {
            $this->commands([
                InitCommand::class,
                CreateSchemaCommand::class,
                FreshStorageCommand::class,
                CreateMigrationCommand::class,
                CreateModelCommand::class,
                CreateResourceCommand::class,
                CreateControllerCommand::class,
                CreateViewCommand::class,
                CreateTestCommand::class,
                UpdateMultilingualCommand::class,
                CreateApiCommand::class,
                UpdateAuthorizationCommand::class,
                FreeCommand::class,
                AdderCommand::class,
                // moo:cloud:push / moo:cloud:mcp 由 moo-monitor-laravel 提供(命令名不变)
                // 开发人员账号：仅保留 add（首个账号引导用）；其余 CRUD 走 Web UI
                AccountAddCommand::class,
                ScaffoldMergeYamlCommand::class,
                SnapshotInitCommand::class,
                DbAuditCommand::class,
            ]);
        }

        // 加载 路由
        $this->loadRoutesFrom(__DIR__ . '/Http/routes.php');

        // 注册扩展包 视图
        $this->loadViewsFrom(__DIR__ . '/Http/Views', 'scaffold');
    }
}
