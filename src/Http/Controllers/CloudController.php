<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Controllers;

use Composer\InstalledVersions;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Mooeen\Monitor\Cloud\CloudSync;
use Mooeen\Monitor\MonitorProvider;
use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Mooeen\Monitor\Recorder\SqlSlowRecorder;
use Mooeen\Scaffold\Utility;
use Throwable;

/**
 * 云端汇聚控制台。
 *
 *   - index(GET /scaffold/cloud):本地两类缓冲(运行时错误 / 慢 SQL)的状态总览
 *     + 云端接入状态 + 云端控制台入口。只读,任何环境可看。
 *   - push(POST /scaffold/cloud/push):手动触发 moo:cloud:push(增量 + 回收)。
 *     是写类动作,经 EnforceScaffoldWritable 锁 —— 生产 / 只读拒绝,**适用于本地**。
 *
 * 推送逻辑与 CloudPushCommand 同骨架(CloudSync::sync + pruneLocal),不在请求链路常驻,
 * 仅 user 显式点按时跑一次。
 */
class CloudController extends Controller
{
    public function __construct(
        Utility $utility,
        Filesystem $filesystem,
        private readonly RuntimeErrorRecorder $runtimeRecorder,
        private readonly SqlSlowRecorder $sqlSlowRecorder,
    ) {
        parent::__construct($utility, $filesystem);
    }

    public function index(Request $request): View
    {
        $cfg     = (array) config('moo-monitor.cloud', []);
        $enabled = (bool) ($cfg['enabled'] ?? false);
        $baseUrl = rtrim((string) ($cfg['base_url'] ?? ''), '/');
        $token   = (string) ($cfg['token'] ?? '');

        $configured = $enabled && $baseUrl !== '' && $token !== '';

        $sync    = new CloudSync;
        $cursors = $sync->cursors();

        // 本地两类缓冲:计数(按桶 glob)+ 上次同步游标
        $buffers = [
            'runtimes' => $this->bufferStatus($this->runtimeRecorder, '运行时错误', 'runtimes', 'debug', $cursors['runtimes'] ?? null),
            'slow_sql' => $this->bufferStatus($this->sqlSlowRecorder, '慢 SQL', 'sql-slows', 'protocol', $cursors['slow_sql'] ?? null),
        ];

        // 待推送条数(dry-run,只在已接入时有意义)
        if ($configured) {
            foreach (['runtimes', 'slow_sql'] as $t) {
                $r                      = $sync->sync($t, all: false, dryRun: true);
                $buffers[$t]['pending'] = ($r['ok'] && ! $r['skipped']) ? (int) $r['changed'] : null;
            }
        }

        return $this->view('cloud.index', [
            'enabled'       => $enabled,
            'base_url'      => $baseUrl,
            'token_masked'  => $this->maskToken($token),
            'configured'    => $configured,
            'retention'     => (int) ($cfg['local_retention_days'] ?? 7),
            'schedule'      => (bool) ($cfg['schedule'] ?? true),
            'config_rows'   => $this->configRows($cfg, $baseUrl, $token),
            'version_info'  => $this->versionInfo($cfg, $baseUrl),
            'buffers'       => $buffers,
            'is_prod'       => function_exists('app') && app()->environment('production'),
            'is_readonly'   => (bool) config('scaffold.config_ui.readonly', false),
            'flash_message' => $request->hasSession() ? $request->session()->pull('flash_message') : null,
            'flash_error'   => $request->hasSession() ? $request->session()->pull('flash_error') : null,
        ]);
    }

