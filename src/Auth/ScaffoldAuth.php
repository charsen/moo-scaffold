<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Auth;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Mooeen\Scaffold\Support\AccountStore;

class ScaffoldAuth
{
    /**
     * 鉴权是否启用。fail-closed：仅当 config('scaffold.auth.enabled') 显式 = false 时关闭，
     * 否则一律启用 —— 即使 yaml 为空 / 所有账号停用，也强制走 login（页面给"无账号，请 moo:account:add"提示）。
     *
     * 历史上这里曾在 accounts 为空时返回 false 让 middleware 放行（fail-open），
     * 那是 plan 18 前的兼容逻辑，会让整个 /scaffold 在所有账号停用时变成公开，已废弃。
     */
    public function isEnabled(): bool
    {
        return $this->config('enabled', true) !== false;
    }

    /**
     * 获取可登录账号集合（key = username, value = 完整账号 row）。
     *
     * 唯一数据源：AccountStore（scaffold/accounts.yaml）。
     * config('scaffold.auth.accounts') fallback 已废弃；空 yaml 时需用 `moo:account:add` 创建首个账号。
     *
     * 仅返回 enabled !== false 的账号。
     */
    public function getAccounts(): array
    {
        $store = $this->store();
        if ($store === null || ! $store->exists()) {
            return [];
        }

        $result = [];
        foreach ($store->listEnabled() as $row) {
            $username = trim((string) $row['username']);
            $password = (string) ($row['password'] ?? '');
            if ($username === '' || $password === '') {
                continue;
            }
            $result[$username] = $row;
        }

        return $result;
    }

    /**
     * 一个用于"用户不存在"分支的占位 bcrypt hash，让 attempt() 在用户不存在 / 存在但密码错
     * 两种情况下都跑一次 bcrypt 验证。这是为了消除时间侧信道——否则攻击者通过响应时长
     * 区分（用户不存在 ~1ms vs 命中用户但密码错 ~100ms）即可枚举有效用户名。
     */
    private const DUMMY_BCRYPT_HASH = '$2y$10$WAvkAJBxIO9N4DWQ7zWY3.tBobi1S0RGS6.gAwk0R/W6.HgF0RKQa';

    public function attempt(string $username, string $password): bool
    {
        $username = trim($username);
        $account  = $this->getAccounts()[$username] ?? null;

        // 用户不存在 / 被停用 → 仍然跑一次 dummy bcrypt 维持等时返回，避免用户名枚举
        if ($account === null || ($account['enabled'] ?? true) === false) {
            password_verify($password, self::DUMMY_BCRYPT_HASH);

            return false;
        }

        $stored = (string) $account['password'];
        if ($stored === '') {
            password_verify($password, self::DUMMY_BCRYPT_HASH);

            return false;
        }

        // bcrypt 格式 → password_verify；非 hash 视为旧明文，兼容比较 + 登录成功后自动升级
        if (password_get_info($stored)['algo'] !== null) {
            return password_verify($password, $stored);
        }

        if (! hash_equals($stored, $password)) {
            // 旧明文密码错误 —— 跑一次 dummy bcrypt 维持与 bcrypt 路径等时长
            password_verify($password, self::DUMMY_BCRYPT_HASH);

            return false;
        }

        // 旧明文登录成功 → 静默升级到 bcrypt（一次性 migration，下次走 password_verify）
        $store = $this->store();
        if ($store !== null) {
            try {
                $store->update($username, ['password' => $password], 'auth:auto-upgrade');
            } catch (\Throwable) {
                // 升级失败不影响本次登录
            }
        }

        return true;
    }

    private function store(): ?AccountStore
    {
        if (! function_exists('app')) {
            return null;
        }
        try {
            return app(AccountStore::class);
        } catch (\Throwable) {
            return null;
        }
    }

    public function authenticateRequest(Request $request): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $raw = $request->cookie($this->getCookieName());
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $payload = $this->decodePayload($raw);
        if ($payload === null) {
            return null;
        }

        $username   = (string) ($payload['username'] ?? '');
        $lastActive = (int) ($payload['last_active'] ?? 0);
        $signature  = (string) ($payload['signature'] ?? '');

