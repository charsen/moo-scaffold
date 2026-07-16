<?php declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Mooeen\Scaffold\Http\Controllers\AccountController;
use Mooeen\Scaffold\Http\Controllers\ApiController;
use Mooeen\Scaffold\Http\Controllers\ApiProxyController;
use Mooeen\Scaffold\Http\Controllers\AuthController;
use Mooeen\Scaffold\Http\Controllers\CloudController;
use Mooeen\Scaffold\Http\Controllers\CloudRedirectController;
use Mooeen\Scaffold\Http\Controllers\ConfigController;
use Mooeen\Scaffold\Http\Controllers\DesignerController;
use Mooeen\Scaffold\Http\Controllers\DocsController;
use Mooeen\Scaffold\Http\Controllers\RouteController;
use Mooeen\Scaffold\Http\Controllers\ScaffoldController;
use Mooeen\Scaffold\Http\Middleware\EnforceAdminOnly;
use Mooeen\Scaffold\Http\Middleware\EnforceDesignerPermission;
use Mooeen\Scaffold\Http\Middleware\EnforceScaffoldWritable;
use Mooeen\Scaffold\Http\Middleware\ScaffoldAuthenticate;
use Mooeen\Scaffold\Http\Middleware\SecurityHeaders;
use Mooeen\Scaffold\Utility;

$config = (new Utility)->getConfig('route');

if (! ($config['enabled'] ?? true)) {
    return;
}

$prefix     = $config['prefix']     ?? 'scaffold';
$middleware = $config['middleware'] ?? [];

// ============================================================
// Public webhook endpoints（隔离 group）
//
// 不沾用户在 config/scaffold.php 配的 $middleware（如 `web`，会带 CSRF/session）。
// 这些接口本质是 webhook：外部客户端 POST 进来、body 带 token 自鉴权，不应该受
// 浏览器中心化的 CSRF / session 限制。装在用户配的 $middleware 里曾经踩过一次
// 419 「CSRF token mismatch」的坑（某下游项目 把 route.middleware 默认成 ['web']）。
// ============================================================
Route::prefix($prefix)->middleware([SecurityHeaders::class])->group(function () {
    // Todos 上报已迁移到 moo-scaffold-cloud:Chrome 扩展(moo-chrome-dev-tool)直接 POST
    // 云端 /api/v1/todos/intake,scaffold 不再接收。运行时 / 慢 SQL 由 moo:cloud:push 推送上云。

    // CSP 违规上报端点(浏览器 POST 不带 cookie,公开),plan-22 Q5 加固:
    //   - throttle:60,1/IP 防 DDoS
    //   - payload ≤ 8KB(在 controller 内 check)
    Route::post('/csp-report', ScaffoldController::class . '@cspReport')
        ->middleware('throttle:60,1')
        ->name('scaffold.csp.report');
});

