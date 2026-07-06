<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

/**
 * Scaffold 配置管理器（plan 18 §3.2.1）
 *
 * 实现：只读路径（分组 / 字段读取 / 字段来源识别 / .env 镜像）+ 写入分发
 *
 * 字段来源（source）两种：
 *   - env  : 源码里写了 env('K', default)，UI 写入走 .env 文件（EnvFileEditor）
 *   - file : 源码里是字面量，UI 写入走 engine/config/scaffold.php（PhpFileEditor）
 *
 * 历史回溯走 git（engine/config/ 入 git）。生产 / readonly 环境硬抛 ConfigWriteForbiddenException。
 */
class ConfigManager
{
    /** 字段类型：影响 UI 控件渲染 */
    public const TYPE_STRING = 'string';

    public const TYPE_INT = 'int';

    public const TYPE_BOOL = 'bool';

    public const TYPE_TEXT = 'text';        // 长文本

    public const TYPE_LIST = 'list';        // 数组（字符串 list）

    public const TYPE_MAP = 'map';          // KV 表（hosts 等）

    public function __construct(
        private readonly Repository $config,
        private readonly Filesystem $fs,
        private readonly ConfigSourceScanner $scanner,
        private readonly PhpFileEditor $phpEditor,
        private readonly EnvFileEditor $envEditor,
    ) {}

    /**
     * 全部分组定义。供 ConfigController::index 渲染总览卡片用。
     */
    public function groups(): array
    {
        return [
            'basic' => $this->groupBasic(),
            'auth'  => $this->groupAuth(),
            'paths' => $this->groupPaths(),
            'route' => $this->groupRoute(),
            'hosts' => $this->groupHosts(),
            'proxy' => $this->groupProxy(),
        ];
    }

    /**
     * 读取单个分组：附加每个字段的 value / source / default / env_key / sensitive。
     */
    public function read(string $groupKey): ?array
    {
        $group = $this->groups()[$groupKey] ?? null;
        if ($group === null) {
            return null;
        }

        $fields = [];
        foreach ($group['fields'] as $field) {
            $fields[] = $this->resolveField($field);
        }
        $group['fields'] = $fields;

        return $group;
    }

    /**
     * 给定 dot-path 返回对应 env key；无则 null。
     */
    public function envOriginOf(string $dotPath): ?string
    {
        return $this->scanner->envKeyOf($dotPath);
    }

    /**
     * 写入分组下的字段。返回 WriteResult 数组：
     *   ['written' => N, 'skipped' => [...], 'diff' => [path => [old, new]]]
     *
     * 跳过条件：
     *   - 字段来源是 env（拒绝；env 必须改 .env）
     *   - 新值与当前值相同（无操作）
     */
    public function write(string $groupKey, array $values, string $by): array
    {
        $this->assertWritable();

        $group = $this->read($groupKey);
        if ($group === null) {
            throw new \RuntimeException("分组 [{$groupKey}] 不存在");
        }

        $diff          = [];
        $skipped       = [];
        $written       = 0;
        $envWrites     = [];   // env key => new string value
        $envConfigSets = [];   // dot-path => new value(env 字段写后覆盖进运行时 config)
        $fileWrites    = [];   // dot-path => new value

        foreach ($group['fields'] as $field) {
            $path = $field['path'];
            if (! array_key_exists($path, $values)) {
                continue;
            }
            $newVal = $this->castValueForField($values[$path], $field['type']);
            // cast 返回 null 一律视为"用户没动 / 非法形状按未动处理":map 没传任何行、
            // int 清空(原 (int)'' = 0 会把 ttl/timeout 写成毒药 0)、标量字段被喂数组等
            if ($newVal === null) {
                continue;
            }

            // plan-40 §五 F1 长度 cap(从 HTTP 验证下沉,见 ConfigController::update):
            // string/text 整值、list/map 每个元素 ≤ 2000,超长拒写走 skipped 提示
            if ($overLong = $this->findOverLongValue($newVal)) {
                $skipped[$path] = "值过长(>2000 字符):{$overLong},未写入";

                continue;
            }

            // value_validator：当前仅支持 'url'。校验失败整字段拒写，提示用户改正
            $validator = $field['value_validator'] ?? null;
            if ($validator === 'url' && is_array($newVal)) {
                $bad = [];
                foreach ($newVal as $vk => $vv) {
                    if (! $this->looksLikeHttpUrl((string) $vv)) {
                        $bad[] = $vk . ' → ' . ($vv === '' ? '(空)' : $vv);
                    }
                }
                if ($bad !== []) {
                    $skipped[$path] = '存在非合法 URL，未写入；请改为 http(s)://... 形式：' . implode('、', $bad);

                    continue;
                }
            }

            $oldVal = $field['raw_value'];
            if ($this->valueEquals($oldVal, $newVal)) {
                continue;
            }

            if ($field['source'] === 'env') {
                $envKey = $field['env_key'];
                if ($envKey === null) {
                    $skipped[$path] = "字段 [{$path}] 标记为 env 但无 env key 映射，跳过";

                    continue;
                }
                $envWrites[$envKey]   = $this->stringifyForEnv($newVal);
                $envConfigSets[$path] = $newVal;
            } else {
                $fileWrites[$path] = $newVal;
            }

            $diff[$path] = [$oldVal, $newVal];
            $written++;
        }

        if ($envWrites !== []) {
            $this->applyEnvWrites($envWrites);
        }
        if ($fileWrites !== []) {
            $this->applyFileWrites($fileWrites);
        }
        if ($envWrites !== [] || $fileWrites !== []) {
            $this->reloadConfig($envConfigSets);
        }

        return [
            'written'   => $written,
            'skipped'   => $skipped,
            'diff'      => $diff,
            'env_dirty' => $envWrites !== [], // 调用方应提示用户重启 worker
        ];
    }

