<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Mooeen\Scaffold\Http\Middleware\EnforceAdminOnly;
use Mooeen\Scaffold\Http\Middleware\EnforceDesignerPermission;
use Mooeen\Scaffold\Http\Middleware\EnforceScaffoldWritable;
use Mooeen\Scaffold\Support\AccountStore;

/**
 * 三个 `Enforce*` 中间件的**回执契约**（统一 JSON 信封 · 第 2 项 · 阶段 2）。
 *
 * 背景：这三处此前各自裸返回 `{error:"字符串"} + 403` —— 全站**第 5 种**旧形态（`error` 是**字符串**，
 * 而控制器那边是 `{ok,error:{…}}`）。更麻烦的是**形状从来没被锁过**：
 * `EnforceAdminOnlyTest` / `EnforceScaffoldWritableTest` 只断言状态码，`EnforceDesignerPermission`
 * 甚至连测试文件都没有（本轮之前 `grep -rl EnforceDesignerPermission tests/` 零命中）。
 *
 * 所以本文件补三件事：
 *   ① **三处产出同一形状** —— 这是本项最该守的契约：把它们并排断言，任何一处单独漂移都会红；
 *   ② 四处拒绝各有**可区分的机器码**（前端要能分支）；
 *   ③ **表单分支没被迁移顺手改坏**（`flash_error` + 303）—— JSON 信封只管 `ajax()/expectsJson()` 那一半。
 *
 * 单元跑中间件本体（伪 Request 直接喂 `handle()`），不经路由/auth —— 与两个既有测试同款。
 */

/** 一律「非 admin、无 designer 权限」的 AccountStore，让三处都走到拒绝分支。 */
function mwEnv_store(): AccountStore
{
    $store = Mockery::mock(AccountStore::class);
    $store->shouldReceive('isAdmin')->andReturnFalse();
    $store->shouldReceive('canDesignDb')->andReturnFalse();

    return $store;
}

/** 造一个命中 JSON 分支的伪请求（`Accept: application/json` ⇒ `expectsJson()` 为真）。 */
function mwEnv_jsonReq(string $uri, string $method = 'POST', ?string $user = 'dev'): Request
{
    $req = Request::create($uri, $method);
    $req->headers->set('Accept', 'application/json');
    if ($user !== null) {
        $req->attributes->set('scaffold_auth_user', $user);
    }

    return $req;
}

/** 按名字跑对应中间件；`$next` 用哨兵响应，放行路径一眼可辨。 */
function mwEnv_run(string $which, Request $req, ?AccountStore $store = null): \Symfony\Component\HttpFoundation\Response
{
    $next = fn () => response('PASSED_THROUGH', 200);
    $store ??= mwEnv_store();

    return match ($which) {
        'admin'    => (new EnforceAdminOnly($store))->handle($req, $next),
        'designer' => (new EnforceDesignerPermission($store))->handle($req, $next),
        'writable' => (new EnforceScaffoldWritable)->handle($req, $next),
    };
}

it('三处 JSON 403 产出同一信封（顶层恰好 ok/error，error 是对象而非字符串）', function () {
    config(['scaffold.config_ui.readonly' => true]);

    $cases = [
        'admin    · accounts 写'     => mwEnv_run('admin', mwEnv_jsonReq('/scaffold/accounts/x/delete')),
        'designer · designer 写'     => mwEnv_run('designer', mwEnv_jsonReq('/scaffold/db/designer/Demo/tables')),
        'writable · 生产/只读锁路径' => mwEnv_run('writable', mwEnv_jsonReq('/scaffold/db/designer/Demo/tables')),
    ];

    foreach ($cases as $label => $res) {
        $body = json_decode((string) $res->getContent(), true);

        expect($res->getStatusCode())->toBe(403, "{$label}：仍必须是 403")
            ->and(array_keys($body))->toBe(['ok', 'error'], "{$label}：顶层恰好 ok/error")
            ->and($body['ok'])->toBeFalse("{$label}：ok 必须是 false")
            // 旧的 `{error:"字符串"}` 形态正是靠这条被挡住的 —— 它没有 ok 键、且 error 是字符串
            ->and($body['error'])->toBeArray("{$label}：error 必须是对象")
            ->and(array_keys($body['error']))->toBe(['code', 'msg', 'detail'], "{$label}：error 恰好三键")
            ->and($body['error']['msg'])->toBeString()->not->toBe('', "{$label}：msg 不许为空（人要看）")
            ->and($body['error']['detail'])->toBe([]);
    }
});

it('四处拒绝各有可区分的机器码（前端要能分支）', function () {
    config(['scaffold.config_ui.readonly' => true]);

    $codeOf = static fn (string $which, string $uri): string => json_decode((string) mwEnv_run($which, mwEnv_jsonReq($uri))->getContent(), true)['error']['code'];

    expect($codeOf('admin', '/scaffold/accounts'))->toBe('ADMIN_ONLY')
        ->and($codeOf('designer', '/scaffold/db/designer/Demo/tables'))->toBe('DESIGNER_FORBIDDEN')
        ->and($codeOf('writable', '/scaffold/db/designer/Demo/tables'))->toBe('WRITE_LOCKED');

    // 第 4 处：plans / release-records 仅 local 可编辑 —— 与上面的「生产写锁」是两个不同的条件
    $origEnv = app()->environment();
    app()->instance('env', 'staging');
    try {
        expect($codeOf('writable', '/scaffold/plans/foo'))->toBe('EDIT_LOCAL_ONLY');
    } finally {
        app()->instance('env', $origEnv);
    }
});

it('表单分支未被迁移改坏：flash_error + 303 回退（信封只管 JSON 那一半）', function () {
    config(['scaffold.config_ui.readonly' => true]);
    $session = app('session')->driver();

    // 刻意**不**带 Accept: application/json ⇒ 走 flash + redirect 那一半
    $req = Request::create('/scaffold/accounts', 'POST');
    $req->attributes->set('scaffold_auth_user', 'dev');
    $req->setLaravelSession($session);

    $res = mwEnv_run('admin', $req);

    expect($res->getStatusCode())->toBe(303)
        ->and($session->get('flash_error'))->toBe('人员管理仅 admin 可访问。');
});

it('designer 中间件：有 can_design_db 权限时放行写（补此前零覆盖的通过分支）', function () {
    $store = Mockery::mock(AccountStore::class);
    $store->shouldReceive('isAdmin')->andReturnFalse();
    $store->shouldReceive('canDesignDb')->once()->with('dev')->andReturnTrue();

    $res = mwEnv_run('designer', mwEnv_jsonReq('/scaffold/db/designer/Demo/tables'), $store);

    expect($res->getStatusCode())->toBe(200)
        ->and((string) $res->getContent())->toBe('PASSED_THROUGH');
});
