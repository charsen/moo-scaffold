<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Mooeen\Scaffold\Http\Middleware\EnforceAdminOnly;
use Mooeen\Scaffold\Support\AccountStore;

/**
 * EnforceAdminOnly:人员管理(/scaffold/accounts)仅 admin 可进 —— 含「进入」(GET)与「管理」(写)。
 * 单元跑中间件本体(伪 Request + scaffold_auth_user attr + $next),不经路由/auth。
 * 走 JSON 分支(Accept: application/json)避开 redirect()->back() 的 session 依赖。
 */
beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/scaffold-adminonly-' . uniqid('', true);
    mkdir($this->tmpDir, 0777, true);
    config(['scaffold.accounts.yaml_path' => $this->tmpDir . '/accounts.yaml']);
    app()->forgetInstance(AccountStore::class);
    $this->store = app(AccountStore::class);
    $this->store->create(['username' => 'boss', 'password' => 'x', 'role' => 'admin'], 'test');
    $this->store->create(['username' => 'dev', 'password' => 'x', 'role' => 'member'], 'test');
    $this->mw = new EnforceAdminOnly($this->store);
});

afterEach(function () {
    if (isset($this->tmpDir) && is_dir($this->tmpDir)) {
        shell_exec('rm -rf ' . escapeshellarg($this->tmpDir));
    }
});

function runAdminOnly(EnforceAdminOnly $mw, string $uri, string $method, ?string $user): \Symfony\Component\HttpFoundation\Response
{
    $req = Request::create($uri, $method);
    $req->headers->set('Accept', 'application/json');
    if ($user !== null) {
        $req->attributes->set('scaffold_auth_user', $user);
    }

    return $mw->handle($req, fn () => response('PASSED', 200));
}

it('admin 进 accounts(GET)放行', function () {
    expect(runAdminOnly($this->mw, '/scaffold/accounts', 'GET', 'boss')->getStatusCode())->toBe(200);
});

it('member 进 accounts(GET)→ 403(拦在入口,不让进)', function () {
    expect(runAdminOnly($this->mw, '/scaffold/accounts', 'GET', 'dev')->getStatusCode())->toBe(403);
});

it('member 改 accounts(POST delete)→ 403', function () {
    expect(runAdminOnly($this->mw, '/scaffold/accounts/x/delete', 'POST', 'dev')->getStatusCode())->toBe(403);
});

it('member 访问非 accounts / config 路径放行(不误伤其它模块)', function () {
    expect(runAdminOnly($this->mw, '/scaffold/db/designer/Demo', 'GET', 'dev')->getStatusCode())->toBe(200);
});

// ─── 配置写权限(2026-09-11)─────────────────────────────────────────────────
// 依据 docs/overview.md 的角色表「admin 可改任何账号 / 配置 / member 仅自管资料」。
// 这条缺口有实际后果:`scaffold.hosts` 是 /scaffold/api/proxy 的 **SSRF 白名单来源**,
// member 能改它 = 能把任意 origin 塞进白名单再打出去 —— 改白名单的权限不该和用白名单同低。

it('member 改配置(POST /config/hosts)→ 403(不能扩 SSRF 白名单)', function () {
    expect(runAdminOnly($this->mw, '/scaffold/config/hosts', 'POST', 'dev')->getStatusCode())->toBe(403);
});

it('member 改 AI 配置(POST /config/ai)→ 403', function () {
    expect(runAdminOnly($this->mw, '/scaffold/config/ai', 'POST', 'dev')->getStatusCode())->toBe(403);
});

it('admin 改配置(POST /config/hosts)放行', function () {
    expect(runAdminOnly($this->mw, '/scaffold/config/hosts', 'POST', 'boss')->getStatusCode())->toBe(200);
});

it('member 读配置(GET /config)放行 —— 只拦写,页面只读且敏感字段已掩码', function () {
    expect(runAdminOnly($this->mw, '/scaffold/config', 'GET', 'dev')->getStatusCode())->toBe(200);
    expect(runAdminOnly($this->mw, '/scaffold/config/env', 'GET', 'dev')->getStatusCode())->toBe(200);
});

it('非写方法不被当成"写"拦掉(HEAD 属 safe method)', function () {
    expect(runAdminOnly($this->mw, '/scaffold/config/basic', 'HEAD', 'dev')->getStatusCode())->toBe(200);
});

it('config 写拦截也跟随自定义 route prefix', function () {
    config(['scaffold.route.prefix' => 'devtools']);
    expect(runAdminOnly($this->mw, '/devtools/config/hosts', 'POST', 'dev')->getStatusCode())->toBe(403);
    // 原前缀不再受限(证明是按配置前缀匹配,不是硬编码)
    expect(runAdminOnly($this->mw, '/scaffold/config/hosts', 'POST', 'dev')->getStatusCode())->toBe(200);
});

it('auth 关 / 无 user → 放行(单用户/开放模式)', function () {
    expect(runAdminOnly($this->mw, '/scaffold/accounts', 'GET', null)->getStatusCode())->toBe(200);
});

it('custom route prefix: accounts 按配置前缀拦', function () {
    config(['scaffold.route.prefix' => 'devtools']);
    expect(runAdminOnly($this->mw, '/devtools/accounts', 'GET', 'dev')->getStatusCode())->toBe(403);
    expect(runAdminOnly($this->mw, '/scaffold/accounts', 'GET', 'dev')->getStatusCode())->toBe(200);
});
