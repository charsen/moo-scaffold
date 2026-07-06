<?php declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Mooeen\Scaffold\Http\Middleware\EnforceScaffoldWritable;
use Mooeen\Scaffold\Http\Middleware\ScaffoldAuthenticate;

/**
 * Smoke test 覆盖 scaffold 其他模块 controller 的 GET endpoint
 * (Account / Route / Acl / Config / Api / Runtime / Database 等)。
 *
 * 不测写操作(POST/DELETE)—  这些有副作用(磁盘 yaml/runtime 文件 mutation),
 * 真测需要 fixture 隔离,投入产出比低。这里只验:
 *   1. 路由注册存在(404 → 实测可达)
 *   2. controller boot 不挂(200 / view 渲染)
 *   3. 关键 validation endpoint 422 拦
 */
beforeEach(function () {
    $this->withoutMiddleware([
        ScaffoldAuthenticate::class,
        VerifyCsrfToken::class,
        EnforceScaffoldWritable::class,
    ]);
});

// ─── Scaffold home / Database ───────────────────────────────────

it('GET /scaffold renders home view', function () {
    $this->get('/scaffold')->assertOk();
});

it('GET /scaffold renders the cloud summary panel when configured', function () {
    // 强制走「已接入」分支:配齐 cloud + Http::fake 回一个标准汇总,验面板分支 blade 能渲染、不挂。
    config([
        'moo-monitor.cloud.enabled'  => true,
        'moo-monitor.cloud.base_url' => 'https://cloud.test',
        'moo-monitor.cloud.token'    => 'tok-' . str_repeat('a1', 20),
    ]);
    \Illuminate\Support\Facades\Http::fake([
        'cloud.test/api/v1/summary' => \Illuminate\Support\Facades\Http::response([
            'ok'      => true,
            'project' => ['slug' => 'wc', 'name' => 'WC'],
            'stats'   => [
                'runtimes'     => ['open' => 2, 'total' => 3],
                'slow_queries' => ['open' => 1, 'total' => 1],
                'todos'        => ['open' => 1, 'in_progress' => 0, 'done' => 0, 'total' => 1],
            ],
            'recent' => [
                'runtimes'     => [['hash' => 'abc123abc123', 'exc_class' => 'RuntimeException', 'exc_message' => 'boom', 'count' => 3, 'last_seen' => '2026-06-05T10:00:00+00:00', 'status' => 'open']],
                'slow_queries' => [],
                'todos'        => [['id' => '01', 'title' => '页面崩了', 'status' => 'open', 'priority' => 'high', 'created_at' => '2026-06-05T10:00:00+00:00']],
            ],
            'generated_at' => '2026-06-05T10:00:00+00:00',
        ], 200),
    ]);

    $this->get('/scaffold')
        ->assertOk()
        ->assertSee('S-Cloud 云端汇聚')
        ->assertSee('进入 Moo Scaffold Cloud')
        ->assertSee('RuntimeException')
        ->assertSee('页面崩了')
        ->assertSee('https://cloud.test/app/wc/runtimes');
});

// 注:/scaffold/db 数据库文档功能 5f8250d 已砍(留设计器 + 字典两入口),原 reachability test 已删除。

it('GET /scaffold/dictionaries is reachable (200 or 500 from missing cache)', function () {
    $r = $this->get('/scaffold/dictionaries');
    expect($r->status())->toBeIn([200, 500]);
});

// ─── Api docs / debug ───────────────────────────────────────────

it('GET /scaffold/api renders api list', function () {
    // 无 app 会跳默认应用(2026-06「取消选应用落地页」),跟随跳转后应落在 200 列表页。
    $this->followingRedirects()->get('/scaffold/api')->assertOk();
});

it('GET /scaffold/api?app=admin filters by app', function () {
    $this->get('/scaffold/api?app=admin')->assertOk();
});

it('GET /scaffold/api/request renders debug picker', function () {
    // 同上:无 app 跳默认应用,跟随跳转后落在 200 调试页。
    $this->followingRedirects()->get('/scaffold/api/request')->assertOk();
});

// ─── Routes / Acl ───────────────────────────────────────────────

