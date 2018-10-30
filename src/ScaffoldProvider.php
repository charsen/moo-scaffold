<?php
namespace Charsen\Scaffold;

use Charsen\Scaffold\Command\CreateDatabaseSchemaCommand;
use Charsen\Scaffold\Command\CreateFoldersCommand;
use Charsen\Scaffold\Command\FreshDatabaseStorageCommand;
use Illuminate\Support\ServiceProvider;

class ScaffoldProvider extends ServiceProvider
{

    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        if ($this->app->runningInConsole())
        {
            $this->publishes([
                __DIR__ . '/../config/config.php' => config_path('scaffold.php'),
            ], 'config');

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
            //$this->app->bind('command.scaffold:database', DatabaseSchemaCommand::class);
            $this->commands([
                CreateFoldersCommand::class,
                CreateDatabaseSchemaCommand::class,
                FreshDatabaseStorageCommand::class,
            ]);
        }

        // 加载 路由
        $this->loadRoutesFrom(__DIR__ . '/Http/routes.php');
        //$this->app->make('Charsen\Scaffold\Http\Controllers\ScaffoldController');

        // 注册扩展包 视图
        $this->loadViewsFrom(__DIR__ . '/Http/Views', 'scaffold');
    }
}