    /**
     * 在生产 / readonly 时拒绝写入。
     */
    public function assertWritable(): void
    {
        if (function_exists('app') && app()->environment('production')) {
            throw new ConfigWriteForbiddenException('生产环境禁止修改 scaffold 配置');
        }
        if ((bool) $this->config->get('scaffold.config_ui.readonly', false)) {
            throw new ConfigWriteForbiddenException('当前为强制只读模式（SCAFFOLD_CONFIG_READONLY）');
        }
    }

    /**
     * 总体只读判定。任一为真即只读：
     *   1. APP_ENV=production
     *   2. config('scaffold.config_ui.readonly') = true
     */
    public function isReadonly(): bool
    {
        if (function_exists('app') && app()->environment('production')) {
            return true;
        }

        return (bool) $this->config->get('scaffold.config_ui.readonly', false);
    }

    /**
     * 读取 .env 文件（脱敏后）供 envMirror 页面展示。
     *
     * 仅做"文本镜像"——不影响运行时；改值仍须人工编辑 .env。
     *
     * @return list<array{key:string, value:string, sensitive:bool}>
     */
    public function readEnvMirror(): array
    {
        $envPath = function_exists('base_path') ? base_path('.env') : '.env';
        if (! $this->fs->exists($envPath)) {
            return [];
        }

        $content   = $this->fs->get($envPath);
        $sensitive = (array) $this->config->get('scaffold.config_ui.sensitive_keys', []);

        $rows = [];
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (! preg_match('/^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*)$/', $line, $m)) {
                continue;
            }
            $key = $m[1];
            $val = trim($m[2]);
            // 去引号
            if (
                (str_starts_with($val, '"') && str_ends_with($val, '"'))
                || (str_starts_with($val, "'") && str_ends_with($val, "'"))
            ) {
                $val = substr($val, 1, -1);
            }
            // 去行尾注释（不在引号内的 # 起的注释）
            if (($pos = strpos($val, '#')) !== false && ! preg_match('/^[\'"]/', $m[2])) {
                $val = rtrim(substr($val, 0, $pos));
            }

            $isSensitive = $this->isSensitiveKey($key, $sensitive);
            $rows[]      = [
                'key'       => $key,
                'value'     => $isSensitive ? $this->mask($val) : $val,
                'sensitive' => $isSensitive,
            ];
        }

