<?php declare(strict_types=1);

/*
 * 锁定 Foundation\Controller::aclPlainKey 的 key 形状契约。
 *
 * 这是 moo:auth 生成端与运行端鉴权共享的唯一真源——key 一旦悄悄漂移，
 * 下游每个项目的全部账号权限都会对不上。fixture-free：只设 config，不碰 schema。
 *
 * 不变量：
 *  1. 宿主 key 逐字节稳定（含 CRM→c-r-m 等历史怪癖）
 *  2. vendor 包(extra_modules) 收敛成 admin-<module>-*，不泄漏根命名空间
 *  3. 不依赖任何 App\ 字面——换根命名空间靠 config 反查
 */

use Mooeen\Scaffold\Foundation\Controller;

beforeEach(function () {
    config()->set('scaffold.controller', [
        'admin' => [
            'path'          => 'app/Admin/Controllers/',
            'extra_modules' => ['System' => 'Mooeen\\System\\Http\\Controllers\\Admin'],
        ],
        'api' => [
            'path' => 'app/Api/Controllers/',
        ],
    ]);
});

test('宿主 key 逐字节复刻（不依赖 App 根字面）', function () {
    expect(Controller::aclPlainKey('App\\Admin\\Controllers\\System\\DepartmentController::index'))
        ->toBe('admin-system-department-index');
    expect(Controller::aclPlainKey('App\\Admin\\Controllers\\CRM\\CustomerController::index'))
        ->toBe('admin-c-r-m-customer-index');                            // CRM→c-r-m 怪癖必须保留
    expect(Controller::aclPlainKey('App\\Admin\\Controllers\\BigData\\DataDeviceTypeController::index'))
        ->toBe('admin-big-data-data-device-type-index');
    expect(Controller::aclPlainKey('App\\Admin\\Controllers\\DashboardController::index'))
        ->toBe('admin-dashboard-index');                                // 无模块子目录
    expect(Controller::aclPlainKey('App\\Api\\Controllers\\OrderController::store'))
        ->toBe('api-order-store');                                      // 另一个 app 前缀取自 config
});

test('vendor 包(extra_modules) 收敛成 admin-<module>-*，不泄漏命名空间', function () {
    $k = Controller::aclPlainKey('Mooeen\\System\\Http\\Controllers\\Admin\\DepartmentController::index');
    expect($k)->toBe('admin-system-department-index')
        ->and($k)->not->toContain('mooeen')
        ->and($k)->not->toContain('http');

    expect(Controller::aclPlainKey('Mooeen\\System\\Http\\Controllers\\Admin\\PersonnelController::destroyBatch'))
        ->toBe('admin-system-personnel-destroy-batch');
});

test('destroyBatch / 多词 action 正确 snake', function () {
    expect(Controller::aclPlainKey('App\\Admin\\Controllers\\Finance\\InOutBudgetController::updateBudgetValue'))
        ->toBe('admin-finance-in-out-budget-update-budget-value');
});

test('Controller 后缀对 key 身份是可选的（漏后缀折叠到同一 key）', function () {
    // 解释 宿主项目 InOutBudgetHasSubject 漏后缀(`InOutBudget::show`)为何不炸 runtime 鉴权：
    // key 推导本就剥 Controller 后缀，'InOutBudget' 与 'InOutBudgetController' 落到同一 key。
    expect(Controller::aclPlainKey('App\\Admin\\Controllers\\Finance\\InOutBudget::show'))
        ->toBe(Controller::aclPlainKey('App\\Admin\\Controllers\\Finance\\InOutBudgetController::show'));
});

test('未命中任何已配置 app → 退化但确定（gen↔runtime 仍一致，不抛错）', function () {
    $a = Controller::aclPlainKey('Some\\Unknown\\FooController::bar');
    expect($a)->toBe(Controller::aclPlainKey('Some\\Unknown\\FooController::bar'))
        ->and($a)->toBeString()
        ->and($a)->not->toBe('');
});
