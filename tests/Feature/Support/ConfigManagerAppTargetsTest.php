<?php

declare(strict_types=1);

use Mooeen\Scaffold\Support\ConfigManager;

it('路径配置按 controller 注册表动态展示所有应用端', function () {
    config()->set('scaffold.controller.rpa', [
        'name'       => 'RPA',
        'path'       => 'app/Rpa/Controllers/',
        'route_mode' => 'manual',
    ]);

    $paths = collect(app(ConfigManager::class)->groups()['paths']['fields'])->pluck('path')->all();

    expect($paths)->toContain(
        'controller.admin.path',
        'controller.mobi.path',
        'controller.web.path',
        'controller.rpa.path',
    )->not->toContain('controller.api.path');
});
