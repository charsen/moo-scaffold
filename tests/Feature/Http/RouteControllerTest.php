<?php declare(strict_types=1);

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Router;
use Mooeen\Scaffold\Http\Controllers\RouteController;
use Mooeen\Scaffold\Http\Middleware\EnforceScaffoldWritable;
use Mooeen\Scaffold\Http\Middleware\ScaffoldAuthenticate;
use Mooeen\Scaffold\Support\AclActionResolver;
use Mooeen\Scaffold\Support\AclDocumentLoader;
use Mooeen\Scaffold\Utility;

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
 * 不需要 fixture:getApps() 读 config('scaffold.controller'),testbench merge 后默认含 admin/mobi/web。
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

it('每请求新建实例 —— 六个请求级 memo 的前提(2026-09-18 第 10/11 项)', function () {
    // RouteController 里有 6 个 memo(aclIndexCache / crossAppIndex / controllerMethodsCache /
    // controllerFileCache / apiSchemaCache / menusTransformCache),前五个在 getAppRoutes() 的
    // per-route 循环里、第六个在 per-module 循环里被调用,
    // 且**没有失效钩子**。它们安全的前提就是「控制器不是 singleton」—— 每次请求由容器新建,
    // 所以请求内不会读到上一次请求构建的 ACL / API yaml。
    // 谁哪天把 RouteController 注册成 singleton(或把它挪进某个常驻对象),
    // 六处 memo 会静默变陈旧,这条会先红。
    expect(app(\Mooeen\Scaffold\Http\Controllers\RouteController::class))
        ->not->toBe(app(\Mooeen\Scaffold\Http\Controllers\RouteController::class));
});

// ─── 模块菜单变换 YAML 的请求级 memo(2026-09-18 第 11 项)──────────────────
// 与 ctx 里「等价突变测不出性能差」不同:三种写法(**缓存整张表** / 缓存单个 name / 按 moduleKey
// 分键)的输出并不一样,所以行为层可观测 —— 这条不需要源码锚点。

/** 计数版 Filesystem:resolveModuleName 的「同一 app 只读一次」靠它观测。 */
class RouteCtrlCountingFilesystem extends Filesystem
{
    /** @var list<string> */
    public array $isFileCalls = [];

    public function isFile($file): bool
    {
        $this->isFileCalls[] = (string) $file;

        return parent::isFile($file);
    }
}

/** 造 sandbox + 直接建控制器(绕开路由),返回 [控制器, 计数版 fs, 资源相对目录]。 */
function routeCtrl_memoSandbox(): array
{
    $rel = 'menutrans_' . uniqid();
    app(Filesystem::class)->ensureDirectoryExists(base_path($rel . '/admin'));
    file_put_contents(
        base_path($rel . '/admin/_menus_transform.yaml'),
        "System:\n  name: 系统设置\nOrder:\n  name: 订单中心\n"
    );
    config(['scaffold.api.schema' => $rel . '/']);   // getApiPath 直接拼 app 名,尾斜杠必须有

    $fs   = new RouteCtrlCountingFilesystem;
    $ctrl = new RouteController(
        app(Utility::class),
        $fs,
        app(Router::class),
        app(AclActionResolver::class),
        app(AclDocumentLoader::class),
    );

    return [$ctrl, $fs, $rel];
}

/** 私有方法反射句柄。 */
function routeCtrl_privateMethod(string $class, string $method): ReflectionMethod
{
    $r = new ReflectionMethod($class, $method);
    $r->setAccessible(true);

    return $r;
}

/** 私有属性反射句柄。 */
function routeCtrl_privateProperty(string $class, string $property): ReflectionProperty
{
    $r = new ReflectionProperty($class, $property);
    $r->setAccessible(true);

    return $r;
}

it('resolveModuleName:按 app 缓存整张菜单表 —— 同一 YAML 只解析一次,各模块各得自己的名字', function () {
    [$ctrl, $fs, $rel] = routeCtrl_memoSandbox();

    try {
        $name = routeCtrl_privateMethod(RouteController::class, 'resolveModuleName');

        expect($name->invoke($ctrl, 'admin', 'System'))->toBe('系统设置')
            // 第 2 个模块必须拿自己的名字 —— 「缓存单个 name」的写法会在这里吐「系统设置」
            ->and($name->invoke($ctrl, 'admin', 'Order'))->toBe('订单中心')
            // 未登记的模块回退到 moduleKey(与改前逐字节等价)
            ->and($name->invoke($ctrl, 'admin', 'Unknown'))->toBe('Unknown')
            // 三次调用只探测一次文件
            ->and($fs->isFileCalls)->toHaveCount(1);

        // 键是 app:换 app 要重新解析(不是全局只算一次);该 app 没配 yaml → 回退 key
        expect($name->invoke($ctrl, 'web', 'System'))->toBe('System')
            ->and($fs->isFileCalls)->toHaveCount(2);

        // 再问同一个「没配 yaml」的 app:不该再探测 —— 存进缓存的 [] 也「算已试」。
        // 若判据写成 empty() / `=== []` 这类真值检查,这里会多出第 3 次探测。
        expect($name->invoke($ctrl, 'web', 'Other'))->toBe('Other')
            ->and($fs->isFileCalls)->toHaveCount(2);

        // 缓存里存的是「已 normalize 的整张表」,不是「某模块的名字」
        $cache = routeCtrl_privateProperty(RouteController::class, 'menusTransformCache')->getValue($ctrl);
        expect(array_keys($cache))->toBe(['admin', 'web'])
            ->and($cache['admin']['Order']['name'])->toBe('订单中心')
            ->and($cache['web'])->toBe([]);
    } finally {
        app(Filesystem::class)->deleteDirectory(base_path($rel));
    }
});