        if ($username === '' || $lastActive <= 0 || $signature === '') {
            return null;
        }

        if (! isset($this->getAccounts()[$username])) {
            return null;
        }

        if (! hash_equals($this->sign($username, $lastActive), $signature)) {
            return null;
        }

        if ($lastActive < (time() - $this->getTtlMinutes() * 60)) {
            return null;
        }

        return [
            'username'    => $username,
            'last_active' => $lastActive,
        ];
    }

    public function makeCookie(string $username, ?Request $request = null)
    {
        $lastActive = time();
        // 加密 payload：base64 / HMAC 签名只防伪造，无法阻止抓 cookie 后读出用户名 / 时间戳。
        // Crypt::encryptString 用 APP_KEY (AES-256-CBC) 加密，返回 base64 编码，cookie 安全。
        $value = Crypt::encryptString((string) json_encode([
            'username'    => $username,
            'last_active' => $lastActive,
            'signature'   => $this->sign($username, $lastActive),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $request = $request ?: request();
        $secure  = $request instanceof Request ? $request->isSecure() : false;

        return cookie()->make(
            $this->getCookieName(),
            $value,
            $this->getTtlMinutes(),
            $this->getCookiePath(),
            null,
            $secure,
            true,
            false,
            // plan-22 安全审计 Q8:SameSite Lax → Strict,scaffold 是内部 dev 工具,
            // 不需要从外部链接(邮件/IM)点击带 cookie 进入。副作用:外部直链点进来要重新登录。
            'strict'
        );
    }

    public function forgetCookie()
    {
        return cookie()->forget($this->getCookieName(), $this->getCookiePath());
    }

    public function loginUrl(Request $request, ?string $redirect = null): string
    {
        return route('scaffold.login', [
            'redirect' => $this->sanitizeRedirect($redirect ?? $request->getRequestUri()),
        ]);
    }

    public function sanitizeRedirect(?string $redirect): string
    {
        $default = route('scaffold.home', [], false);
        if (! is_string($redirect) || trim($redirect) === '') {
            return $default;
        }

        $parts = parse_url(trim($redirect));
        if ($parts === false) {
            return $default;
        }

        $path           = (string) ($parts['path'] ?? '');
        $prefix         = trim((string) config('scaffold.route.prefix', 'scaffold'), '/');
        $requiredPrefix = '/' . ($prefix === '' ? '' : $prefix);
        $loginPath      = rtrim($requiredPrefix, '/') . '/login';
        $logoutPath     = rtrim($requiredPrefix, '/') . '/logout';

        if ($path === '' || $path === $loginPath || $path === $logoutPath) {
            return $default;
        }

        if ($requiredPrefix !== '/' && ! str_starts_with($path, $requiredPrefix)) {
            return $default;
        }

        return $path
            . (isset($parts['query']) ? '?' . $parts['query'] : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    public function getCookieName(): string
    {
        return (string) $this->config('cookie_name', 'scaffold_auth');
    }

    public function getTtlMinutes(): int
    {
        return max(1, (int) $this->config('ttl_minutes', 60 * 24 * 7));
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return config('scaffold.auth.' . $key, $default);
    }

    private function getCookiePath(): string
    {
        $prefix = trim((string) config('scaffold.route.prefix', 'scaffold'), '/');

        return $prefix === '' ? '/' : '/' . $prefix;
    }

    private function decodePayload(string $raw): ?array
    {
        // 新格式：Crypt::encryptString 加密的 payload；解密失败说明 cookie 被改 / 老格式 / APP_KEY 换了
        try {
            $json = Crypt::decryptString($raw);
        } catch (DecryptException) {
            return null;
        }

        $payload = json_decode($json, true);

        return is_array($payload) ? $payload : null;
    }

    private function sign(string $username, int $lastActive): string
    {
        return hash_hmac('sha256', $username . '|' . $lastActive, $this->getSecretKey());
    }

    private function getSecretKey(): string
    {
        $key = (string) config('app.key', 'scaffold');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return $decoded === false ? $key : $decoded;
        }

        return $key;
    }
}
