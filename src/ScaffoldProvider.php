<?php

namespace Mooeen\Scaffold;

use Illuminate\Support\ServiceProvider;
use Mooeen\Scaffold\Command\CreateApiCommand;
use Mooeen\Scaffold\Command\CreateControllerCommand;
use Mooeen\Scaffold\Command\CreateMigrationCommand;
use Mooeen\Scaffold\Command\CreateModelCommand;
use Mooeen\Scaffold\Command\CreateSchemaCommand;
use Mooeen\Scaffold\Command\FreeCommand;
use Mooeen\Scaffold\Command\FreshStorageCommand;
use Mooeen\Scaffold\Command\InitCommand;
use Mooeen\Scaffold\Command\UpdateAuthorizationCommand;
use Mooeen\Scaffold\Command\UpdateMultilingualCommand;

/**
 * Laravel Scaffold Service Provider
 *
 * @author Charsen https://github.com/charsen
 */
class ScaffoldProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/config.php' => config_path('scaffold.php')], 'config');

            // $this->publishes([__DIR__ . '/../public' => public_path('vendor/scaffold')], 'public');
        }
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/config.php', 'scaffold');

        if ($this->app->runningInConsole()) {
            $this->commands([
                InitCommand::class,
                CreateSchemaCommand::class,
                FreshStorageCommand::class,
                CreateMigrationCommand::class,
                CreateModelCommand::class,
                CreateControllerCommand::class,
                UpdateMultilingualCommand::class,
                CreateApiCommand::class,
                UpdateAuthorizationCommand::class,
                FreeCommand::class,
            ]);
        }

        // 加载 迁移文件
        $this->loadMigrationsFrom(__DIR__ . '/migrations');

        // 加载 路由
        // $this->loadRoutesFrom(__DIR__ . '/Http/routes.php');

        // 注册扩展包 视图
        // $this->loadViewsFrom(__DIR__ . '/Http/Views', 'scaffold');
    }
}
