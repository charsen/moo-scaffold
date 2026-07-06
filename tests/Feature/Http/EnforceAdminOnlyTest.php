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

it('member 访问非 accounts 路径放行(只锁人员管理)', function () {
    expect(runAdminOnly($this->mw, '/scaffold/db/designer/Demo', 'GET', 'dev')->getStatusCode())->toBe(200);
});

it('auth 关 / 无 user → 放行(单用户/开放模式)', function () {
    expect(runAdminOnly($this->mw, '/scaffold/accounts', 'GET', null)->getStatusCode())->toBe(200);
});

it('custom route prefix: accounts 按配置前缀拦', function () {
    config(['scaffold.route.prefix' => 'devtools']);
    expect(runAdminOnly($this->mw, '/devtools/accounts', 'GET', 'dev')->getStatusCode())->toBe(403);
    expect(runAdminOnly($this->mw, '/scaffold/accounts', 'GET', 'dev')->getStatusCode())->toBe(200);
});
