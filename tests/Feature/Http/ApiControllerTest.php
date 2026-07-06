<?php declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Http;
use Mooeen\Scaffold\Http\Middleware\EnforceScaffoldWritable;
use Mooeen\Scaffold\Http\Middleware\ScaffoldAuthenticate;

/**
 * ApiController HTTP Feature 测试（**重点/冒烟**,1578 行不追求全覆盖）。
 *
 * GET /api、/api?app=admin、/api/request 渲染冒烟已被 ScaffoldRoutesTest 覆盖。
 * 这里补不重复的重点 endpoint:
 *   - index 过滤:未知 app → 渲染 picker(current_app=null)。
 *   - proxy(SSRF 安全相关):missing url 422 / 非白名单 origin 返 _proxy_status=403 /
 *     非法 method 拒绝 / 命中白名单 host 时走真实 HTTP(Http::fake 拦截不出网)。
 *   - cache:POST 存"上次填的参数",返回 ok。
 *
 * 鉴权 / CSRF / 写保护中间件 withoutMiddleware 绕过。
 * 需要 schema 的部分依赖 PEST_HOST_SCAFFOLD_PATH fixture(套件运行约定已设)。
 */
beforeEach(function () {
    $this->withoutMiddleware([
        ScaffoldAuthenticate::class,
        VerifyCsrfToken::class,
        EnforceScaffoldWritable::class,
    ]);
});

// ─── index 过滤 ─────────────────────────────────────────────────────────

it('GET /scaffold/api?app=__nope__ 未知 app → 跳默认应用(不再是选应用落地页)', function () {
    // 2026-06:无效 app 不再渲染 picker,而是跳默认应用(cookie 上次 / 首个);跟随跳转应落在 200 列表页。
    $this->followingRedirects()->get('/scaffold/api?app=__nope__')
        ->assertOk()
        ->assertViewHas('apps');
});

// ─── 数组参数解析:数组-标量(field + field.*)折成一个可发参数;数组-对象(field.*.sub)父不可发 ──
// 2026-06-21:修「调试页把 field + field.* 数组错解析成两行」。区分 field.* 是标量元素 vs 对象元素。
it('isRuleParameterSendable/isScalarArrayElement:数组-标量父可发(.* 标量元素被吸收),数组-对象父不可发', function () {
    $c        = app(\Mooeen\Scaffold\Http\Controllers\ApiController::class);
    $sendable = new ReflectionMethod($c, 'isRuleParameterSendable');
    $sendable->setAccessible(true);
    $scalar = new ReflectionMethod($c, 'isScalarArrayElement');
    $scalar->setAccessible(true);

    // 数组-标量:market_cart_ids + market_cart_ids.*(numeric)→ 父可单发、.* 是标量元素(formatRules 吸收掉)
    $idsKeys = ['market_cart_ids', 'market_cart_ids.*'];
    expect($sendable->invoke($c, 'market_cart_ids', ['required', 'array', 'min:1'], $idsKeys))->toBeTrue();
    expect($scalar->invoke($c, 'market_cart_ids.*', $idsKeys))->toBeTrue();

    // 数组-对象:medias + medias.* + medias.*.media_file → 父不可发(填子)、medias.* 非标量(有更深 .media_file)、叶子可发
    $objKeys = ['medias', 'medias.*', 'medias.*.media_file'];
    expect($sendable->invoke($c, 'medias', ['required', 'array'], $objKeys))->toBeFalse();
    expect($scalar->invoke($c, 'medias.*', $objKeys))->toBeFalse();
    expect($sendable->invoke($c, 'medias.*.media_file', ['string'], $objKeys))->toBeTrue();
});

// ─── proxy:SSRF 白名单 + 校验 ───────────────────────────────────────────

it('POST /scaffold/api/proxy 缺 _proxy_url → 422', function () {
    $this->postJson('/scaffold/api/proxy', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['_proxy_url']);
});

