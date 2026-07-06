<?php declare(strict_types=1);

use Mooeen\Scaffold\Utility;

/**
 * ship-checklist #5 回归锁:Controller 后缀 normalize 收敛到 Utility 单一真源后，
 * 锁住「只剥/补尾缀」的正确语义 —— 历史上散落各端的 str_replace（删全部）/
 * Str::replaceLast（删最后一次）对病态名字结果发散，这里钉死统一行为。
 */
it('stripControllerSuffix 只剥末尾的 Controller', function () {
    expect(Utility::stripControllerSuffix('UserController'))->toBe('User');
    expect(Utility::stripControllerSuffix('User'))->toBe('User');              // 无后缀原样
    expect(Utility::stripControllerSuffix(''))->toBe('');                      // 空串
    expect(Utility::stripControllerSuffix('Controller'))->toBe('');            // 纯 Controller → 空
    // FQCN 也可传（后缀在末尾）
    expect(Utility::stripControllerSuffix('App\\Http\\Controllers\\OrderController'))->toBe('App\\Http\\Controllers\\Order');
});

it('stripControllerSuffix 不动名字中间/开头的 Controller（修正旧 str_replace/replaceLast 的发散）', function () {
    expect(Utility::stripControllerSuffix('ControllerManager'))->toBe('ControllerManager'); // 开头不剥
    expect(Utility::stripControllerSuffix('MyControllerHelper'))->toBe('MyControllerHelper'); // 中间不剥
    expect(Utility::stripControllerSuffix('FooControllerController'))->toBe('FooController'); // 只剥最末一层
});

it('ensureControllerSuffix 缺则补、有则不重复、空串原样', function () {
    expect(Utility::ensureControllerSuffix('User'))->toBe('UserController');
    expect(Utility::ensureControllerSuffix('UserController'))->toBe('UserController'); // 不重复
    expect(Utility::ensureControllerSuffix(''))->toBe('');                              // 空串不补
    expect(Utility::ensureControllerSuffix('ControllerManager'))->toBe('ControllerManagerController'); // 末尾不是 Controller → 补
});

it('strip 与 ensure 互逆（往返稳定）', function () {
    foreach (['User', 'Order', 'ControllerManager'] as $base) {
        $withSuffix = Utility::ensureControllerSuffix($base);
        expect(Utility::stripControllerSuffix($withSuffix))->toBe($base);
    }
});