    /**
     * 接入配置明细(对标 config 页:label + key + 值 + env 来源)。
     *
     * @return list<array{label:string,key:string,value:string,tone:?string,env:?string,mono:bool}>
     */
    private function configRows(array $cfg, string $baseUrl, string $token): array
    {
        $onOff = static fn (bool $v): array => ['value' => $v ? '开' : '关', 'tone' => $v ? 'on' : 'off'];
        $push  = (array) ($cfg['push'] ?? []);

        $rows = [
            ['label' => '启用',           'key' => 'enabled',              'env' => 'MOO_MONITOR_CLOUD_ENABLED'] + $onOff((bool) ($cfg['enabled'] ?? false)),
            ['label' => '云端地址',       'key' => 'base_url',             'env' => 'MOO_MONITOR_CLOUD_URL',                 'value' => $baseUrl !== '' ? $baseUrl : '— 未配',                 'tone' => $baseUrl !== '' ? null : 'off'],
            ['label' => 'Token',          'key' => 'token',                'env' => 'MOO_MONITOR_CLOUD_TOKEN',               'value' => $token !== '' ? $this->maskToken($token) : '— 未配', 'tone' => $token !== '' ? null : 'off', 'mono' => true],
            ['label' => '单次超时',       'key' => 'timeout',              'env' => 'MOO_MONITOR_CLOUD_TIMEOUT',             'value' => ((int) ($cfg['timeout'] ?? 5)) . ' s'],
            ['label' => '每批条数',       'key' => 'batch',                'env' => 'MOO_MONITOR_CLOUD_BATCH',               'value' => (string) (int) ($cfg['batch'] ?? 100)],
            ['label' => 'TLS 校验',       'key' => 'verify',               'env' => 'MOO_MONITOR_CLOUD_VERIFY']        + $onOff((bool) ($cfg['verify'] ?? true)),
            ['label' => '推送 · 运行时',  'key' => 'push.runtimes',        'env' => 'MOO_MONITOR_CLOUD_PUSH_RUNTIMES'] + $onOff((bool) ($push['runtimes'] ?? true)),
            ['label' => '推送 · 慢 SQL',  'key' => 'push.slow_sql',        'env' => 'MOO_MONITOR_CLOUD_PUSH_SLOW_SQL'] + $onOff((bool) ($push['slow_sql'] ?? true)),
            ['label' => '自动调度',       'key' => 'schedule',             'env' => 'MOO_MONITOR_CLOUD_SCHEDULE']      + $onOff((bool) ($cfg['schedule'] ?? true)),
            ['label' => '本地回收阈值',   'key' => 'local_retention_days', 'env' => 'MOO_MONITOR_CLOUD_LOCAL_RETENTION_DAYS', 'value' => ((int) ($cfg['local_retention_days'] ?? 7)) . ' 天'],
        ];

        // 统一补全缺省键(value / tone / env / mono),不覆盖已有
        return array_map(
            static fn (array $r): array => $r + ['value' => '', 'tone' => null, 'env' => null, 'mono' => false],
            $rows
        );
    }

