<?php declare(strict_types=1);

use Mooeen\Scaffold\Support\AclActionResolver;

/**
 * AclActionResolver 回归锁（此前 0 测试）——鉴权正确性核心：把 controller action 解析成
 * 它运行时会校验的 ACL key。锁住 transform 映射（create→store / 数组多目标 / 默认 map）、
 * 不存在类的安全退化、targetMethodInfo 的 reflection 解析。
 *
 * fixture controller 实现 resolver 反射调用的协议方法 formatAclName / getTransformMethods。
 */
class AclResolverProbeController
{
    public function index() {}

    public function store() {}

    public function update() {}

    public function trashed() {}

    // resolver 反射调这个把 target 转成 key（plain=短名-方法 / full=类@方法）
    public function formatAclName(string $target, bool $plain): string
    {
        [$cls, $m] = explode('::', $target);

        return $plain ? strtolower(class_basename($cls)) . '-' . $m : $cls . '@' . $m;
    }
}

class AclResolverMapController
{
    public function store() {}

    public function update() {}

    public function batch() {}

    // 自定义 transform：单目标 + 数组多目标
    protected function getTransformMethods(): array
    {
        return [
            'edit'  => 'update',
            'batch' => ['store', 'update'],
        ];
    }

    public function formatAclName(string $target, bool $plain): string
    {
        [$cls, $m] = explode('::', $target);

        return $plain ? strtolower(class_basename($cls)) . '-' . $m : $cls . '@' . $m;
    }
}

it('默认 transform map：create→store 视为 transformed', function () {
    $r   = new AclActionResolver;
    $res = $r->resolve(AclResolverProbeController::class, 'create');

    expect($res['transformed'])->toBeTrue();
    expect($res['targets'])->toBe([AclResolverProbeController::class . '::store']);
    expect($res['plain_key'])->toBe('acl-resolver-probe-store');
});

it('action 不在 map 中：原样、transformed=false', function () {
    $r   = new AclActionResolver;
    $res = $r->resolve(AclResolverProbeController::class, 'index');

    expect($res['transformed'])->toBeFalse();
    expect($res['targets'])->toBe([AclResolverProbeController::class . '::index']);
    expect($res['plain_key'])->toBe('acl-resolver-probe-index');
    // md5 开启时 key 为明文 key 的 md5 片段（与 Controller::formatAclName 同口径）。
    expect($res['key'])->toBe('3ed8eaa117ea7005');
});

it('自定义 getTransformMethods：数组多目标 → 多 key', function () {
    $r   = new AclActionResolver;
    $res = $r->resolve(AclResolverMapController::class, 'batch');

    expect($res['targets'])->toBe([
        AclResolverMapController::class . '::store',
        AclResolverMapController::class . '::update',
    ]);
    expect($res['plain_keys'])->toBe([
        'acl-resolver-map-store',
        'acl-resolver-map-update',
    ]);
    expect($res['target_keys'])->toBe([
        AclResolverMapController::class . '::store'  => '0d96613445b4791d',
        AclResolverMapController::class . '::update' => '14504ec4f2eee747',
    ]);
    expect($res['plain_key'])->toBe('acl-resolver-map-store | acl-resolver-map-update');
    expect($res['transformed'])->toBeTrue();
});

it('不存在的类 → 安全空结果', function () {
    $r   = new AclActionResolver;
    $res = $r->resolve('App\\Nope\\TotallyMissingController', 'index');

    expect($res)->toBe([
        'keys'        => [],
        'plain_keys'  => [],
        'key'         => '',
        'plain_key'   => '',
        'targets'     => [],
        'target_keys' => [],
        'target'      => '',
        'transformed' => false,
    ]);
});

it('targetMethodInfo 解析存在的方法、拒绝无效 target', function () {
    $r = new AclActionResolver;

    $info = $r->targetMethodInfo(AclResolverProbeController::class . '::store');
    expect($info)->not->toBeNull();
    expect($info['method'])->toBe('store');
    expect($info['reflection'])->toBeInstanceOf(ReflectionMethod::class);

    expect($r->targetMethodInfo(AclResolverProbeController::class . '::ghost'))->toBeNull(); // 方法不存在
    expect($r->targetMethodInfo('no-double-colon'))->toBeNull();                              // 无 ::
    expect($r->targetMethodInfo('App\\Missing::x'))->toBeNull();                              // 类不存在
});
