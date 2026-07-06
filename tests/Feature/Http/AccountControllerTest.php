<?php declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Mooeen\Scaffold\Http\Middleware\EnforceScaffoldWritable;
use Mooeen\Scaffold\Http\Middleware\ScaffoldAuthenticate;
use Mooeen\Scaffold\Support\AccountStore;

/**
 * AccountController HTTP Feature 测试。
 *
 * GET /scaffold/accounts 列表冒烟已被 ScaffoldRoutesTest 覆盖。这里专测**写类**:
 * 创建 / 更新(改密) / 启停 / 删除,以及守护(不能删自己 / 不能删最后一个 admin)。
 *
 * 注意 AccountController 把所有异常吞进 session flash + redirect(不返 422),
 * 所以断言策略是:打 endpoint → 跟随 redirect → 直接读 AccountStore yaml 验证副作用 / 不变。
 *
 * sandbox:app()->setBasePath($tmp) 让 AccountStore::path() 落临时 accounts.yaml。
 * 鉴权 / CSRF / 只读写保护中间件 withoutMiddleware 绕过(env 为 testing 非 production,
 * assertCanWrite 放行),只验 controller + AccountStore 逻辑。
 */
beforeEach(function () {
    $this->withoutMiddleware([
        ScaffoldAuthenticate::class,
        VerifyCsrfToken::class,
        EnforceScaffoldWritable::class,
    ]);

    $this->acctCtrl_tmp = sys_get_temp_dir() . '/scaffold_acctctrl_' . uniqid();
    @mkdir($this->acctCtrl_tmp, 0755, true);
    $this->acctCtrl_origBase = base_path();
    app()->setBasePath($this->acctCtrl_tmp);
    config([
        'scaffold.accounts.yaml_path' => 'accounts.yaml',
        'scaffold.config_ui.readonly' => false,
    ]);

    $this->acctCtrl_store = app(AccountStore::class);
});

afterEach(function () {
    app()->setBasePath($this->acctCtrl_origBase);
    @unlink($this->acctCtrl_tmp . '/accounts.yaml');
    @rmdir($this->acctCtrl_tmp);
});

function acctCtrl_seedAdmin(AccountStore $store, string $username = 'alice'): void
{
    $store->create(['username' => $username, 'password' => 'pw', 'role' => 'admin'], 'seed');
}

// ─── 创建 ────────────────────────────────────────────────────────────────

it('POST /scaffold/accounts 创建账号', function () {
    $r = $this->post('/scaffold/accounts', [
        'username' => 'bob',
        'password' => 'secret',
        'role'     => 'member',
    ]);
    $r->assertRedirect(route('scaffold.accounts'));

    $row = $this->acctCtrl_store->find('bob');
    expect($row)->not->toBeNull();
    expect($row['role'])->toBe('member');
    expect($row)->not->toHaveKey('token');                // token 装置已随 todos 云端化移除
    expect(password_verify('secret', $row['password']))->toBeTrue();
});

it('POST /scaffold/accounts 空 username → flash 错误,不落盘', function () {
    $r = $this->post('/scaffold/accounts', ['username' => '', 'password' => 'x']);
    $r->assertRedirect(route('scaffold.accounts'));
    $r->assertSessionHas('flash_error');

    expect($this->acctCtrl_store->all())->toBe([]);
});

it('POST /scaffold/accounts 非法 username 字符 → flash 错误', function () {
    // extractPayload 用 /^[A-Za-z0-9._-]{1,64}$/ 校验,带空格 → InvalidArgumentException → flash
    $r = $this->post('/scaffold/accounts', ['username' => 'bad name', 'password' => 'x']);
    $r->assertRedirect(route('scaffold.accounts'));
    $r->assertSessionHas('flash_error');
    expect($this->acctCtrl_store->find('bad name'))->toBeNull();
});

it('POST /scaffold/accounts 重复 username → flash 错误', function () {
    acctCtrl_seedAdmin($this->acctCtrl_store, 'dup');
    $r = $this->post('/scaffold/accounts', ['username' => 'dup', 'password' => 'x', 'role' => 'admin']);
    $r->assertSessionHas('flash_error');
    expect($this->acctCtrl_store->all())->toHaveCount(1);
});

// ─── 更新(改密 / 改 role)────────────────────────────────────────────────