it('POST /scaffold/api/proxy 非白名单 origin → _proxy_status=403(拒绝出网)', function () {
    // config('scaffold.hosts') 默认 localhost / example.com;evil.com 不在白名单 → isAllowedProxyUrl false
    $r = $this->postJson('/scaffold/api/proxy', [
        '_proxy_url'    => 'http://evil.com/steal',
        '_proxy_method' => 'GET',
    ]);
    $r->assertOk();
    expect($r->json('_proxy_status'))->toBe(403);
});

it('POST /scaffold/api/proxy 非法 scheme(file://)→ url 校验 422 / 403', function () {
    // 'url' 校验规则先拦非 http(s);若放过则 isAllowedProxyUrl 的 scheme 白名单兜底 403
    $r = $this->postJson('/scaffold/api/proxy', [
        '_proxy_url'    => 'file:///etc/passwd',
        '_proxy_method' => 'GET',
    ]);
    expect($r->status())->toBeIn([422, 200]);
    if ($r->status() === 200) {
        expect($r->json('_proxy_status'))->toBe(403);
    }
});

it('POST /scaffold/api/proxy 命中白名单 host → 走真实 HTTP(Http::fake 拦截)', function () {
    // 把 localhost 设进白名单,proxy 放行后真发请求 → Http::fake 拦回 {ok:1}
    config(['scaffold.hosts' => ['本地' => 'http://localhost']]);
    Http::fake(['localhost/*' => Http::response(['ok' => 1], 200)]);

    $r = $this->postJson('/scaffold/api/proxy', [
        '_proxy_url'    => 'http://localhost/api/ping',
        '_proxy_method' => 'GET',
    ]);
    $r->assertOk();
    expect($r->json('_proxy_status'))->toBe(200);
    expect($r->json('ok'))->toBe(1);
});

it('POST /scaffold/api/proxy 上游返回 JSON 列表 → 形状保留(不被 string key 改形成 object)', function () {
    // 原实现 $body[...] 直接往 list 上挂 string key → JSON array 变 object({"0":...,"1":...}),
    // 前端展示 / form-preview shape 检测全被破坏(looksLikeFormWidgets 要求顶层 Array)。
    config(['scaffold.hosts' => ['本地' => 'http://localhost']]);
    Http::fake(['localhost/*' => Http::response([['id' => 1], ['id' => 2]], 200)]);

    $r = $this->postJson('/scaffold/api/proxy', [
        '_proxy_url'    => 'http://localhost/api/list',
        '_proxy_method' => 'GET',
    ]);
    $r->assertOk();
    expect($r->json('_proxy_status'))->toBe(200);
    expect($r->json('data'))->toBe([['id' => 1], ['id' => 2]]);   // 列表完整包在 data 下
});

it('POST /scaffold/api/proxy 上游返回标量 JSON → 不再误报 502(原 throw Cannot use scalar as array)', function () {
    config(['scaffold.hosts' => ['本地' => 'http://localhost']]);
    Http::fake(['localhost/*' => Http::response('"pong"', 200, ['Content-Type' => 'application/json'])]);

    $r = $this->postJson('/scaffold/api/proxy', [
        '_proxy_url'    => 'http://localhost/api/ping',
        '_proxy_method' => 'GET',
    ]);
    $r->assertOk();
    expect($r->json('_proxy_status'))->toBe(200);     // 原实现这里是 502 "Proxy Error: Cannot use a scalar value..."
    expect($r->json('data'))->toBe('pong');
});

it('POST /scaffold/api/proxy 多值响应头(多条 Set-Cookie)全部展示,不只取第一条', function () {
    config(['scaffold.hosts' => ['本地' => 'http://localhost']]);
    Http::fake(['localhost/*' => Http::response(['ok' => 1], 200, [
        'Set-Cookie'   => ['sid=abc; Path=/', 'remember=xyz; Path=/'],
        'Content-Type' => 'application/json',
    ])]);

    $r = $this->postJson('/scaffold/api/proxy', [
        '_proxy_url'    => 'http://localhost/api/login',
        '_proxy_method' => 'POST',
    ]);
    $r->assertOk();
    // 原实现 array_map(fn($v)=>$v[0]) 只返 'sid=abc...',remember 那条丢失
    $cookie = $r->json('_proxy_headers.set-cookie');
    expect($cookie)->toContain('sid=abc');
    expect($cookie)->toContain('remember=xyz');
});

