<?php declare(strict_types=1);

use Mooeen\Scaffold\Support\AccountStore;
use Mooeen\Scaffold\Support\AccountWriteForbiddenException;

/**
 * AccountStore 写路径单测:bcrypt 幂等 + last-admin store 层兜底守护 + readonly 硬拒。
 * 这些是"误用会丢账号 / 锁死所有人"的高风险写操作(ship-checklist #9),且原本零覆盖。
 *
 * sandbox:setBasePath 到 temp dir,accounts.yaml 落 sandbox,跑完整目录删 —— 不碰任何真实 accounts。
 */
beforeEach(function () {
    $this->sandbox = sys_get_temp_dir() . '/scaffold_acct_' . uniqid();
    @mkdir($this->sandbox, 0755, true);
    $this->origBase = base_path();
    app()->setBasePath($this->sandbox);
    config(['scaffold.accounts.yaml_path' => 'accounts.yaml']);   // → sandbox/accounts.yaml
    $this->store = app(AccountStore::class);
});

afterEach(function () {
    app()->setBasePath($this->origBase);
    @unlink($this->sandbox . '/accounts.yaml');
    @rmdir($this->sandbox);
});

it('create() 把明文密码 hash 成 bcrypt', function () {
    $row = $this->store->create(['username' => 'alice', 'password' => 's3cret!', 'role' => 'admin'], 'test');
    expect(password_get_info($row['password'])['algo'])->not->toBeNull();   // 是 bcrypt hash
    expect(password_verify('s3cret!', $row['password']))->toBeTrue();
    expect($row['password'])->not->toBe('s3cret!');                          // 不是明文
});

it('create() 不重复 hash 已是 bcrypt 的密码(幂等)', function () {
    $hash = password_hash('preset', PASSWORD_BCRYPT);
    $row  = $this->store->create(['username' => 'bob', 'password' => $hash, 'role' => 'admin'], 'test');
    expect($row['password'])->toBe($hash);     // 原样存,不二次 hash
});

it('delete() 拒删最后一个启用 admin(store 层兜底)', function () {
    $this->store->create(['username' => 'solo', 'password' => 'x', 'role' => 'admin'], 'test');
    expect(fn () => $this->store->delete('solo', 'test'))
        ->toThrow(\RuntimeException::class, '最后一个');
});

it('delete() 允许删非最后的 admin', function () {
    $this->store->create(['username' => 'a1', 'password' => 'x', 'role' => 'admin'], 'test');
    $this->store->create(['username' => 'a2', 'password' => 'x', 'role' => 'admin'], 'test');
    expect($this->store->delete('a1', 'test'))->toBeTrue();
    expect($this->store->find('a1'))->toBeNull();
    expect($this->store->find('a2'))->not->toBeNull();
});

it('last-admin 守护只数 enabled admin', function () {
    $this->store->create(['username' => 'keep', 'password' => 'x', 'role' => 'admin', 'enabled' => true], 'test');
    $this->store->create(['username' => 'off', 'password' => 'x', 'role' => 'admin', 'enabled' => false], 'test');
    // 删唯一 enabled admin → 剩下只有 disabled admin → 视为无剩余 enabled admin → 拒
    expect(fn () => $this->store->delete('keep', 'test'))
        ->toThrow(\RuntimeException::class, '最后一个');
});

it('删 member 不触发 last-admin 守护;但唯一 admin 仍拒删', function () {
    $this->store->create(['username' => 'admin1', 'password' => 'x', 'role' => 'admin'], 'test');
    $this->store->create(['username' => 'mem1', 'password' => 'x', 'role' => 'member'], 'test');
    expect($this->store->delete('mem1', 'test'))->toBeTrue();
    expect(fn () => $this->store->delete('admin1', 'test'))->toThrow(\RuntimeException::class);
});

it('readonly 模式下写方法硬拒(AccountWriteForbiddenException)', function () {
    config(['scaffold.config_ui.readonly' => true]);
    expect(fn () => $this->store->create(['username' => 'z', 'password' => 'x'], 'test'))
        ->toThrow(AccountWriteForbiddenException::class);
});

// ─── canDesignDb 设计数据库权限(2026-06-17) ──────────────────────

it('canDesignDb: admin 角色恒 true(无需 can_design_db flag)', function () {
    $this->store->create(['username' => 'adm', 'password' => 'x', 'role' => 'admin'], 'test');
    expect($this->store->canDesignDb('adm'))->toBeTrue();
});

it('canDesignDb: member 默认 false,授权后 true,撤权后回 false', function () {
    $this->store->create(['username' => 'mem', 'password' => 'x', 'role' => 'member'], 'test');
    expect($this->store->canDesignDb('mem'))->toBeFalse();
    $this->store->update('mem', ['can_design_db' => true], 'test');
    expect($this->store->canDesignDb('mem'))->toBeTrue();
    $this->store->update('mem', ['can_design_db' => false], 'test');
    expect($this->store->canDesignDb('mem'))->toBeFalse();
});

it('canDesignDb: 账号不存在 → false', function () {
    expect($this->store->canDesignDb('ghost'))->toBeFalse();
});

it('can_design_db round-trips through yaml(normalize 白名单保留,reload 不丢)', function () {
    $this->store->create(['username' => 'mem2', 'password' => 'x', 'role' => 'member', 'can_design_db' => true], 'test');
    app()->forgetInstance(\Mooeen\Scaffold\Support\AccountStore::class);
    $fresh = app(\Mooeen\Scaffold\Support\AccountStore::class);
    expect($fresh->find('mem2')['can_design_db'])->toBeTrue();
    expect($fresh->canDesignDb('mem2'))->toBeTrue();
});

it('isAdmin: admin → true,member → false,不存在 → false', function () {
    $this->store->create(['username' => 'boss', 'password' => 'x', 'role' => 'admin'], 'test');
    $this->store->create(['username' => 'dev', 'password' => 'x', 'role' => 'member'], 'test');
    expect($this->store->isAdmin('boss'))->toBeTrue();
    expect($this->store->isAdmin('dev'))->toBeFalse();
    expect($this->store->isAdmin('ghost'))->toBeFalse();
});