it('POST /scaffold/accounts/{username} 改密码', function () {
    acctCtrl_seedAdmin($this->acctCtrl_store, 'carol');
    $oldHash = $this->acctCtrl_store->find('carol')['password'];

    $r = $this->post('/scaffold/accounts/carol', ['password' => 'newpw']);
    $r->assertRedirect(route('scaffold.accounts'));
    $r->assertSessionHas('flash_message');

    $newHash = $this->acctCtrl_store->find('carol')['password'];
    expect($newHash)->not->toBe($oldHash);
    expect(password_verify('newpw', $newHash))->toBeTrue();
});

it('POST /scaffold/accounts/{username} 空密码 = 不改(保留旧 hash)', function () {
    acctCtrl_seedAdmin($this->acctCtrl_store, 'dave');
    $oldHash = $this->acctCtrl_store->find('dave')['password'];

    $this->post('/scaffold/accounts/dave', ['password' => '', 'phone' => '12345'])
        ->assertRedirect();

    $row = $this->acctCtrl_store->find('dave');
    expect($row['password'])->toBe($oldHash);
    expect($row['phone'])->toBe('12345');
});

// ─── 启停 toggle ────────────────────────────────────────────────────────

it('POST /scaffold/accounts/{username}/toggle 切换启用状态', function () {
    acctCtrl_seedAdmin($this->acctCtrl_store, 'eve');
    acctCtrl_seedAdmin($this->acctCtrl_store, 'eve_backup');   // 第二个 admin → eve 非末位,可被停用
    expect($this->acctCtrl_store->find('eve')['enabled'])->toBeTrue();

    $this->post('/scaffold/accounts/eve/toggle')->assertRedirect();
    expect($this->acctCtrl_store->find('eve')['enabled'])->toBeFalse();
});

it('不能停用 / 降级最后一个启用 admin → update 守护拒绝(2026-06-09 修)', function () {
    acctCtrl_seedAdmin($this->acctCtrl_store, 'soloadmin');

    // 停用最后一个 admin:守护拒绝,仍启用
    $this->post('/scaffold/accounts/soloadmin/toggle')->assertRedirect();
    expect($this->acctCtrl_store->find('soloadmin')['enabled'])->toBeTrue();

    // 降级最后一个 admin 为 member:守护拒绝,仍是 admin
    $this->post('/scaffold/accounts/soloadmin', ['role' => 'member'])->assertRedirect();
    expect($this->acctCtrl_store->find('soloadmin')['role'])->toBe('admin');
});

// ─── 删除 + 守护 ────────────────────────────────────────────────────────

it('POST /scaffold/accounts/{username}/delete 删除一个非最后 admin', function () {
    acctCtrl_seedAdmin($this->acctCtrl_store, 'admin1');
    acctCtrl_seedAdmin($this->acctCtrl_store, 'admin2');

    $r = $this->post('/scaffold/accounts/admin2/delete');
    $r->assertRedirect();
    $r->assertSessionHas('flash_message');

    expect($this->acctCtrl_store->find('admin2'))->toBeNull();
    expect($this->acctCtrl_store->find('admin1'))->not->toBeNull();
});

it('不能删除最后一个启用 admin → store 兜底拒绝,flash 错误', function () {
    acctCtrl_seedAdmin($this->acctCtrl_store, 'soloadmin');

    $r = $this->post('/scaffold/accounts/soloadmin/delete');
    $r->assertRedirect();
    $r->assertSessionHas('flash_error');

    expect($this->acctCtrl_store->find('soloadmin'))->not->toBeNull();   // 仍在
});

it('删除不存在账号 → store::delete 返回 false(不 throw),无副作用', function () {
    // controller 不区分"删成功"和"目标不存在"(store::delete 对 null target 直接 return false,
    // 不 throw)→ 走 flash_message 分支,但 yaml 不变。这里只锁"无副作用"这个不变量。
    acctCtrl_seedAdmin($this->acctCtrl_store, 'keep');
    $r = $this->post('/scaffold/accounts/ghost/delete');
    $r->assertRedirect(route('scaffold.accounts'));
    expect($this->acctCtrl_store->all())->toHaveCount(1);
    expect($this->acctCtrl_store->find('keep'))->not->toBeNull();
});

// ─── 路由 ->where('username', ...) 约束 ─────────────────────────────────

it('非法 username 形态(纯 dot 串 / 起首非 alphanumeric)被路由 where 404', function () {
    // [A-Za-z0-9][A-Za-z0-9._-]{0,63} — `..` 起首是 dot → 不匹配 → 404
    $this->post('/scaffold/accounts/../delete')->assertNotFound();
    $this->post('/scaffold/accounts/.hidden/delete')->assertNotFound();
});