// ─── cache:存上次参数 ───────────────────────────────────────────────────

it('POST /scaffold/api/cache 存参数 → status ok', function () {
    $this->postJson('/scaffold/api/cache', [
        'key'    => 'apictrl_test_' . uniqid(),
        'params' => ['name' => ['value' => 'x', 'checked' => true]],
    ])->assertOk()->assertJson(['status' => 'ok']);
});

it('POST /scaffold/api/cache 缺 key/params 也安全返回 ok(no-op)', function () {
    $this->postJson('/scaffold/api/cache', [])
        ->assertOk()
        ->assertJson(['status' => 'ok']);
});

it('POST /scaffold/api/cache key 超长 / params 非数组 → 422(2026-06-10 加校验)', function () {
    $this->postJson('/scaffold/api/cache', [
        'key'    => str_repeat('k', 201),
        'params' => ['a' => ['value' => 1]],
    ])->assertStatus(422);

    $this->postJson('/scaffold/api/cache', [
        'key'    => 'ok_key',
        'params' => 'not-an-array',
    ])->assertStatus(422);
});

// ─── 接口文档:yaml 形状守护 + deprecated 口径(2026-06-10 修)──────────────

function apiDoc_sandbox(string $actionsYaml): string
{
    $rel = 'apidoc_' . uniqid();
    $dir = base_path($rel . '/admin/Light');
    app(\Illuminate\Filesystem\Filesystem::class)->ensureDirectoryExists($dir);
    file_put_contents($dir . '/Memo.yaml', "controller:\n  class: MemoController\n  name: 备忘管理\nactions:\n{$actionsYaml}");
    config([
        'scaffold.api.schema' => $rel . '/',   // getApiPath 直接拼 app 名,尾斜杠必须有(包默认值同形)
        'scaffold.controller' => ['admin' => ['name' => ['zh-CN' => '后台', 'en' => 'Admin']]],
    ]);

    return $rel;
}

it('文档列表:action 缺 name 字段 → 兜底为 action 名,三页不再 500', function () {
    $rel = apiDoc_sandbox("  index_get:\n    request: [get, 'light/memos']\n");

    try {
        $r = $this->get('/scaffold/api?app=admin');
        $r->assertOk();              // bug 版本:裸取 $attr['name'] → ErrorException 500
        $r->assertSee('index_get');  // 兜底名渲染进侧栏
    } finally {
        app(\Illuminate\Filesystem\Filesystem::class)->deleteDirectory(base_path($rel));
    }
});

it('文档详情:action 形状异常(缺 request)→ 404 而非 500', function () {
    $rel = apiDoc_sandbox("  bad_get:\n    name: 坏接口\n");

    try {
        // bug 版本:getOneApi 裸取 request[0] → ErrorException 500(show 是文档详情 AJAX 端点)
        $this->get('/scaffold/api/show?app=admin&f=Light&c=Memo&a=bad_get')->assertStatus(404);
    } finally {
        app(\Illuminate\Filesystem\Filesystem::class)->deleteDirectory(base_path($rel));
    }
});

it('文档列表:deprecated action 不进计数但仍展示(与 getAppStats 口径对齐)', function () {
    $rel = apiDoc_sandbox("  index_get:\n    name: 列表\n    request: [get, 'light/memos']\n  old_get:\n    name: 旧接口\n    deprecated: true\n    request: [get, 'light/olds']\n");

    try {
        $r = $this->get('/scaffold/api?app=admin');
        $r->assertOk();
        // 侧栏徽章计数 = 活接口数(bug 版本含 deprecated → 2)
        $r->assertViewHas('menus', function (array $menus): bool {
            foreach ($menus as $controllers) {
                foreach ($controllers as $c) {
                    if (($c['class'] ?? '') === 'MemoController') {
                        return ($c['api_count'] ?? null) === 1;
                    }
                }
            }

            return false;
        });
        $r->assertSee('旧接口');     // deprecated 仍展示(带弃用标签)
    } finally {
        app(\Illuminate\Filesystem\Filesystem::class)->deleteDirectory(base_path($rel));
    }
});