it('GET /scaffold/routes renders route list', function () {
    $this->get('/scaffold/routes')->assertOk();
});

it('GET /scaffold/acl redirects to route list', function () {
    $r = $this->get('/scaffold/acl');
    $r->assertRedirect();
    expect($r->headers->get('Location'))->toContain('/scaffold/routes');
});

// ─── Config ─────────────────────────────────────────────────────

it('GET /scaffold/config renders config page when enabled', function () {
    $this->get('/scaffold/config')->assertOk();
});

it('GET /scaffold/config/env renders env mirror', function () {
    $r = $this->get('/scaffold/config/env');
    // 端点可能在某些环境下被禁用(403)或返 200
    expect($r->status())->toBeIn([200, 403]);
});

// ─── Cloud ──────────────────────────────────────────────────────

it('GET /scaffold/cloud renders cloud console', function () {
    // cloud 默认未启用(enabled=false)→ 渲染「未启用」状态页,本地缓冲计数为 0
    $this->get('/scaffold/cloud')->assertOk();
});

// ─── Accounts ───────────────────────────────────────────────────

it('GET /scaffold/accounts renders account list', function () {
    $this->get('/scaffold/accounts')->assertOk();
});

// 账号表单(2026-06-09 修):原生表单里未勾选的 checkbox 不进 POST → 后端 has('enabled') 假 →
// 停用意图被丢。hidden enabled=0 在前兜底;密码框由 type=text(明文)改 type=password。
// 放这里(真实 base_path)而非 AccountControllerTest(sandbox base_path 无 composer.json,
// blade 组件编译走 getNamespace 读 composer.json 会 500)。
it('GET /scaffold/accounts 表单含 hidden enabled=0 兜底 + 密码框 type=password', function () {
    $html = $this->get('/scaffold/accounts')->assertOk()->getContent();

    expect($html)->toContain('<input type="hidden" name="enabled" value="0">');
    expect($html)->toContain('id="acc-form-password"');
    expect($html)->toContain('type="password"');
});

it('POST /scaffold/accounts returns 422 on missing required fields', function () {
    $r = $this->postJson('/scaffold/accounts', []);
    // controller 可能用 redirect-back-with-errors 或 JSON 422
    expect($r->status())->toBeIn([302, 422]);
});

// ─── Runtimes（查看器已退役 → CloudRedirectController 重定向/通知云端）──

it('GET /scaffold/runtimes redirects or notices to cloud (本地查看器已退役)', function () {
    // 云端化后本地查看器退役:cloud.base_url 已配则 302 跳云端,未配则 200 退役通知页。
    $r = $this->get('/scaffold/runtimes');
    expect($r->status())->toBeIn([200, 302]);
});

it('GET /scaffold/runtimes/non-existent-hash returns 404 / 302 / 200', function () {
    $r = $this->get('/scaffold/runtimes/non-existent-hash');
    expect($r->status())->toBeIn([404, 302, 200]);     // abort 404 / redirect back / 空页
});

// Todos 已整体移出 scaffold(扩展直发云端),无本地路由可测。

// ─── Auth pages ────────────────────────────────────────────────

it('GET /scaffold/login renders login form (skips ScaffoldAuthenticate)', function () {
    // login 页 不在 ScaffoldAuthenticate group 内
    $this->get('/scaffold/login')->assertOk();
});

// 登出改 POST-only(2026-06-09 修):GET 免 CSRF,<img src=".../scaffold/logout"> 可跨站强制登出。
it('GET /scaffold/logout 不触发登出(防 <img> 跨站强制登出)', function () {
    // 关键安全不变量:GET 不得被当成登出处理。
    // 改 POST-only 后 GET 落 404/405;若回退到 Route::match(['GET','POST']),GET 会命中
    // AuthController@logout 返 302 重定向 → 下面的断言变红。
    $r = $this->get('/scaffold/logout');
    expect($r->status())->toBeIn([404, 405]);
    expect($r->status())->not->toBe(302);
});

it('POST /scaffold/logout 正常处理(登出 → 重定向)', function () {
    $r = $this->post('/scaffold/logout');
    expect($r->status())->toBeIn([302, 200]);
});