    /**
     * 运行环境与心跳版本信息。只读本地轻量来源,不连 DB / Redis 做实时探测。
     *
     * @return array{cards:list<array{label:string,value:string,hint:string,tone:string,mono:bool}>,switches:list<array{label:string,key:string,value:string,tone:string}>,details:list<array{label:string,key:string,value:string,mono:bool}>}
     */
    private function versionInfo(array $cfg, string $baseUrl): array
    {
        $meta      = $this->heartbeatMeta($cfg);
        $scaffold  = $this->packageVersion('charsen/moo-scaffold', $this->changelogVersion());
        $monitor   = $this->packageVersion('charsen/moo-monitor-laravel', $this->monitorFallbackVersion());
        $laravel   = (string) ($meta['laravel_version'] ?? '—');
        $php       = (string) ($meta['php_version'] ?? PHP_VERSION);
        $appName   = (string) ($meta['app_name'] ?? 'unknown');
        $appEnv    = (string) ($meta['app_env'] ?? 'unknown');
        $git       = $this->gitRef();
        $switchRow = static fn (string $label, string $key, bool $value): array => [
            'label' => $label,
            'key'   => $key,
            'value' => $value ? '开' : '关',
            'tone'  => $value ? 'on' : 'off',
        ];

        return [
            'cards' => [
                ['label' => 'SDK', 'value' => $monitor, 'hint' => (string) ($meta['sdk'] ?? 'moo-monitor-laravel'), 'tone' => 'accent', 'mono' => true],
                ['label' => '宿主框架', 'value' => "Laravel {$laravel}", 'hint' => "PHP {$php}", 'tone' => 'info', 'mono' => true],
                ['label' => '项目环境', 'value' => $appName, 'hint' => $appEnv, 'tone' => $appEnv === 'production' ? 'warning' : 'success', 'mono' => false],
                ['label' => 'Scaffold', 'value' => $scaffold, 'hint' => $git !== '—' ? "Git {$git}" : 'Git 未识别', 'tone' => 'neutral', 'mono' => true],
            ],
            'switches' => [
                $switchRow('Cloud', 'cloud_enabled', (bool) ($meta['cloud_enabled'] ?? false)),
                $switchRow('Runtime 采集', 'runtime_enabled', (bool) ($meta['runtime_enabled'] ?? true)),
                $switchRow('慢 SQL 采集', 'slow_sql_enabled', (bool) ($meta['slow_sql_enabled'] ?? false)),
                $switchRow('Runtime 推送', 'push_runtimes', (bool) ($meta['push_runtimes'] ?? true)),
                $switchRow('慢 SQL 推送', 'push_slow_sql', (bool) ($meta['push_slow_sql'] ?? true)),
                $switchRow('自动调度', 'schedule', (bool) ($meta['schedule'] ?? true)),
            ],
            'details' => [
                ['label' => '云端地址', 'key' => 'base_url', 'value' => $baseUrl !== '' ? $baseUrl : '— 未配', 'mono' => true],
                ['label' => '数据库', 'key' => 'database.default', 'value' => $this->databaseDriver(), 'mono' => true],
                ['label' => 'Redis', 'key' => 'database.redis.client', 'value' => $this->redisDriver(), 'mono' => true],
                ['label' => '队列', 'key' => 'queue.default', 'value' => $this->configString('queue.default'), 'mono' => true],
                ['label' => '缓存', 'key' => 'cache.default', 'value' => $this->configString('cache.default'), 'mono' => true],
                ['label' => '时区', 'key' => 'app.timezone', 'value' => $this->configString('app.timezone'), 'mono' => true],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function heartbeatMeta(array $cfg): array
    {
        $runtime = (array) config('moo-monitor.runtime', []);
        $slowSql = (array) config('moo-monitor.sql_slow', []);

        return [
            'sdk'              => 'moo-monitor-laravel',
            'sdk_version'      => $this->monitorVersion(),
            'php_version'      => PHP_VERSION,
            'laravel_version'  => function_exists('app') ? (string) app()->version() : '',
            'app_env'          => (string) config('app.env', 'unknown'),
            'app_name'         => (string) config('app.name', 'unknown'),
            'cloud_enabled'    => (bool) ($cfg['enabled'] ?? false),
            'runtime_enabled'  => (bool) ($runtime['enabled'] ?? true),
            'slow_sql_enabled' => (bool) ($slowSql['enabled'] ?? false),
            'push_runtimes'    => (bool) ($cfg['push']['runtimes'] ?? true),
            'push_slow_sql'    => (bool) ($cfg['push']['slow_sql'] ?? true),
            'schedule'         => (bool) ($cfg['schedule'] ?? true),
        ];
    }

    private function packageVersion(string $package, ?string $fallback = null): string
    {
        $pretty = null;
        $ref    = null;

        try {
            if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package)) {
                $pretty = InstalledVersions::getPrettyVersion($package);
                $ref    = InstalledVersions::getReference($package);
            }
        } catch (Throwable) {
            // fallback below
        }

        $pretty = is_string($pretty) ? ltrim($pretty, 'v') : '';
        $base   = $pretty !== '' ? $pretty : (string) ($fallback ?? '');

        if ($base === '') {
            return '—';
        }

        if (str_starts_with($base, 'dev-') && $fallback !== null && $fallback !== '' && $fallback !== $base) {
            $base = ltrim($fallback, 'v') . ' · ' . $base;
        }

        if (is_string($ref) && preg_match('/^[0-9a-f]{40}$/i', $ref) === 1) {
            return $base . ' @ ' . substr($ref, 0, 7);
        }

        return $base;
    }

    private function monitorVersion(): string
    {
        try {
            if (method_exists(MonitorProvider::class, 'version')) {
                return MonitorProvider::version();
            }
            if (defined(MonitorProvider::class . '::VERSION')) {
                return (string) constant(MonitorProvider::class . '::VERSION');
            }
        } catch (Throwable) {
            // fallback below
        }

        return $this->packageVersion('charsen/moo-monitor-laravel');
    }

    private function monitorFallbackVersion(): ?string
    {
        try {
            if (defined(MonitorProvider::class . '::VERSION')) {
                return (string) constant(MonitorProvider::class . '::VERSION');
            }
            if (method_exists(MonitorProvider::class, 'version')) {
                return MonitorProvider::version();
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function changelogVersion(): ?string
    {
        $path = dirname(__DIR__, 3) . '/CHANGELOG.md';
        $head = is_file($path) ? (string) @file_get_contents($path, false, null, 0, 512) : '';
        if (preg_match('/^## \[([0-9]+\.[0-9]+\.[0-9]+)\]/m', $head, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function gitRef(): string
    {
        $base = function_exists('base_path') ? base_path() : getcwd();
        if (! is_string($base) || $base === '') {
            return '—';
        }

        $git    = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.git';
        $gitDir = null;
        if (is_dir($git)) {
            $gitDir = $git;
        } elseif (is_file($git)) {
            $content = trim((string) @file_get_contents($git));
            if (str_starts_with($content, 'gitdir:')) {
                $dir    = trim(substr($content, 7));
                $gitDir = str_starts_with($dir, DIRECTORY_SEPARATOR)
                    ? $dir
                    : rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $dir;
            }
        }

        if (! is_string($gitDir) || ! is_file($gitDir . DIRECTORY_SEPARATOR . 'HEAD')) {
            return '—';
        }

        $head = trim((string) @file_get_contents($gitDir . DIRECTORY_SEPARATOR . 'HEAD'));
        if ($head === '') {
            return '—';
        }

        if (! str_starts_with($head, 'ref:')) {
            return substr($head, 0, 7);
        }

        $ref  = trim(substr($head, 4));
        $hash = $this->gitHashForRef($gitDir, $ref);
        if ($hash === null) {
            return basename($ref);
        }

        return basename($ref) . ' @ ' . substr($hash, 0, 7);
    }

    private function gitHashForRef(string $gitDir, string $ref): ?string
    {
        $file = rtrim($gitDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $ref);
        if (is_file($file)) {
            $hash = trim((string) @file_get_contents($file));
            if (preg_match('/^[0-9a-f]{40}$/i', $hash) === 1) {
                return $hash;
            }
        }

        $packed = rtrim($gitDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'packed-refs';
        if (! is_file($packed)) {
            return null;
        }
        foreach (file($packed, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if ($line === '' || $line[0] === '#' || $line[0] === '^') {
                continue;
            }
            [$hash, $name] = array_pad(explode(' ', $line, 2), 2, '');
            if ($name === $ref && preg_match('/^[0-9a-f]{40}$/i', $hash) === 1) {
                return $hash;
            }
        }

        return null;
    }

    private function databaseDriver(): string
    {
        $default = (string) config('database.default', '');
        if ($default === '') {
            return '—';
        }

        $connection = (array) config("database.connections.{$default}", []);
        $driver     = (string) ($connection['driver'] ?? '');
        if ($driver === '' || $driver === $default) {
            return $default;
        }

        return $default . ' / ' . $driver;
    }

    private function redisDriver(): string
    {
        $client = (string) config('database.redis.client', '');
        if ($client === '') {
            $client = extension_loaded('redis') ? 'phpredis' : '';
        }

        if ($client === 'phpredis') {
            $version = phpversion('redis');

            return is_string($version) && $version !== '' ? "phpredis {$version}" : 'phpredis';
        }

        if ($client === 'predis') {
            return 'predis ' . $this->packageVersion('predis/predis');
        }

        return $client !== '' ? $client : '—';
    }

    private function configString(string $key): string
    {
        $value = config($key);
        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return '—';
    }

    public function push(Request $request)
    {
        $cfg = (array) config('moo-monitor.cloud', []);
        if (! ($cfg['enabled'] ?? false) || empty($cfg['base_url']) || empty($cfg['token'])) {
            return $this->back($request, false, 'cloud 未启用（MOO_MONITOR_CLOUD_ENABLED），或 MOO_MONITOR_CLOUD_TOKEN 未配置（URL 已有默认值）。');
        }

        $sync      = new CloudSync;
        $retention = (int) ($cfg['local_retention_days'] ?? 7);
        $labels    = ['runtimes' => '运行时错误', 'slow_sql' => '慢 SQL'];
        $pushed    = 0;
        $recycled  = 0;
        $skipped   = 0;
        $failed    = null;
        $failedAt  = null;

        foreach ($sync->types() as $type) {
            $r = $sync->sync($type, all: false, dryRun: false);
            if ($r['skipped']) {
                $skipped++;

                continue;
            }
            if (! $r['ok']) {
                $failed   = $r['error'];
                $failedAt = $labels[$type] ?? $type;
                break;
            }
            $pushed += (int) $r['pushed'];

            // 推送成功后回收本地(临时缓冲);失败即停,不回收未确认上云的。
            $p = $sync->pruneLocal($type, $retention);
            $recycled += $p['purged'] + $p['prunedOpen'];
        }

        // 心跳:与 CloudPushCommand 同语义 —— 真实跑过推送管道就打一拍,云端「推送中断」
        // 哨兵据此判活。原先只有调度命令打,scheduler 没跑、全靠手动推送的 host(本地 dev
        // 常态)天天在推却被云端误报"推送中断"(2026-06-10 修)。best-effort,不影响结果。
        (new \Mooeen\Monitor\Cloud\CloudClient($cfg))->heartbeat();

        if ($failed !== null) {
            // 部分成功的事实不能丢:runtimes 已推完才在 slow_sql 失败时,原文案"推送失败"
            // 让用户以为整体没推(2026-06-10 修)
            $prefix = $pushed > 0 ? "已推送 {$pushed} 条" . ($recycled > 0 ? " · 本地回收 {$recycled} 条" : '') . '；' : '';

            return $this->back($request, false, "{$prefix}{$failedAt} 推送失败：{$failed}");
        }

        if ($skipped > 0 && $pushed === 0 && $recycled === 0) {
            // 两类都被分类型开关跳过时,原文案"已推送 0 条"像成功,实际一条没动
            return $this->back($request, false, '推送被分类型开关跳过（MOO_MONITOR_CLOUD_PUSH_RUNTIMES / SLOW_SQL），没有任何记录被推送。');
        }

        // 推完即清首页云端汇总缓存,面板下次渲染立刻反映最新(否则要等 60s TTL)。
        try {
            cache()->forget(ScaffoldController::CLOUD_SUMMARY_CACHE_KEY);
        } catch (\Throwable) {
            // 缓存不可用不影响推送结果
        }

        $msg = "已推送 {$pushed} 条" . ($recycled > 0 ? " · 本地回收 {$recycled} 条" : '');

        return $this->back($request, true, $msg);
    }

    /** @return array{label:string,dir:string,icon:string,open:int,cursor:?string,pending:?int} */
    private function bufferStatus(RuntimeErrorRecorder|SqlSlowRecorder $recorder, string $label, string $dir, string $icon, ?string $cursor): array
    {
        // 云端化后处置(resolved/deleted)只在 S-Cloud 做,本地这两桶恒空 → 不再统计;
        // 本地只关心 open(缓冲量)+ pending(待推)+ cursor(上次同步)。
        return [
            'label'   => $label,
            'dir'     => $dir,
            'icon'    => $icon,
            'open'    => $recorder->count('open'),
            'cursor'  => $cursor,   // 上次同步水位(meta.updated_at);null = 未推过
            'pending' => null,      // 待推条数,已接入时由 index 填(dry-run)
        ];
    }

    private function maskToken(string $token): string
    {
        $len = strlen($token);
        if ($len === 0) {
            return '';
        }
        if ($len <= 10) {
            return str_repeat('•', $len);
        }

        return substr($token, 0, 6) . '••••••' . substr($token, -4);
    }

    private function back(Request $request, bool $ok, string $message)
    {
        if ($request->ajax() || $request->expectsJson()) {
            return response()->json(['ok' => $ok, 'message' => $message], $ok ? 200 : 422);
        }
        $request->session()->flash($ok ? 'flash_message' : 'flash_error', $message);

        return redirect()->route('cloud.index');
    }
}
