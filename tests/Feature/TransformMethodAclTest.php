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
