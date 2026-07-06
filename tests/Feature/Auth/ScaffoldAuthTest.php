<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Mooeen\Scaffold\Auth\ScaffoldAuth;
use Mooeen\Scaffold\Support\AccountStore;

/**
 * 认证核心单测:cookie 加密 round-trip / 篡改 / 错签名 / TTL 过期 / 账号失效全 fail-closed,
 * 外加 sanitizeRedirect 的 open-redirect 防护。框架边界(Laravel Crypt / cookie 序列化)改动时,
 * 这是唯一能 catch 回归的网 —— 之前零覆盖。
 *
 * sandbox seed 一个 alice(admin)到 temp accounts.yaml,供 authenticateRequest 的账号存在性检查。
 */
beforeEach(function () {
    $this->sandbox = sys_get_temp_dir() . '/scaffold_auth_' . uniqid();
    @mkdir($this->sandbox, 0755, true);
    $this->origBase = base_path();
    app()->setBasePath($this->sandbox);
    config(['scaffold.accounts.yaml_path' => 'accounts.yaml']);
    app(AccountStore::class)->create(['username' => 'alice', 'password' => 'pw', 'role' => 'admin'], 'test');
    $this->auth = new ScaffoldAuth;
});

afterEach(function () {
    app()->setBasePath($this->origBase);
    @unlink($this->sandbox . '/accounts.yaml');
    @rmdir($this->sandbox);
});

function authRequestWithCookie(string $value): Request
{
    return Request::create('/scaffold', 'GET', [], ['scaffold_auth' => $value]);
}

// ─── makeCookie ↔ authenticateRequest round-trip ──────────────────────────

it('makeCookie → authenticateRequest round-trip 返回 username', function () {
    $value  = $this->auth->makeCookie('alice', Request::create('/scaffold'))->getValue();
    $result = $this->auth->authenticateRequest(authRequestWithCookie($value));
    expect($result)->not->toBeNull();
    expect($result['username'])->toBe('alice');
    expect($result['last_active'])->toBeInt()->toBeGreaterThan(0);
});

it('无 cookie → null', function () {
    expect($this->auth->authenticateRequest(Request::create('/scaffold', 'GET')))->toBeNull();
});

it('乱码 cookie(非合法密文)→ null', function () {
    expect($this->auth->authenticateRequest(authRequestWithCookie('not-a-valid-encrypted-cookie')))->toBeNull();
});

it('篡改密文 → 解密失败 → null', function () {
    $value = $this->auth->makeCookie('alice', Request::create('/scaffold'))->getValue();
    $pos   = intdiv(strlen($value), 2);
    // 翻中间一个 base64 字符:破坏 iv/value/mac → DecryptException → fail-closed
    $tampered = substr($value, 0, $pos) . ($value[$pos] === 'A' ? 'B' : 'A') . substr($value, $pos + 1);
    expect($this->auth->authenticateRequest(authRequestWithCookie($tampered)))->toBeNull();
});

it('错签名(payload 合法但 signature 伪造)→ null', function () {
    $value = Crypt::encryptString((string) json_encode([
        'username'    => 'alice',
        'last_active' => time(),
        'signature'   => 'forged-signature',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    expect($this->auth->authenticateRequest(authRequestWithCookie($value)))->toBeNull();
});

it('TTL 过期(last_active 早于 ttl 窗口)→ null', function () {
    $signRef = new ReflectionMethod($this->auth, 'sign');
    $signRef->setAccessible(true);
    $old   = time() - ($this->auth->getTtlMinutes() * 60) - 100;     // 超出 TTL
    $sig   = $signRef->invoke($this->auth, 'alice', $old);            // 用真签名,只让 TTL 触发
    $value = Crypt::encryptString((string) json_encode([
        'username'    => 'alice',
        'last_active' => $old,
        'signature'   => $sig,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    expect($this->auth->authenticateRequest(authRequestWithCookie($value)))->toBeNull();
});

it('账号已不存在(cookie 合法签名但 user 不在 accounts)→ null', function () {
    $signRef = new ReflectionMethod($this->auth, 'sign');
    $signRef->setAccessible(true);
    $now   = time();
    $sig   = $signRef->invoke($this->auth, 'ghost', $now);
    $value = Crypt::encryptString((string) json_encode([
        'username'    => 'ghost',
        'last_active' => $now,
        'signature'   => $sig,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    expect($this->auth->authenticateRequest(authRequestWithCookie($value)))->toBeNull();
});

// ─── sanitizeRedirect:open-redirect 防护 ─────────────────────────────────

it('sanitizeRedirect 放行 prefix 下的路径(含 query / fragment)', function () {
    expect($this->auth->sanitizeRedirect('/scaffold/db/designer'))->toBe('/scaffold/db/designer');
    expect($this->auth->sanitizeRedirect('/scaffold/db/designer?x=1#frag'))->toBe('/scaffold/db/designer?x=1#frag');
});

it('sanitizeRedirect 剥离外部 host,只留同源路径', function () {
    // 绝对 URL 的 host 被 parse_url 丢弃,只取 path;path 在 prefix 下 → 放行同源路径(非跳外站)
    expect($this->auth->sanitizeRedirect('https://evil.com/scaffold/db/designer'))->toBe('/scaffold/db/designer');
});

it('sanitizeRedirect 把 prefix 外 / 空 / login / logout 都打回默认 home', function () {
    $home = route('scaffold.home', [], false);
    expect($this->auth->sanitizeRedirect('/evil/path'))->toBe($home);
    expect($this->auth->sanitizeRedirect('https://evil.com/phishing'))->toBe($home);
    expect($this->auth->sanitizeRedirect(''))->toBe($home);
    expect($this->auth->sanitizeRedirect(null))->toBe($home);
    expect($this->auth->sanitizeRedirect('/scaffold/login'))->toBe($home);
    expect($this->auth->sanitizeRedirect('/scaffold/logout'))->toBe($home);
});
