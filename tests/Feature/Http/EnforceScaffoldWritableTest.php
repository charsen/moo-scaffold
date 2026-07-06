<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Mooeen\Scaffold\Http\Middleware\EnforceScaffoldWritable;

/**
 * EnforceScaffoldWritable 生产写锁 invariant 单测(production / 强制只读时
 * 拒 designer / accounts / config 的 W 方法,其它 W 与所有安全方法放行)。
 *
 * 单元跑中间件本体(伪 Request + $next),不经路由/控制器/auth —— 直接锁住 LOCKED_PATTERNS 判定逻辑,
 * 路由层哪天动一刀也能 catch。走 JSON 分支(designer/accounts/config 写都是 AJAX),避开 redirect()->back() 的 session 依赖。
 */
function runWritableMiddleware(string $uri, string $method): \Symfony\Component\HttpFoundation\Response
{
    $req = Request::create($uri, $method);
    $req->headers->set('Accept', 'application/json');     // 走 forbidden() 的 JSON 403 分支
    $mw = new EnforceScaffoldWritable;

    return $mw->handle($req, fn () => response('PASSED_THROUGH', 200));
}

it('readonly: locked designer write (POST) → 403', function () {
    config(['scaffold.config_ui.readonly' => true]);
    expect(runWritableMiddleware('/scaffold/db/designer/Demo/tables', 'POST')->getStatusCode())->toBe(403);
});

it('readonly: locked accounts write (POST) → 403', function () {
    config(['scaffold.config_ui.readonly' => true]);
    expect(runWritableMiddleware('/scaffold/accounts/alice/delete', 'POST')->getStatusCode())->toBe(403);
});

it('readonly: locked config write (PUT) → 403', function () {
    config(['scaffold.config_ui.readonly' => true]);
    expect(runWritableMiddleware('/scaffold/config', 'PUT')->getStatusCode())->toBe(403);
});

it('readonly: locked cloud push (POST) → 403', function () {
    config(['scaffold.config_ui.readonly' => true]);
    expect(runWritableMiddleware('/scaffold/cloud/push', 'POST')->getStatusCode())->toBe(403);
});

it('readonly: GET /scaffold/cloud 状态页(非锁路径)放行', function () {
    config(['scaffold.config_ui.readonly' => true]);
    expect(runWritableMiddleware('/scaffold/cloud', 'GET')->getStatusCode())->toBe(200);
});

it('readonly: GET on locked path passes (safe method 永远放行)', function () {
    config(['scaffold.config_ui.readonly' => true]);
    $res = runWritableMiddleware('/scaffold/config', 'GET');
    expect($res->getStatusCode())->toBe(200);
    expect((string) $res->getContent())->toBe('PASSED_THROUGH');
});

it('readonly: 非锁路径写(api/cache)放行', function () {
    config(['scaffold.config_ui.readonly' => true]);
    expect(runWritableMiddleware('/scaffold/api/cache', 'POST')->getStatusCode())->toBe(200);
});

it('dev(非只读非生产): 锁路径写也放行', function () {
    config(['scaffold.config_ui.readonly' => false]);
    expect(runWritableMiddleware('/scaffold/config', 'POST')->getStatusCode())->toBe(200);
});

it('production: 锁路径写 → 403(即使非只读)', function () {
    config(['scaffold.config_ui.readonly' => false]);
    $origEnv = app()->environment();
    app()->instance('env', 'production');
    try {
        expect(runWritableMiddleware('/scaffold/db/designer/Demo/tables', 'POST')->getStatusCode())->toBe(403);
    } finally {
        app()->instance('env', $origEnv);
    }
});

it('production: 非锁路径写仍放行', function () {
    config(['scaffold.config_ui.readonly' => false]);
    $origEnv = app()->environment();
    app()->instance('env', 'production');
    try {
        expect(runWritableMiddleware('/scaffold/api/cache', 'POST')->getStatusCode())->toBe(200);
    } finally {
        app()->instance('env', $origEnv);
    }
});

it('custom route prefix: 锁路径按配置前缀计算(2026-06-09 修:原硬编码 scaffold/ 改前缀即绕过)', function () {
    config(['scaffold.config_ui.readonly' => true, 'scaffold.route.prefix' => 'devtools']);
    // 新前缀下的 designer 写仍被锁(修复前会因 pattern 失配而 200 放行)
    expect(runWritableMiddleware('/devtools/db/designer/Demo/tables', 'POST')->getStatusCode())->toBe(403);
    // pattern 跟随配置前缀:旧 scaffold/ 路径此时已非有效路由,不被锁
    expect(runWritableMiddleware('/scaffold/db/designer/Demo/tables', 'POST')->getStatusCode())->toBe(200);
});
