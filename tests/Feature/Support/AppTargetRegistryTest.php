<?php

declare(strict_types=1);

use Mooeen\Scaffold\Support\AppTargetRegistry;

it('按 sort 返回默认 admin、mobi、web 端并保留 API 文档配置命名空间', function () {
    $registry = app(AppTargetRegistry::class);

    expect(array_keys($registry->all()))->toBe(['admin', 'mobi', 'web'])
        ->and($registry->get('mobi')['path'])->toBe('app/Mobi/Controllers/')
        ->and($registry->get('web')['route'])->toBe('routes/web.php')
        ->and(config('scaffold.api.schema'))->toBe('scaffold/api/');
});

it('兼容旧 host 未声明 profile、controller trait stub 和 route mode 的端配置', function () {
    config()->set('scaffold.controller', [
        'admin' => [
            'name'          => ['zh-CN' => '后台'],
            'path'          => 'app/Admin/Controllers/',
            'request_path'  => 'app/Admin/Requests/',
            'resource_path' => 'app/Admin/Resources/',
            'requests'      => ['index'],
            'stub'          => 'controller-admin',
            'trait_stub'    => 'controller-admin-base-action-trait',
            'route'         => 'routes/admin.php',
        ],
        'rpa' => [
            'name'       => 'RPA',
            'path'       => 'app/Rpa/Controllers/',
            'route'      => 'routes/rpa.php',
            'route_mode' => 'manual',
        ],
    ]);

    $registry = app(AppTargetRegistry::class);

    expect($registry->get('admin')['profile'])->toBe('admin-crud')
        ->and($registry->get('admin')['controller_trait_stub'])->toBe('controller-admin-trait')
        ->and($registry->get('admin')['route_mode'])->toBe('resource')
        ->and($registry->get('rpa')['profile'])->toBe('readonly-api')
        ->and($registry->get('rpa')['route_mode'])->toBe('manual');
});

it('未配置 app 与不完整 codegen 端都会 fail fast', function () {
    config()->set('scaffold.controller', [
        'rpa' => [
            'name'       => 'RPA',
            'path'       => 'app/Rpa/Controllers/',
            'route_mode' => 'manual',
        ],
    ]);

    $registry = app(AppTargetRegistry::class);

    expect(fn () => $registry->assertConfigured(['rpa', 'ghost'], 'Demo.controller.app'))
        ->toThrow(InvalidArgumentException::class, 'ghost')
        ->and(fn () => $registry->codegen('rpa'))
        ->toThrow(InvalidArgumentException::class, '不能用于代码生成');
});

it('拒绝不可预测的 app key 与未知路由模式', function () {
    config()->set('scaffold.controller', ['Mobi' => []]);
    expect(fn () => app(AppTargetRegistry::class)->all())
        ->toThrow(InvalidArgumentException::class, 'key');

    config()->set('scaffold.controller', ['Mobile-App' => []]);
    expect(fn () => app(AppTargetRegistry::class)->all())
        ->toThrow(InvalidArgumentException::class, 'key');

    config()->set('scaffold.controller', [
        'rpa' => ['route_mode' => 'automatic'],
    ]);
    expect(fn () => app(AppTargetRegistry::class)->all())
        ->toThrow(InvalidArgumentException::class, 'route_mode');
});
