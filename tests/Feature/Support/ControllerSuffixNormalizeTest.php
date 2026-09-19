<?php declare(strict_types=1);

use Mooeen\Scaffold\Support\ControllerName;
use Mooeen\Scaffold\Utility;

/**
 * ship-checklist #5 回归锁:Controller 后缀 normalize 的**单一真源** `Support\ControllerName`。
 *
 * 锁住「只剥/补尾缀」的正确语义 —— 历史上散落各端的 str_replace（删全部）/
 * Str::replaceLast（删最后一次）对病态名字结果发散，这里钉死统一行为。
 *
 * 2026-09-19 实现自 `Utility` 外迁（`Utility` 是当时全仓最后一个「有状态服务混装静态方法」的类）。
 * 最后一条专钉 `Utility` 上那两个 `@deprecated` 转发与真源**逐点等价** —— 既防「有人照抄一份分叉实现」，
 * 也防「转发被悄悄改成别的语义」。
 */
it('ControllerName::strip 只剥末尾的 Controller', function () {
    expect(ControllerName::strip('UserController'))->toBe('User');
    expect(ControllerName::strip('User'))->toBe('User');              // 无后缀原样
    expect(ControllerName::strip(''))->toBe('');                      // 空串
    expect(ControllerName::strip('Controller'))->toBe('');            // 纯 Controller → 空
    // FQCN 也可传（后缀在末尾）
    expect(ControllerName::strip('App\\Http\\Controllers\\OrderController'))->toBe('App\\Http\\Controllers\\Order');
});

it('ControllerName::strip 不动名字中间/开头的 Controller（修正旧 str_replace/replaceLast 的发散）', function () {
    expect(ControllerName::strip('ControllerManager'))->toBe('ControllerManager'); // 开头不剥
    expect(ControllerName::strip('MyControllerHelper'))->toBe('MyControllerHelper'); // 中间不剥
    expect(ControllerName::strip('FooControllerController'))->toBe('FooController'); // 只剥最末一层
});

it('ControllerName::ensure 缺则补、有则不重复、空串原样', function () {
    expect(ControllerName::ensure('User'))->toBe('UserController');
    expect(ControllerName::ensure('UserController'))->toBe('UserController'); // 不重复
    expect(ControllerName::ensure(''))->toBe('');                              // 空串不补
    expect(ControllerName::ensure('ControllerManager'))->toBe('ControllerManagerController'); // 末尾不是 Controller → 补
});

it('strip 与 ensure 互逆（往返稳定）', function () {
    foreach (['User', 'Order', 'ControllerManager'] as $base) {
        $withSuffix = ControllerName::ensure($base);
        expect(ControllerName::strip($withSuffix))->toBe($base);
    }
});

it('Utility 上两个 @deprecated 转发与真源逐点等价（防重复实现 / 防分叉）', function () {
    // 覆盖：无后缀、有后缀、空串、纯后缀、中间含、双尾缀、FQCN
    $samples = [
        'User', 'UserController', '', 'Controller', 'ControllerManager',
        'MyControllerHelper', 'FooControllerController',
        'App\\Http\\Controllers\\OrderController',
    ];

    foreach ($samples as $sample) {
        expect(Utility::stripControllerSuffix($sample))->toBe(ControllerName::strip($sample));
        expect(Utility::ensureControllerSuffix($sample))->toBe(ControllerName::ensure($sample));
    }
});
