<?php declare(strict_types=1);

/*
 * 锁 Foundation\Controller::getAclMethodName 的「权限点转移」一对多（数组）分支。
 *
 * 数组里每个 item 必须与字符串分支同口径：有 :: → 跨控制器；无 :: → 当前控制器的方法。
 * 历史 bug：数组分支对每个 item 一律走 getOtherControllerAction，裸名 'index' 被错算成
 * <namespace>\index（当成控制器、动作为空）→ ACL key 错、永远匹配不上 → 一对多转移对非 root 必 403。
 *
 * fixture-free：只比对「数组分支 == 字符串分支」的一致性，不依赖具体 acl key 形状（那由 AclPlainKeyTest 锁）。
 */

use Mooeen\Scaffold\Foundation\Controller;

function aclMethodNameFor(Controller $ctrl, string $method, array $transform): string|array
{
    $mp = new ReflectionProperty(Controller::class, 'method');
    $mp->setAccessible(true);
    $mp->setValue($ctrl, $method);

    $tp = new ReflectionProperty(Controller::class, 'transform_methods');
    $tp->setAccessible(true);
    $tp->setValue($ctrl, $transform);

    $rm = new ReflectionMethod(Controller::class, 'getAclMethodName');
    $rm->setAccessible(true);

    return $rm->invoke($ctrl);
}

it('getAclMethodName 数组分支：裸名 / 跨控制器都与字符串分支同口径（修复一对多转移）', function () {
    $ctrl = new class extends Controller {};

    // 字符串分支作基准
    $stringBare  = aclMethodNameFor($ctrl, 'm', ['m' => 'index']);                 // 裸名 → 当前控制器
    $stringCross = aclMethodNameFor($ctrl, 'm', ['m' => 'OtherController::index']); // 有 :: → 跨控制器

    // 数组分支：['index', 'OtherController::index']
    $array = aclMethodNameFor($ctrl, 'm', ['m' => ['index', 'OtherController::index']]);

    expect($array)->toBeArray()->toHaveCount(2)
        ->and($array[0])->toBe($stringBare)    // 裸名一致（修复前会算成 <ns>\index → 不一致）
        ->and($array[1])->toBe($stringCross);  // 跨控制器一致
});

/*
 * 锁 `Foundation\Controller::checkAuthorization()` 的数组分支**真的会放行**（2026-09-22 修）。
 *
 * 历史 bug（2.2.6 引入、2.2.7 仍未修，实测连 root 都被拒）：数组分支写成了
 * `Gate::any($method, ['acl_authentication'])` —— `Gate::any($abilities, $arguments)` 的第一个参数
 * 是 **ability 名列表**，于是把 ACL key 当成 ability、把 `acl_authentication` 当成实参，
 * Gate 只定义过 `acl_authentication` → **恒 false**。凡 `transform_methods` 登记成数组的动作
 * 一律 403（MiniAppPageController 3 个、PersonnelOptionController 5 个，以及既有 3 处受害者）。
 *
 * 该缺陷此前无测试覆盖：同文件的用例只锁 `getAclMethodName()` 的**返回值形状**，从不调用
 * `checkAuthorization()`，故形状对、授权仍恒拒 —— 测试全绿而线上 403。
 */

/** 以给定授权结果与 transform 调用 checkAuthorization()，返回是否放行。 */
function aclAuthorizes(Controller $ctrl, string $method, array $transform, array $grantedKeys): bool
{
    $mp = new ReflectionProperty(Controller::class, 'method');
    $mp->setAccessible(true);
    $mp->setValue($ctrl, $method);
    $tp = new ReflectionProperty(Controller::class, 'transform_methods');
    $tp->setAccessible(true);
    $tp->setValue($ctrl, $transform);

    $user     = new class extends \Illuminate\Foundation\Auth\User {};
    $user->id = 7;
    auth()->setUser($user);

    app(\Illuminate\Contracts\Auth\Access\Gate::class)->define(
        'acl_authentication',
        static fn ($u, $key): bool => in_array((string) $key, $grantedKeys, true),
    );

    $config = config('scaffold.authorization.check');
    config()->set('scaffold.authorization.check', true);

    try {
        $ra = new ReflectionMethod(Controller::class, 'checkAuthorization');
        $ra->setAccessible(true);
        $ra->invoke($ctrl);

        return true;
    } catch (\Illuminate\Auth\Access\AuthorizationException) {
        return false;
    } finally {
        config()->set('scaffold.authorization.check', $config);
    }
}

it('checkAuthorization 数组分支：命中任一目标即放行（曾因 Gate::any 参数颠倒而恒拒）', function () {
    $ctrl = new class extends Controller {};
    $keys = aclMethodNameFor($ctrl, 'm', ['m' => ['index', 'OtherController::index']]);
    expect($keys)->toBeArray()->toHaveCount(2);

    // 只授予第二个目标 → 应放行（OR 语义）
    expect(aclAuthorizes($ctrl, 'm', ['m' => ['index', 'OtherController::index']], [$keys[1]]))->toBeTrue();
    // 只授予第一个目标 → 应放行
    expect(aclAuthorizes($ctrl, 'm', ['m' => ['index', 'OtherController::index']], [$keys[0]]))->toBeTrue();
    // 一个都不授予 → 应拒绝
    expect(aclAuthorizes($ctrl, 'm', ['m' => ['index', 'OtherController::index']], []))->toBeFalse();
});
