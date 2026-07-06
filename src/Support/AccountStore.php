<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * 开发人员账号存储（plan 18 §3.2.2）
 *
 * 存储位置：scaffold/accounts.yaml（入 git，跟随 scaffold-sync.sh 同步）
 * 模板：    packages/moo-scaffold/stubs/accounts.example.yaml（git tracked）
 *
 * 写入策略：每次都整体重写 YAML（不增量 patch）；历史回溯走 git，不再单独落备份文件。
 * 生产环境硬拒：write 系列方法在 APP_ENV=production 或 scaffold.config_ui.readonly=true 时
 * 抛 AccountWriteForbiddenException。
 */
class AccountStore
{
    public const ROLE_ADMIN = 'admin';

    public const ROLE_MEMBER = 'member';

    public function __construct(
        private readonly Repository $config,
        private readonly Filesystem $fs,
    ) {}

    public function path(): string
    {
        $rel = (string) $this->config->get('scaffold.accounts.yaml_path', 'scaffold/accounts.yaml');

        return $this->basePath($rel);
    }

    public function exists(): bool
    {
        return $this->fs->exists($this->path());
    }

    /**
     * 全量返回（含 disabled）。返回结构：
     *   [
     *     'meta' => [...],
     *     'accounts' => [
     *       'mooeen' => ['username'=>...,'password'=>...,'phone'=>...,'role'=>...,'enabled'=>true,...],
     *       ...
     *     ],
     *   ]
     */
    public function load(): array
    {
        if (! $this->exists()) {
            return ['meta' => [], 'accounts' => []];
        }

        $raw  = $this->fs->get($this->path());
        $data = Yaml::parse($raw) ?: [];

        $accounts = [];
        foreach ((array) ($data['accounts'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $row = $this->normalize($row);
            if ($row === null) {
                continue;
            }
            $accounts[$row['username']] = $row;
        }

        return [
            'meta'     => (array) ($data['meta'] ?? []),
            'accounts' => $accounts,
        ];
    }

    /**
     * 仅返回 enabled !== false 的账号（ScaffoldAuth 走这个）。
     */
    public function listEnabled(): array
    {
        $all = $this->load()['accounts'];

        return array_values(array_filter($all, fn ($a) => ($a['enabled'] ?? true) !== false));
    }

    public function all(): array
    {
        return array_values($this->load()['accounts']);
    }

    public function find(string $username): ?array
    {
        return $this->load()['accounts'][trim($username)] ?? null;
    }

    public function meta(): array
    {
        return $this->load()['meta'];
    }

    /**
     * 新增账号；username 唯一性校验。
     */
    public function create(array $payload, string $by): array
    {
        $this->assertWritable();

        $username = trim((string) ($payload['username'] ?? ''));
        if ($username === '') {
            throw new RuntimeException('username 不能为空');
        }

        $loaded = $this->load();
        if (isset($loaded['accounts'][$username])) {
            throw new RuntimeException("账号 [{$username}] 已存在");
        }

        $now       = $this->now();
        $rawPwd    = (string) ($payload['password'] ?? '');
        $hashedPwd = $rawPwd === '' ? '' : ($this->isPasswordHashed($rawPwd) ? $rawPwd : password_hash($rawPwd, PASSWORD_BCRYPT));

        $row = $this->normalize([
            'username'      => $username,
            'password'      => $hashedPwd,
            'phone'         => (string) ($payload['phone'] ?? ''),
            'role'          => $this->validateRole($payload['role'] ?? self::ROLE_ADMIN),
            'enabled'       => array_key_exists('enabled', $payload) ? (bool) $payload['enabled'] : true,
            'can_design_db' => (bool) ($payload['can_design_db'] ?? false),
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        if ($row === null) {
            throw new RuntimeException('账号字段不合法');
        }

        $loaded['accounts'][$username] = $row;
        $this->persist($loaded, $by, 'create:' . $username);

        return $row;
    }

    /**
     * 更新账号字段（username 不可改）；只更新 payload 里出现的 key。
     */
    public function update(string $username, array $payload, string $by): array
    {
        $this->assertWritable();
        $username = trim($username);

        $loaded   = $this->load();
        $existing = $loaded['accounts'][$username] ?? null;
        if ($existing === null) {
            throw new RuntimeException("账号 [{$username}] 不存在");
        }

        $next = $existing;
        // password 空字符串表示"不改"（保留旧 hash），非空则 hash 化（如果还没 hash）
        if (array_key_exists('password', $payload)) {
            $newPwd = (string) $payload['password'];
            if ($newPwd !== '') {
                $next['password'] = $this->isPasswordHashed($newPwd)
                    ? $newPwd
                    : password_hash($newPwd, PASSWORD_BCRYPT);
            }
        }
        if (array_key_exists('phone', $payload)) {
            $next['phone'] = (string) $payload['phone'];
        }
        if (array_key_exists('role', $payload)) {
            $next['role'] = $this->validateRole($payload['role']);
        }
        if (array_key_exists('enabled', $payload)) {
            $next['enabled'] = (bool) $payload['enabled'];
        }
        if (array_key_exists('can_design_db', $payload)) {
            $next['can_design_db'] = (bool) $payload['can_design_db'];
        }
        $next['updated_at'] = $this->now();

        // 末位 admin 守护:把最后一个启用 admin 降级(改 role)或停用(enabled=false),会让系统
        // 零 admin、账号管理彻底锁死,只能改磁盘救回。delete() 早有此守护,update() 之前漏了
        // (2026-06-09 修)。
        $wasEnabledAdmin   = ($existing['role'] ?? '') === self::ROLE_ADMIN && ($existing['enabled'] ?? true) !== false;
        $stillEnabledAdmin = ($next['role'] ?? '')     === self::ROLE_ADMIN && ($next['enabled'] ?? true)     !== false;
        if ($wasEnabledAdmin && ! $stillEnabledAdmin) {
            $otherAdmins = array_filter(
                $loaded['accounts'],
                fn ($a, $u) => $u !== $username && ($a['role'] ?? '') === self::ROLE_ADMIN && ($a['enabled'] ?? true) !== false,
                ARRAY_FILTER_USE_BOTH,
            );
            if ($otherAdmins === []) {
                throw new RuntimeException("不能降级 / 停用最后一个启用状态的 admin [{$username}]");
            }
        }

        $loaded['accounts'][$username] = $next;
        $this->persist($loaded, $by, 'update:' . $username);

        return $next;
    }

    /**
     * 该用户能否设计数据库(designer 写权限):admin 角色恒可;member 看 can_design_db flag。
     * 账号不存在 → false。auth 关闭 / 无登录用户的兜底由调用方处理(无 user 视作放行)。
     */
    public function canDesignDb(string $username): bool
    {
        $a = $this->find(trim($username));
        if ($a === null) {
            return false;
        }

        return ($a['role'] ?? '') === self::ROLE_ADMIN || ($a['can_design_db'] ?? false) === true;
    }

    /** 该用户是否 admin 角色(人员管理仅 admin 可进)。账号不存在 → false。 */
    public function isAdmin(string $username): bool
    {
        $a = $this->find(trim($username));

        return $a !== null && ($a['role'] ?? '') === self::ROLE_ADMIN;
    }

    public function toggleEnabled(string $username, bool $enabled, string $by): bool
    {
        $this->update($username, ['enabled' => $enabled], $by);

        return true;
    }

    /**
     * 删除账号；调用方需保证 "不能删自己" / "不能删最后一个 admin" 已在上层检查。
     * 不过 store 本身也做一层 "最后一个 admin 不能删" 的兜底（防止脚本误用）。
     */
    public function delete(string $username, string $by): bool
    {
        $this->assertWritable();
        $username = trim($username);

        $loaded = $this->load();
        $target = $loaded['accounts'][$username] ?? null;
        if ($target === null) {
            return false;
        }

        // 兜底：last admin guard
        if (($target['role'] ?? '') === self::ROLE_ADMIN) {
            $remainingAdmins = array_filter(
                $loaded['accounts'],
                fn ($a, $u) => $u !== $username && ($a['role'] ?? '') === self::ROLE_ADMIN && ($a['enabled'] ?? true) !== false,
                ARRAY_FILTER_USE_BOTH
            );
            if ($remainingAdmins === []) {
                throw new RuntimeException("不能删除最后一个启用状态的 admin [{$username}]");
            }
        }

        unset($loaded['accounts'][$username]);
        $this->persist($loaded, $by, 'delete:' . $username);

        return true;
    }

    // resetToken / importFromConfig / backups / restore / 文件级备份：精简版 UI 不需要，
    // 历史回溯走 git（scaffold/ 整目录入 git）。

    /**
     * 在 production 或强制只读时硬拒。
     */
    public function assertWritable(): void
    {
        if (function_exists('app') && app()->environment('production')) {
            throw new AccountWriteForbiddenException('生产环境禁止写入开发人员账号');
        }
        if ((bool) $this->config->get('scaffold.config_ui.readonly', false)) {
            throw new AccountWriteForbiddenException('当前为强制只读模式（SCAFFOLD_CONFIG_READONLY）');
        }
    }

    // -------------------------------------------------------------------------

    private function persist(array $loaded, string $by, string $action, string $source = 'scaffold-ui'): void
    {
        $accounts       = array_values($loaded['accounts']);
        $loaded['meta'] = [
            'schema_version' => 1,
            'updated_at'     => $this->now(),
            'updated_by'     => $by ?: 'cli',
            'last_action'    => $action,
            'source'         => $source,
            'count'          => count($accounts),
        ];

        $this->writeYaml($loaded['meta'], $accounts);
    }

    private function writeYaml(array $meta, array $accounts): void
    {
        $path = $this->path();
        $this->ensureDir(dirname($path));

        $body = "# Scaffold 开发人员账号（plan 18）\n" .
            "# 由 scaffold Web UI 维护（/scaffold/accounts）；勿手改 schema_version。\n" .
            "# 首个账号引导用 `php artisan moo:account:add`；其余 CRUD 全部走 UI。\n" .
            "# 本文件**入 git**：团队 + 远程部署通过 scaffold-sync.sh 同步；密码以 bcrypt hash 存储。\n\n" .
            Yaml::dump([
                'meta'     => $meta,
                'accounts' => $accounts,
            ], 4, 4, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

        $this->fs->put($path, $body);
        @chmod($path, 0600);
    }

    /**
     * 字段归一：补默认值、过滤未知 key、确保类型。返回 null 表示 row 不合法。
     * 注：不在此 hash password —— normalize 也被 load() 调用（每行 yaml），hash 会让每次
     * load 都消耗 ~100ms × N。hash 化在 create() / update() 入口单独处理。
     */
    private function normalize(array $row): ?array
    {
        $username = trim((string) ($row['username'] ?? ''));
        if ($username === '' || ! preg_match('/^[A-Za-z0-9._-]{1,64}$/', $username)) {
            return null;
        }

        return [
            'username' => $username,
            'password' => (string) ($row['password'] ?? ''),
            'phone'    => (string) ($row['phone'] ?? ''),
            'role'     => $this->validateRole($row['role'] ?? self::ROLE_ADMIN),
            'enabled'  => array_key_exists('enabled', $row) ? (bool) $row['enabled'] : true,
            // 设计数据库权限(designer 写权限);admin 角色另在 canDesignDb() 里恒为 true,此 flag 主要给 member
            'can_design_db' => array_key_exists('can_design_db', $row) ? (bool) $row['can_design_db'] : false,
            'created_at'    => (string) ($row['created_at'] ?? ''),
            'updated_at'    => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * 检测 password 是否已 bcrypt（避免重复 hash）。
     * password_get_info 对非 hash 字符串返回 algo=null（PHP 原生，安全）。
     */
    private function isPasswordHashed(string $password): bool
    {
        return password_get_info($password)['algo'] !== null;
    }

    private function validateRole(mixed $role): string
    {
        $r = is_string($role) ? strtolower(trim($role)) : '';

        return $r === self::ROLE_MEMBER ? self::ROLE_MEMBER : self::ROLE_ADMIN;
    }

    private function basePath(string $relative): string
    {
        return function_exists('base_path') ? base_path($relative) : $relative;
    }

    private function ensureDir(string $dir): void
    {
        if (! $this->fs->isDirectory($dir)) {
            $this->fs->makeDirectory($dir, 0755, true);
        }
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
