<?php
namespace Charsen\Scaffold;

use Charsen\Scaffold\Command\FreeCommand;
use Charsen\Scaffold\Command\InitCommand;
use Charsen\Scaffold\Command\UpdateAuthorizationCommand;
use Charsen\Scaffold\Command\UpdateMultilingualCommand;
use Charsen\Scaffold\Command\CreateMigrationCommand;
use Charsen\Scaffold\Command\CreateModelCommand;
use Charsen\Scaffold\Command\CreateSchemaCommand;
use Charsen\Scaffold\Command\CreateControllerCommand;
use Charsen\Scaffold\Command\FreshStorageCommand;
use Charsen\Scaffold\Command\CreateApiCommand;
use Illuminate\Support\ServiceProvider;

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
        if ($this->app->runningInConsole())
        {
            $this->publishes([__DIR__ . '/../config/config.php' => config_path('scaffold.php')], 'config');

            $this->publishes([__DIR__ . '/../public' => public_path('scaffold_assets')], 'public');
        }
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/config.php', 'scaffold');

        if ($this->app->runningInConsole())
        {
            $this->commands([
                InitCommand::class,
                CreateSchemaCommand::class,
                FreshStorageCommand::class,
                CreateModelCommand::class,
                CreateMigrationCommand::class,
                CreateControllerCommand::class,
                UpdateMultilingualCommand::class,
                CreateApiCommand::class,
                UpdateAuthorizationCommand::class,
                FreeCommand::class,
            ]);
        }

        // 加载 路由
        $this->loadRoutesFrom(__DIR__ . '/Http/routes.php');

        // 注册扩展包 视图
        $this->loadViewsFrom(__DIR__ . '/Http/Views', 'scaffold');
    }
}