// ============================================================
// 浏览器侧 UI / 管理接口（吃用户配的 $middleware）
// ============================================================
Route::prefix($prefix)->middleware(array_merge($middleware, [SecurityHeaders::class]))->group(function () {
    Route::get('/login', AuthController::class . '@showLogin')->name('scaffold.login');
    // /login POST 加节流：5 次/分钟/IP，防暴力破解
    Route::post('/login', AuthController::class . '@login')
        ->middleware('throttle:5,1')
        ->name('scaffold.login.submit');
    // POST-only:GET 登出可被 <img src=.../logout> 跨站强制登出(GET 免 CSRF)。改 POST + @csrf
    // 表单,走 web 组的 VerifyCsrfToken 防 CSRF(2026-06-09 修)。
    Route::post('/logout', AuthController::class . '@logout')->name('scaffold.logout');

    // plan-22 安全审计 Q7:组件预览挪到 inner middleware group 内,双层防御
    //   1) APP_DEBUG=false(生产)→ 路由不注册
    //   2) APP_DEBUG=true(dev/local/staging)→ 路由存在,但需 scaffold 登录态
    // 见下方 Route::middleware([ScaffoldAuthenticate::class, ...])->group() 内

    // 所有受保护路由：登录 + Session（CSRF token 来源）+ CSRF 校验 + 生产/只读写保护
    // VerifyCsrfToken 校验 @csrf 表单 token，挡掉跨站伪造请求（CVE 级别守护）
    // EnforceScaffoldWritable 在 production / readonly 时一律拒绝 POST/PUT/PATCH/DELETE
    Route::middleware([
        ScaffoldAuthenticate::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        EnforceScaffoldWritable::class,
        // per-user：无「设计数据库」权限(can_design_db / admin)→ designer 写操作 403(GET 只读放行)
        EnforceDesignerPermission::class,
        // 人员管理(/accounts)仅 admin 可进(GET + 写都拦非 admin)
        EnforceAdminOnly::class,
    ])->group(function () {
        Route::get('/', ScaffoldController::class . '@index')->name('scaffold.home');

        // plan-22 Q7: 组件预览(仅 APP_DEBUG=true 时启用,且要 scaffold 登录态)
        if (config('app.debug')) {
            Route::get('/_components', fn () => view('scaffold::_components'))->name('scaffold._components');
        }

        // 2026-05-23:数据库文档(table.list / table.show)整功能砍掉,只留字典
        // 2026-05-24 二轮 audit C4:DatabaseController 只剩 dictionaries 一个 method 不划算,合 ScaffoldController
        Route::get('/dictionaries', ScaffoldController::class . '@dictionaries')->name('dictionaries');

        // 2026-06-20:数据库文档(只读)重新引入 —— 3 栏 doc 视图(模块 → 表 → 表详情),复用 SchemaLoader。
        // 纯 GET 只读(生产也可看);?schema=X&table=Y 选中模块/表,服务端渲染,无新增 JS。
        Route::get('/db/docs', ScaffoldController::class . '@dbDocs')->name('db.docs');

        // Plan 19 数据库设计器（DesignerController + 4 个 JSON 端点）
        Route::get('/db/designer', DesignerController::class . '@index')
            ->name('db.designer.index');
        Route::get('/db/designer/{schema}', DesignerController::class . '@show')
            ->where('schema', '[A-Z][A-Za-z0-9]*')
            ->name('db.designer.show');
        // plan-40 §三 R-1 横切补漏:LOCK_EX 已防数据撕裂,加 throttle 防多 tab 排队卡死
        Route::post('/db/designer/{schema}/save', DesignerController::class . '@save')
            ->where('schema', '[A-Z][A-Za-z0-9]*')
            ->middleware('throttle:30,1')
            ->name('db.designer.save');
        // plan-40 §四 B-1:加 throttle 防 DeepSeek 钱包烧 / 账号封;60 次/分钟
        Route::post('/db/designer/translate', DesignerController::class . '@translate')
            ->middleware('throttle:60,1')
            ->name('db.designer.translate');
        Route::get('/db/designer/{schema}/preview', DesignerController::class . '@preview')
            ->where('schema', '[A-Z][A-Za-z0-9]*')
            ->name('db.designer.preview');
        // plan-40 §三 R-1 横切补漏:文件 IO + baseline snapshot 重,人类操作 10/min 足够
        Route::post('/db/designer/{schema}/migrate', DesignerController::class . '@migrate')
            ->where('schema', '[A-Z][A-Za-z0-9]*')
            ->middleware('throttle:10,1')
            ->name('db.designer.migrate');
        // plan-49 migration 合并:dry-run preview + execute 两段式
        Route::post('/db/designer/{schema}/migrations/compact-preview', DesignerController::class . '@compactMigrationsPreview')
            ->where('schema', '[A-Z][A-Za-z0-9]*')
            ->middleware('throttle:30,1')
            ->name('db.designer.migrations.compact_preview');
        Route::post('/db/designer/{schema}/migrations/compact', DesignerController::class . '@compactMigrationsExecute')
            ->where('schema', '[A-Z][A-Za-z0-9]*')
            ->middleware('throttle:10,1')
            ->name('db.designer.migrations.compact_execute');
        // 2026-05-21:删 migration 文件 entry(C 方案)— migrations 表无 record 才允许删,
        // 不动 snapshot(user 自己决定要不要重生成,需手动改 .snapshots/{Schema}.yaml)。
        // URL 不带 .php 后缀(nginx 会把 .php URL 当静态 PHP 走 fastcgi,跳过 Laravel 路由 → 404 HTML),
        // controller 内部拼 .php 拿真文件。
        Route::delete('/db/designer/{schema}/migrations/{stem}', DesignerController::class . '@deleteMigration')
            ->where('schema', '[A-Z][A-Za-z0-9]*')
            ->where('stem', '[0-9a-zA-Z_]+')
            ->middleware('throttle:10,1')
            ->name('db.designer.migration.delete');
        // #4:新建 schema(写 .yaml stub)— plan-40 §三 R-1 横切补漏
        Route::post('/db/designer/schemas', DesignerController::class . '@createSchema')
            ->middleware('throttle:10,1')
            ->name('db.designer.create_schema');
        // 草稿态 schema 改名 + 删(锁定态拒绝;改了 / 删了 yaml + cache invalidate,downstream 无影响)
        Route::put('/db/designer/schemas/{schema}', DesignerController::class . '@renameSchema')
            ->where('schema', '[A-Z][A-Za-z0-9]*')
            ->middleware('throttle:10,1')
            ->name('db.designer.rename_schema');
        // 表 key 改名:仅未生成 migration 时;rename yaml 节点(非合并出重复表)+ cache 重建。
        // controller / acl 不源于表 key,不受影响;锁定表(已生成 migration)后端拒。
        Route::put('/db/designer/{schema}/tables/{table}/rename', DesignerController::class . '@renameTable')
            ->where('schema', '[A-Z][A-Za-z0-9]*')
            ->where('table', '[A-Za-z][A-Za-z0-9_]*')
            ->middleware('throttle:10,1')
            ->name('db.designer.rename_table');
        Route::delete('/db/designer/schemas/{schema}', DesignerController::class . '@deleteSchema')
            ->where('schema', '[A-Z][A-Za-z0-9]*')
            ->middleware('throttle:10,1')
            ->name('db.designer.delete_schema');
        Route::post('/db/designer/{schema}/tables', DesignerController::class . '@createTable')
            ->where('schema', '[A-Z][A-Za-z0-9]*')
            ->middleware('throttle:30,1')
            ->name('db.designer.create_table');
        // v6.2 round 7:删表(只删 yaml 节点)— plan-40 §三 R-1 横切补漏
        Route::delete('/db/designer/{schema}/tables/{table}', DesignerController::class . '@deleteTable')
            ->where('schema', '[A-Z][A-Za-z0-9]*')
            ->where('table', '[A-Za-z][A-Za-z0-9_]*')
            ->middleware('throttle:30,1')
            ->name('db.designer.delete_table');
        // URL path 不带 .php(nginx 会按 try_files 拦截非存在的 .php 文件),用 ?file= query
        Route::get('/db/designer/{schema}/migration-content', DesignerController::class . '@migrationContent')
            ->where('schema', '[A-Z][A-Za-z0-9]*')
            ->name('db.designer.migration_content');

        // plan-29:ACL 单页已合并进接口路由(/routes),保留命名路由 + redirect 让旧书签不坏
        // ACL 已并入「接口路由」页(route.list);转发 app/m/keyword,否则 param 页「查看 ACL」chip
        // 的 app 上下文 + keyword 过滤会在重定向时丢失,落到无过滤的 routes 页(2026-06-11 修)。
        Route::get('/acl', fn (\Illuminate\Http\Request $req) => redirect()->route('route.list', $req->only(['app', 'm', 'keyword'])))->name('acl.list');

        // API 接口文档 & 调试
        Route::get('/api', ApiController::class . '@index')->name('api.list');
        Route::get('/api/show', ApiController::class . '@show')->name('api.show');
        Route::get('/api/request', ApiController::class . '@request')->name('api.request');
        Route::get('/api/param', ApiController::class . '@param')->name('api.param');
        Route::post('/api/cache', ApiController::class . '@cache')->name('api.cache');
        // plan-40 §三 R-1 横切:origin 白名单已防 SSRF,加 throttle 防反射 DDoS 工具滥用
        Route::post('/api/proxy', ApiProxyController::class . '@proxy')
            ->middleware('throttle:60,1')
            ->name('api.proxy');

        // 接口路由
        Route::get('/routes', RouteController::class . '@index')->name('route.list');

        // plan-52 文档中心。slug 一律走 ?doc= query（不进路由 path，避开 unicode 路由正则坑;
        // 入口 DocsRepository::isValidSlug + realpath 收敛双层防穿越)。写类(save/preview/delete)
        // 走 EnforceScaffoldWritable 的 docs/* 锁,生产/只读拒;GET 全是只读,生产可看可点深链。
        Route::get('/docs', DocsController::class . '@index')->name('docs.index');
        Route::get('/docs/edit', DocsController::class . '@edit')->name('docs.edit');
        // Mermaid 隔离渲染帧（SecurityHeaders 给它单独放宽 CSP + 允许同源 iframe 嵌入）
        Route::get('/docs/_diagram', DocsController::class . '@diagram')->name('docs.diagram');
        // 引用 picker 的接口/表 catalog（只读 JSON）
        Route::get('/docs/picker', DocsController::class . '@picker')->name('docs.picker');
        Route::post('/docs/preview', DocsController::class . '@preview')
            ->middleware('throttle:120,1')
            ->name('docs.preview');
        Route::post('/docs/save', DocsController::class . '@save')
            ->middleware('throttle:30,1')
            ->name('docs.save');
        Route::post('/docs/delete', DocsController::class . '@delete')
            ->middleware('throttle:10,1')
            ->name('docs.delete');
        // 目录主页拖拽排序落盘(写类,同受 EnforceScaffoldWritable 的 docs/* 锁)
        Route::post('/docs/reorder', DocsController::class . '@reorder')
            ->middleware('throttle:30,1')
            ->name('docs.reorder');
        // 全文搜索(只读 JSON,生产可用;搜索框防抖逐键触发,限流放宽到 120/min)
        Route::get('/docs/search', DocsController::class . '@search')
            ->middleware('throttle:120,1')
            ->name('docs.search');

        // Scaffold 配置：主页单页 + 锚点（plan 18）；env 镜像独立页；历史回溯走 git
        Route::get('/config', ConfigController::class . '@index')->name('scaffold.config');
        Route::get('/config/env', ConfigController::class . '@envMirror')->name('scaffold.config.env');
        // AI 配置（Designer 翻译）：GUI 编辑，存 scaffold/ai.yaml（入 git，不走 env）。
        // 必须注册在 /config/{group} 通配前 —— 否则 `ai` 会被 {group} 吞掉。
        // POST 走 EnforceScaffoldWritable 的 config/* 锁,production/只读拒写。
        Route::get('/config/ai', ConfigController::class . '@ai')->name('scaffold.config.ai');
        Route::post('/config/ai', ConfigController::class . '@updateAi')->name('scaffold.config.ai.update');
        // 兼容旧书签：show 路由保留，302 重定向到主页锚点
        Route::get('/config/{group}', ConfigController::class . '@show')
            ->where('group', '[a-z0-9_-]+')
            ->name('scaffold.config.show');
        Route::post('/config/{group}', ConfigController::class . '@update')
            ->where('group', '[a-z0-9_-]+')
            ->name('scaffold.config.update');

        // 开发人员账号（精简版：仅列表 + 增/改/删 + 启停）
        // 2026-05-24 security audit P2:写路由统一 throttle:10,1 防枚举 / 暴力创建,跟 /login throttle:5,1 同等级
        // 2026-05-28 全面 audit:`{username}` regex 起首必 alphanumeric,防 `..` / `.` 之类纯 dot 串
        // (原 `[A-Za-z0-9._-]+` 允许 `..` 两个 dot,虽 AccountStore 单 yaml 无 path traversal 但守紧些)
        Route::get('/accounts', AccountController::class . '@index')->name('scaffold.accounts');
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('/accounts', AccountController::class . '@store')->name('scaffold.accounts.store');
            Route::post('/accounts/{username}', AccountController::class . '@update')
                ->where('username', '[A-Za-z0-9][A-Za-z0-9._-]{0,63}')
                ->name('scaffold.accounts.update');
            Route::post('/accounts/{username}/toggle', AccountController::class . '@toggle')
                ->where('username', '[A-Za-z0-9][A-Za-z0-9._-]{0,63}')
                ->name('scaffold.accounts.toggle');
            Route::post('/accounts/{username}/delete', AccountController::class . '@destroy')
                ->where('username', '[A-Za-z0-9][A-Za-z0-9._-]{0,63}')
                ->name('scaffold.accounts.delete');
        });

        // Runtime 错误查看器已退役 → 统一在 moo-scaffold-cloud 查看。
        // 保留路由名 + URL 路径为重定向桩:旧链接(仪表板 / designer / 钉钉通知)与生成的
        // URL 字符串全部不变,访问时跳云端,不 404。捕获链路(ExceptionDispatcher →
        // RuntimeErrorRecorder 落盘 + moo:cloud:push 上云)保持不变。处置(解决/删除)改在云端。
        Route::get('/runtimes', CloudRedirectController::class . '@runtimes')->name('runtime.list');
        Route::get('/runtimes/{hash}', CloudRedirectController::class . '@runtimes')
            ->where('hash', '[a-f0-9]{12}')
            ->name('runtime.show');

        // 慢 SQL 查看器已退役 → 同上重定向桩。SqlSlowListener 捕获不变。
        Route::get('/sql-slows', CloudRedirectController::class . '@slowQueries')->name('sql-slow.list');
        Route::get('/sql-slows/{hash}', CloudRedirectController::class . '@slowQueries')
            ->where('hash', '[a-f0-9]{12}')
            ->name('sql-slow.show');

        // Todos 已整体移出 scaffold:Chrome 扩展直发 moo-scaffold-cloud,查看也在云端。
        // scaffold 不再有 todo 路由/控制器/存储。

        // 云端汇聚控制台:本地两类缓冲(runtime / 慢 SQL)状态总览 + 运行环境 + 云端入口 + 手动推送。
        //   - index 只读,所有已登录 scaffold 用户可看;
        //   - push 是写类,进 EnforceScaffoldWritable::LOCKED_PATTERNS(scaffold/cloud/push),
        //     生产/只读拒绝(手动推送只适用于本地)。throttle 防连点重复推。
        Route::get('/cloud', CloudController::class . '@index')->name('cloud.index');
        Route::post('/cloud/push', CloudController::class . '@push')
            ->middleware('throttle:10,1')
            ->name('cloud.push');
    });

    // plan-22: scaffold prefix 内的兜底 404,跟随主题(替代 Laravel 默认 dark 强制 404)
    Route::any('/{any}', fn () => response()->view('scaffold::errors.404', [], 404))
        ->where('any', '.*')
        ->name('scaffold.fallback');
});
