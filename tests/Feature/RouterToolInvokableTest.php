<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Mooeen\Scaffold\RouterTool;
use Mooeen\Scaffold\Utility;

class RouterTool_InvokableController
{
    public function __invoke(): array
    {
        return [];
    }
}

it('识别 Laravel 单动作控制器并标准化为 Controller@__invoke', function () {
    config()->set('scaffold.controller.web', [
        'name'          => ['zh-CN' => 'Web', 'en' => 'Web'],
        'api_name'      => 'Web',
        'path'          => 'RouterTool_/',
        'resource_path' => 'app/Web/Resources/',
    ]);

    Route::get('/router-tool-invokable', RouterTool_InvokableController::class)
        ->name('router-tool.invokable');

    $tool   = new RouterTool('web', '', 'action', app(Utility::class), app(Router::class));
    $routes = array_values($tool->get());

    expect($routes)->toHaveCount(1)
        ->and($routes[0]['action'])->toBe(RouterTool_InvokableController::class . '@__invoke')
        ->and($routes[0]['uri'])->toBe('router-tool-invokable');
});
