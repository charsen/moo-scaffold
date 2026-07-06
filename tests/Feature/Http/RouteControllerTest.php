<?php declare(strict_types=1);

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Mooeen\Scaffold\Http\Middleware\EnforceScaffoldWritable;
use Mooeen\Scaffold\Http\Middleware\ScaffoldAuthenticate;

/**
 * RouteController HTTP feature 测试。
 *
 * RouteController 只挂 1 条路由 GET /scaffold/routes(index)。基础 200 冒烟已由
 * Designer/ScaffoldRoutesTest 覆盖,这里补 index 内有逻辑的分支:
 *   - ?app= picker 选定后,响应写 30 天 cookie(scaffold_routes_app,raw 非加密)
 *   - 无 ?app= 但 cookie 命中已存在的 app → redirect 让 URL 反映上次选择(open-redirect 安全:
 *     redirect 目标永远是 route('route.list'),app 参数取自 cookie 但只在 apps 白名单内才触发)
 *   - 无效 ?app= → 回退到第一个 app,不报错
 *
 * 不需要 fixture:getApps() 读 config('scaffold.controller'),testbench merge 后默认含 admin/api。
 */
beforeEach(function () {
    $this->withoutMiddleware([
        ScaffoldAuthenticate::class,
        VerifyCsrfToken::class,
        EnforceScaffoldWritable::class,
        // testbench 默认 route.middleware = ['web'],web 组带 EncryptCookies。
        // 生产环境宿主把 scaffold_routes_app 加进 EncryptCookies::$except 走 raw 对称读写,
        // testbench 无此配置 → EncryptCookies 会把未加密的 scaffold_routes_app 解密失败置 null,
        // controller 读不到 cookie。绕过 EncryptCookies 还原生产 raw cookie 语义。
        EncryptCookies::class,
    ]);
});

/** config('scaffold.controller') 里第一个 app key(getApps 的回退默认) */
function routeCtrl_firstApp(): string
{
    $controllers = (array) config('scaffold.controller', []);
    foreach ($controllers as $app => $cfg) {
        if (is_array($cfg)) {
            return (string) $app;
        }
    }

    return '';
}

// ─── 基础 + cookie 写入 ───────────────────────────────────────────

it('GET /scaffold/routes 渲染列表 200', function () {
    $this->get('/scaffold/routes')->assertOk();
});

it('GET /scaffold/routes?app=<firstApp> → 200 且响应写 scaffold_routes_app cookie', function () {
    $app = routeCtrl_firstApp();
    expect($app)->not->toBe('');     // 前置:config 至少有一个 app

    $r = $this->get('/scaffold/routes?app=' . $app);
    $r->assertOk();
    // controller 末尾 ->cookie('scaffold_routes_app', $currentApp, ...) 写 30 天 cookie。
    // 第三参 $encrypted=false:scaffold 走 raw cookie(生产在 EncryptCookies::$except),不解密断言。
    $r->assertCookie('scaffold_routes_app', $app, false);
});

// ─── 无效 app 回退 ────────────────────────────────────────────────

it('GET /scaffold/routes?app=__nope__:未知 app → 回退到首个 app(200,不 500)', function () {
    $r = $this->get('/scaffold/routes?app=__nope__');
    $r->assertOk();
    // 回退后写的 cookie 应是首个 app,而非用户传的无效值(raw cookie,不解密断言)
    $r->assertCookie('scaffold_routes_app', routeCtrl_firstApp(), false);
});

// ─── cookie 命中 → redirect ──────────────────────────────────────

it('无 ?app= 但 cookie 命中已存在 app → 302 redirect 到带该 app 的 route.list', function () {
    $app = routeCtrl_firstApp();

    // withUnencryptedCookie:scaffold routes cookie 是 raw(不进 EncryptCookies),controller 用 $req->cookie() 读
    $r = $this->withUnencryptedCookie('scaffold_routes_app', $app)
        ->get('/scaffold/routes');

    $r->assertRedirect();
    $loc = $r->headers->get('Location');
    expect($loc)->toContain('/scaffold/routes');
    expect($loc)->toContain('app=' . $app);
});

it('无 ?app= 且 cookie 是不存在的 app → 不 redirect,正常 200', function () {
    // cookie 值不在 apps 白名单 → isset($apps[...]) 为 false → 跳过 redirect 分支
    $r = $this->withUnencryptedCookie('scaffold_routes_app', '__ghost_app__')
        ->get('/scaffold/routes');
    $r->assertOk();
});

it('显式 ?app= 时即使 cookie 命中也不 redirect(?app 优先)', function () {
    $app = routeCtrl_firstApp();

    $r = $this->withUnencryptedCookie('scaffold_routes_app', $app)
        ->get('/scaffold/routes?app=' . $app);
    // currentApp 非空 → 跳过 cookie redirect 分支,直接渲染
    $r->assertOk();
});

it('app 配置缺 path 键 → 该 app 降级为空模块,不再整页 500(2026-06-10 修)', function () {
    // 手编 config 漏写 path 的形态:只有 name
    config(['scaffold.controller' => ['admin' => ['name' => ['zh-CN' => '后台', 'en' => 'Admin']]]]);

    $this->get('/scaffold/routes')->assertOk();   // bug 版本:裸取 path → ErrorException 500
});