        return $rows;
    }

    /**
     * 解析单字段：注入 value / source / env_key / default / sensitive。
     */
    private function resolveField(array $field): array
    {
        $path         = $field['path'];
        $currentValue = $this->config->get('scaffold.' . $path);
        $envKey       = $this->envOriginOf($path);

        // 来源判定：源码里出现 env('K') 即归类 env（写入走 .env）；
        // 其它一律归 file（写入走 engine/config/scaffold.php）。
        $packageDefault = $this->packageDefault($path);
        $source         = $envKey !== null ? 'env' : 'file';

        $sensitive = (bool) ($field['sensitive']
            ?? $this->isSensitiveKey($path, (array) $this->config->get('scaffold.config_ui.sensitive_keys', [])));

        return [
            'path'            => $path,
            'label'           => $field['label'] ?? $path,
            'desc'            => $field['desc']  ?? '',
            'type'            => $field['type']  ?? self::TYPE_STRING,
            'value'           => $sensitive ? $this->maskValue($currentValue) : $currentValue,
            'raw_value'       => $currentValue,
            'source'          => $source,
            'env_key'         => $envKey,
            'default'         => $packageDefault,
            'sensitive'       => $sensitive,
            'options'         => $field['options']         ?? null,
            'value_validator' => $field['value_validator'] ?? null,
        ];
    }

    /**
     * 包内 default config（packages/moo-scaffold/config/config.php）的字段值。
     * 用于"是否被业务侧覆盖"的判定参考。
     */
    private function packageDefault(string $dotPath): mixed
    {
        static $cache = null;
        if ($cache === null) {
            $packageConfigFile = __DIR__ . '/../../config/config.php';
            $cache             = is_file($packageConfigFile) ? (array) require $packageConfigFile : [];
        }

        return data_get($cache, $dotPath);
    }

    /**
     * 是否长得像合法 http(s) URL：必须 http:// 或 https:// 开头 + filter_var 过校验。
     * filter_var 单用会放过 javascript: 等危险 scheme，所以叠 scheme 白名单。
     */
    private function looksLikeHttpUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        if (! preg_match('#^https?://#i', $url)) {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private function valueEquals(mixed $a, mixed $b): bool
    {
        // null vs 空字符串 / 空数组 视为相等（多数情况下 dev 不会刻意区分）
        if ($a === null && ($b === '' || $b === [])) {
            return true;
        }
        if ($b === null && ($a === '' || $a === [])) {
            return true;
        }

        return $a === $b;
    }

    private function isSensitiveKey(string $key, array $sensitiveKeys): bool
    {
        $upper = strtoupper($key);
        foreach ($sensitiveKeys as $needle) {
            if ($needle !== '' && str_contains($upper, strtoupper((string) $needle))) {
                return true;
            }
        }

        return false;
    }

    private function mask(string $value): string
    {
        // plan-22 安全审计 Q1:敏感值统一返 ****(4 个星),不暴露原长度与首尾字符
        // 原"前 3 + 6 个 • + 后 3"露 6 字符 + 长度信息,信息泄漏过多
        // 要查真实值就 SSH cat .env / scaffold/config.yaml
        return $value === '' ? '' : '****';
    }

    private function maskValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->mask($value);
        }
        if (is_array($value)) {
            return array_map(fn ($v) => is_string($v) ? $this->mask($v) : $v, $value);
        }

        return $value;
    }

    private function groupBasic(): array
    {
        return [
            'key'    => 'basic',
            'label'  => '基本信息',
            'desc'   => '编码作者 / 雪花 ID 等元信息',
            'fields' => [
                ['path'    => 'author', 'label' => '当前编码作者', 'type' => self::TYPE_STRING,
                    'desc' => 'scaffold 生成代码时的 @author 注释'],
                ['path' => 'only_in_local', 'label' => '仅本地启用 CLI', 'type' => self::TYPE_BOOL],
                ['path' => 'snow_flake_id', 'label' => '使用雪花 ID', 'type' => self::TYPE_BOOL],
                ['path'    => 'config_ui.enabled', 'label' => '配置 UI 启用', 'type' => self::TYPE_BOOL,
                    'desc' => '关闭后此页面整体不可访问'],
                ['path'    => 'config_ui.readonly', 'label' => '强制只读', 'type' => self::TYPE_BOOL,
                    'desc' => 'true 时即便 APP_ENV=local 也禁止任何写入'],
            ],
        ];
    }

    private function groupAuth(): array
    {
        return [
            'key'    => 'auth',
            'label'  => '鉴权设置',
            'desc'   => 'Scaffold 面板登录设置 + 后台接口鉴权',
            'fields' => [
                ['path' => 'auth.enabled', 'label' => '面板登录启用', 'type' => self::TYPE_BOOL],
                ['path' => 'auth.cookie_name', 'label' => 'Cookie 名', 'type' => self::TYPE_STRING],
                ['path' => 'auth.ttl_minutes', 'label' => '登录有效期（分钟）', 'type' => self::TYPE_INT],
                ['path' => 'authorization.check', 'label' => '后台 ACL 校验', 'type' => self::TYPE_BOOL],
                ['path' => 'authorization.md5', 'label' => 'ACL key md5 加密', 'type' => self::TYPE_BOOL],
            ],
            // Phase 2 落地后此处加 "auth.accounts 已迁移到 /scaffold/accounts" 提示
        ];
    }

    private function groupPaths(): array
    {
        return [
            'key'    => 'paths',
            'label'  => '路径配置',
            'desc'   => 'schema / model / controller / frontend 等输入输出目录',
            'fields' => [
                ['path' => 'database.schema', 'label' => '数据库 schema 路径', 'type' => self::TYPE_STRING],
                ['path' => 'api.schema', 'label' => 'API schema 路径', 'type' => self::TYPE_STRING],
                ['path' => 'api.history', 'label' => 'API 历史路径', 'type' => self::TYPE_STRING],
                ['path' => 'model.path', 'label' => 'Model 输出路径', 'type' => self::TYPE_STRING],
                ['path' => 'controller.admin.path', 'label' => 'Admin controller 路径', 'type' => self::TYPE_STRING],
                ['path' => 'controller.api.path', 'label' => 'Api controller 路径', 'type' => self::TYPE_STRING],
                ['path' => 'frontend.src', 'label' => 'Frontend src', 'type' => self::TYPE_STRING],
                ['path' => 'frontend.models', 'label' => 'Frontend models', 'type' => self::TYPE_STRING],
                ['path' => 'frontend.views', 'label' => 'Frontend views', 'type' => self::TYPE_STRING],
                ['path' => 'frontend.types', 'label' => 'Frontend types', 'type' => self::TYPE_STRING],
            ],
        ];
    }

    private function groupRoute(): array
    {
        return [
            'key'    => 'route',
            'label'  => 'Scaffold 路由',
            'desc'   => '面板自身的路由前缀与中间件',
            'fields' => [
                ['path' => 'route.prefix', 'label' => '路由前缀', 'type' => self::TYPE_STRING],
                ['path' => 'route.middleware', 'label' => '附加中间件', 'type' => self::TYPE_LIST],
            ],
        ];
    }

    private function groupHosts(): array
    {
        return [
            'key'    => 'hosts',
            'label'  => '调试 Host',
            'desc'   => '接口调试器可选的目标域名',
            'fields' => [
                ['path' => 'hosts', 'label' => 'Hosts 映射', 'type' => self::TYPE_MAP, 'value_validator' => 'url'],
            ],
        ];
    }

    private function groupProxy(): array
    {
        return [
            'key'    => 'proxy',
            'label'  => '代理设置',
            'desc'   => '接口调试请求超时(TLS 强制校验,无开关)',
            'fields' => [
                ['path' => 'proxy.timeout', 'label' => '超时（秒）', 'type' => self::TYPE_INT],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // 写入 helper
    // -------------------------------------------------------------------------

    /**
     * 写 env 字段：批量替换 .env 行，写完清 config cache。
     */
    private function applyEnvWrites(array $envWrites): void
    {
        $envPath = function_exists('base_path') ? base_path('.env') : '.env';
        if (! is_file($envPath)) {
            throw new \RuntimeException('.env 文件不存在，无法写入');
        }
        $this->envEditor->setKeysInFile($envPath, $envWrites);

        // 清 config cache（bootstrap/cache/config.php），同时刷新当前进程 env() 调用
        if (function_exists('app') && app()->bound('Illuminate\Contracts\Console\Kernel')) {
            try {
                Artisan::call('config:clear');
            } catch (\Throwable) {
                // ignore；UI 还会通过 reloadConfig() 重 require config 文件兜底
            }
        }
    }

    /**
     * 写 file 字段：用 PhpFileEditor 改 engine/config/scaffold.php。
     */
    private function applyFileWrites(array $fileWrites): void
    {
        $file = function_exists('config_path') ? config_path('scaffold.php') : null;
        if ($file === null || ! is_file($file)) {
            throw new \RuntimeException('engine/config/scaffold.php 不存在，无法写入');
        }
        $this->phpEditor->setValuesInFile($file, $fileWrites);
    }

    /**
     * 写入完成后让同请求 / 后续请求都拿到新值：
     *   - 刷掉 OPcache 里的 scaffold.php
     *   - 重新 require 进 $this->config
     *   - env 字段的新值覆盖进运行时 config(re-require 时 env() 仍返回进程启动时的旧值,
     *     不覆盖的话保存成功后表单回显旧值、本进程逻辑也继续用旧值,像没存上,2026-06-10 修)。
     *     持久化已落 .env;其他常驻进程(php-fpm / queue)重启后生效。
     */
    private function reloadConfig(array $envConfigSets = []): void
    {
        $file = function_exists('config_path') ? config_path('scaffold.php') : null;
        if ($file && is_file($file)) {
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }
            $fresh = (array) require $file;
            $this->config->set('scaffold', $fresh);
        }

        foreach ($envConfigSets as $path => $val) {
            $this->config->set('scaffold.' . $path, $val);
        }
    }

    /**
     * 把 PHP 值序列化为 .env 行的值（裸字符串或 bool/int/null）。
     */
    private function stringifyForEnv(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        // TYPE_LIST(如 route.middleware → SCAFFOLD_MIDDLEWARE)castValueForField 返回数组,
        // 必须 join 成逗号串再写 .env —— 否则 (string)$array = "Array" 把 env 写坏
        // (config.php 读时用 explode(',', env(...)) 正好对称,2026-06-09 修)。
        if (is_array($value)) {
            return implode(',', $value);
        }

        return (string) $value;
    }

    /**
     * 把用户表单值转换成 PHP 类型（input 全是 string，需要按字段 type 强转）。
     */
    private function castValueForField(mixed $raw, string $type): mixed
    {
        if ($raw === null) {
            return null;
        }
        switch ($type) {
            case self::TYPE_BOOL:
                // 标量字段被喂数组(HTTP 参数可构造)→ 按"没动"处理,不强转
                if (is_array($raw)) {
                    return null;
                }

                return filter_var($raw, FILTER_VALIDATE_BOOL);
            case self::TYPE_INT:
                // 空串/非数字 → null(跳过不写)。原 (int)'' = 0 把"清空想恢复默认"变成写入 0:
                // auth.ttl_minutes=0 全员登不进(改配置的页面在登录后面 → 锁死),
                // proxy.timeout=0 代理无限挂起(2026-06-10 修)。显式输入的数字照常通过。
                if (! is_numeric(is_string($raw) ? trim($raw) : $raw)) {
                    return null;
                }

                return (int) $raw;
            case self::TYPE_LIST:
                if (is_array($raw)) {
                    // 嵌套数组元素剔掉(trim(array) 在 PHP8 抛 TypeError)
                    $raw = array_filter($raw, 'is_scalar');

                    return array_values(array_filter(array_map(fn ($s) => trim((string) $s), $raw), fn ($s) => $s !== ''));
                }
                $s = trim((string) $raw);
                if ($s === '') {
                    return [];
                }

                return array_values(array_filter(array_map('trim', explode(',', $s)), fn ($v) => $v !== ''));
            case self::TYPE_MAP:
                // 表单结构：[ ['k'=>..., 'v'=>...], ..., '__present' => '1' ]
                // 还原为关联数组；空 key 跳过；同 key 后者覆盖前者
                // 返回 null = "用户根本没传任何行，按未变更处理"——避免 UI 渲染失败时
                // 一个空 form 把已有 map 整个清掉。
                if (! is_array($raw)) {
                    return null;
                }
                $out       = [];
                $hasAnyRow = false;
                foreach ($raw as $idx => $pair) {
                    if ($idx === '__present' || ! is_array($pair)) {
                        continue;
                    }
                    $hasAnyRow = true;
                    $k         = trim((string) ($pair['k'] ?? ''));
                    $v         = trim((string) ($pair['v'] ?? ''));
                    if ($k === '') {
                        continue;
                    }
                    $out[$k] = $v;
                }

                // 没传任何 row（只有 __present）→ null，写入路径会跳过；
                // 要真清空 map 请直接编辑源文件
                return $hasAnyRow ? $out : null;
            case self::TYPE_TEXT:
            case self::TYPE_STRING:
            default:
                // 标量字段被喂数组 → 按"没动"处理((string)$array 会 ErrorException)
                if (is_array($raw)) {
                    return null;
                }

                return (string) $raw;
        }
    }

    /**
     * plan-40 §五 F1 长度 cap:string/text 整值、list/map 的每个 key/值 ≤ 2000 字符。
     * 返回首个超长项的描述(用于 skipped 提示),无超长返回 null。
     */
    private function findOverLongValue(mixed $value): ?string
    {
        $cap = 2000;
        if (is_string($value)) {
            return mb_strlen($value) > $cap ? mb_substr($value, 0, 30) . '…' : null;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (is_string($k) && mb_strlen($k) > $cap) {
                    return mb_substr($k, 0, 30) . '…(key)';
                }
                if (is_string($v) && mb_strlen($v) > $cap) {
                    return (is_string($k) ? $k . ' → ' : '') . mb_substr($v, 0, 30) . '…';
                }
            }
        }

        return null;
    }
}
