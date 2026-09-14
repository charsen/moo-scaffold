<?php declare(strict_types=1);

/*
 * @Description: 只读体检：找出「Resource 原样透出的 json 列」里真实数据带**整数键映射**的地方。
 *
 * 用法：
 *   php artisan moo:audit:resource-keys                 # 扫已装私包 + 宿主自身
 *   php artisan moo:audit:resource-keys --package=moo-mini-app
 *   php artisan moo:audit:resource-keys --path=/abs/src  # 额外/替代扫描根（可重复）
 *   php artisan moo:audit:resource-keys --limit=500 --json
 *   php artisan moo:audit:resource-keys --fail-on-danger  # 命中即退出码 1（可当 CI 闸门）
 *
 * 为什么需要它：Laravel 的 `ConditionallyLoadsAttributes::removeMissingValues()` 会递归把
 * 「键全为数字」的嵌套数组 `array_values()` 重排 —— 列表没事，但 `{1:'正常',2:'停用'}`、
 * `{4:12,6:3}` 这类**整数键映射**会丢键：前端按值取标签错位，读回来再保存就把键永久写坏。
 * 触发条件有两个（缺一不可）：① 该映射经 Resource 出参；② 有人按值取标签或读回再保存。
 * 本命令只做**判定与定位**，不改数据、不改代码。
 *
 * 判定口径：抽样该列真实数据（默认每列 200 行），递归找「键全数字且非 0..n-1」的层级。
 * 该 Resource 已声明 `$preserveKeys` 的，标为「已声明」不再计危险（大小写与 static 写法都会被识别并提示）。
 */

namespace Mooeen\Scaffold\Command;

use Illuminate\Support\Str;
use Mooeen\Scaffold\Support\NumericKeyMapDetector;

class AuditResourceKeysCommand extends Command
{
    /** 只读，任何环境可跑（含生产核对）。 */
    protected bool $requiresLocalEnvironment = false;

    protected string $title = 'Resource Numeric-Key Map Audit';

    protected $name = 'moo:audit:resource-keys';

    protected $description = 'Audit numeric-keyed maps exposed by API resources (read-only)';

    protected $signature = 'moo:audit:resource-keys
        {--path=* : 扫描根（每个根下找 Http/Resources 与 Models；默认扫 vendor/mooeen/*/src 与宿主 app）}
        {--package= : 只扫某个私包（vendor 目录名，如 moo-mini-app）}
        {--limit=200 : 每列抽样行数上限}
        {--json : 输出 JSON（便于脚本消费）}
        {--fail-on-danger : 命中危险列时退出码 1}';

    /** json / array 类 cast 的声明形态：这些列才可能带映射。 */
    private const JSON_CASTS = '(json|array|AsArrayObject|AsCollection|encrypted:array|object)';

    public function handle(): int
    {
        $json = (bool) $this->option('json');

        if (! $json) {
            $this->showTitle();
        }

        $limit = max((int) $this->option('limit'), 1);
        $roots = $this->resolveRoots();

        if ($roots === []) {
            if ($json) {
                $this->line((string) json_encode(['roots' => [], 'rows' => [], 'error' => 'no_scan_root'], JSON_UNESCAPED_UNICODE));
            } else {
                $this->warn('没找到可扫描的目录（既没有 vendor/mooeen/*/src，也没有带 Http/Resources 的宿主目录）。');
                $this->line('<fg=gray>可用 --path=/abs/path/to/src 指定扫描根（该根下应有 Http/Resources 与/或 Models）。</>');
            }

            return self::SUCCESS;
        }

        $jsonColumns = $this->collectJsonColumns($roots);
        $candidates  = $this->collectCandidates($roots, $jsonColumns);

        if ($candidates === []) {
            if ($json) {
                $this->line((string) json_encode(['roots' => $roots, 'rows' => []], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            } else {
                $this->info('✓ 没有发现「Resource 透出 json 列」的组合 —— 无需关注本类问题。');
            }

            return self::SUCCESS;
        }

        $report      = [];
        $dangerCount = 0;

        foreach ($candidates as $candidate) {
            $row = $this->inspect($candidate, $limit);
            if ($row['verdict'] === 'danger') {
                $dangerCount++;
            }
            $report[] = $row;
        }

        if ($json) {
            $this->line((string) json_encode(['roots' => $roots, 'rows' => $report], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->printReport($report, $limit);
        }

        if ($dangerCount > 0) {
            if (! $json) {
                $this->warn("⚠ 命中 {$dangerCount} 处「整数键映射经 Resource 出参」，请按上面的建议逐处确认。");
            }

            return $this->option('fail-on-danger') ? self::FAILURE : self::SUCCESS;
        }

        if (! $json) {
            $this->info('✓ 未发现危险列（抽样范围内）。');
        }

        return self::SUCCESS;
    }

    /**
     * 扫描根：默认已装私包（vendor/mooeen 下每个包的 src）+ 宿主自身；`--path` 显式给出时只扫它。
     *
     * @return list<string>
     */
    private function resolveRoots(): array
    {
        $paths = array_values(array_filter((array) $this->option('path'), static fn ($p): bool => is_string($p) && $p !== ''));
        if ($paths !== []) {
            return array_values(array_filter($paths, 'is_dir'));
        }

        // 私包装在 `vendor/<vendor>/<包>/src`（本生态是 `vendor/charsen/*`，path 仓是**符号链接**）。
        // 优先按宿主 `composer.json` 的 `extra.moo-private-packages` 精确定位（不夹带第三方包）；
        // 读不到清单时退化成扫 `vendor/*/*/src`，只收真带 Http/Resources 或 Models 的目录。
        $roots = $this->privatePackageRoots();
        if ($roots === []) {
            foreach ((array) glob(base_path('vendor/*/*/src'), GLOB_ONLYDIR) as $dir) {
                if (is_dir($dir . '/Http/Resources') || is_dir($dir . '/Models')) {
                    $roots[] = (string) $dir;
                }
            }
        }

        $only = (string) ($this->option('package') ?? '');
        if ($only !== '') {
            $roots = array_values(array_filter($roots, static fn (string $dir): bool => basename(dirname($dir)) === $only));
        }

        if (is_dir(app_path())) {
            $roots[] = app_path();
        }

        return array_values(array_unique($roots));
    }

    /**
     * 宿主 `composer.json` 的 `extra.moo-private-packages` 里的私包根目录（`vendor/<name>/src`）。
     *
     * 读不到清单 / 目录不存在时返回空数组，由调用方退回「扫 vendor」。
     *
     * @return list<string>
     */
    private function privatePackageRoots(): array
    {
        $manifest = base_path('composer.json');
        if (! is_file($manifest)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($manifest), true);
        if (! is_array($data)) {
            return [];
        }

        $roots = [];
        foreach ((array) ($data['extra']['moo-private-packages'] ?? []) as $entry) {
            $name = is_array($entry) ? (string) ($entry['name'] ?? '') : (string) $entry;
            if ($name === '') {
                continue;
            }
            $dir = base_path('vendor/' . $name . '/src');
            if (is_dir($dir)) {
                $roots[] = $dir;
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * 收集「json/array cast 列」：列名 => [{class, table}]。
     *
     * @param list<string> $roots
     *
     * @return array<string, list<array{class: string, table: string}>>
     */
    private function collectJsonColumns(array $roots): array
    {
        $columns = [];

        foreach ($roots as $root) {
            $modelsDir = rtrim($root, '/') . '/Models';
            if (! is_dir($modelsDir)) {
                continue;
            }
            foreach ($this->phpFiles($modelsDir) as $file) {
                $meta = $this->classMeta($file);
                if ($meta === null) {
                    continue;
                }
                $source = (string) file_get_contents((string) $file);
                if (! preg_match_all("/'([a-z0-9_]+)'\s*=>\s*'" . self::JSON_CASTS . "'/i", $source, $matches)) {
                    continue;
                }
                $table = $this->tableOf($source, $meta['class']);
                foreach (array_unique($matches[1]) as $column) {
                    $columns[$column][] = ['class' => $meta['class'], 'table' => $table];
                }
            }
        }

        return $columns;
    }

    /**
     * 组合：Resource 里透出的列 ∩ json 列。
     *
     * @param list<string>                                             $roots
     * @param array<string, list<array{class: string, table: string}>> $jsonColumns
     *
     * @return list<array{package: string, resource: string, file: string, column: string, models: list<array{class: string, table: string}>, declares: bool, staticDeclare: bool}>
     */
    private function collectCandidates(array $roots, array $jsonColumns): array
    {
        $candidates = [];

        foreach ($roots as $root) {
            $resourcesDir = rtrim($root, '/') . '/Http/Resources';
            if (! is_dir($resourcesDir)) {
                continue;
            }
            $package = $this->packageOf($root);

            foreach ($this->phpFiles($resourcesDir) as $file) {
                $meta    = $this->classMeta($file);
                $source  = (string) file_get_contents($file);
                $exposed = $this->exposedColumns($source);
                if ($meta === null || $exposed === []) {
                    continue;
                }

                $declares      = (bool) preg_match('/\$preserveKeys\s*=/', $source);
                $staticDeclare = (bool) preg_match('/public\s+static\s+(bool\s+)?\$preserveKeys/', $source);

                foreach (array_intersect($exposed, array_keys($jsonColumns)) as $column) {
                    $candidates[] = [
                        'package'       => $package,
                        'resource'      => $meta['class'],
                        'file'          => $file,
                        'column'        => (string) $column,
                        'models'        => $jsonColumns[$column],
                        'declares'      => $declares,
                        'staticDeclare' => $staticDeclare,
                    ];
                }
            }
        }

        return $candidates;
    }

    /**
     * 抽样一个候选列并给出判定。
     *
     * @param array{package: string, resource: string, file: string, column: string, models: list<array{class: string, table: string}>, declares: bool, staticDeclare: bool} $candidate
     *
     * @return array<string, mixed>
     */
    private function inspect(array $candidate, int $limit): array
    {
        $row = [
            'package'  => $candidate['package'],
            'resource' => $candidate['resource'],
            'file'     => $candidate['file'],
            'column'   => $candidate['column'],
            'declares' => $candidate['declares'],
            // 报告表格要用它区分「实例属性（对）」与「static（会报错）」两种声明
            'staticDeclare' => $candidate['staticDeclare'],
            'sampled'       => 0,
            'dangerRows'    => 0,
            'paths'         => [],
            'verdict'       => 'skip',
            'note'          => '',
        ];

        if ($candidate['declares']) {
            $row['verdict'] = 'declared';
            $row['note']    = $candidate['staticDeclare']
                ? '声明了 $preserveKeys，但写成 static —— 实例访问会落到 JsonResource::__get() 报错，应改为实例属性'
                : '已声明 $preserveKeys（实例属性），出参保键';
        }

        $class = $candidate['models'][0]['class'] ?? '';
        if (! class_exists($class)) {
            $row['note'] = $row['note'] !== '' ? $row['note'] : "模型不可加载（{$class}），跳过抽样";

            return $row;
        }

        try {
            $values = $class::query()->whereNotNull($candidate['column'])->limit($limit)->pluck($candidate['column']);
        } catch (\Throwable $e) {
            $row['note'] = '抽样失败：' . Str::limit($e->getMessage(), 80);

            return $row;
        }

        $paths = [];
        foreach ($values as $value) {
            if (! is_array($value)) {
                continue;
            }
            $row['sampled']++;
            $found = NumericKeyMapDetector::dangerPaths($value);
            if ($found !== []) {
                $row['dangerRows']++;
                foreach ($found as $path) {
                    $paths[$path] = true;
                }
            }
        }

        $row['paths'] = array_keys($paths);
        if ($row['declares']) {
            return $row;   // 已声明保键：即便数据是整数键映射也不危险
        }
        if ($row['dangerRows'] > 0) {
            $row['verdict'] = 'danger';
            $row['note']    = '数据里存在整数键映射：经 Resource 出参会丢键（前端按值取标签错位；读回再保存会写坏）';

            return $row;
        }

        $row['verdict'] = 'ok';
        $row['note']    = $row['sampled'] > 0 ? '抽样范围内都是列表或字符串键映射' : '该列在库中无数据，未抽样到';

        return $row;
    }

    /**
     * @param list<array<string, mixed>> $report
     */
    private function printReport(array $report, int $limit): void
    {
        $this->line('<fg=gray>扫描根：' . implode('、', $this->resolveRoots()) . '</>');
        $this->line('<fg=gray>判定：递归找「键全为数字且非 0..n-1」的层级；每列抽样上限 ' . $limit . ' 行。</>');
        $this->line('');

        $this->table(
            ['包', '资源', '列', 'preserveKeys', '抽样', '危险行', '判定', '键路径'],
            array_map(static fn (array $row): array => [
                $row['package'],
                $row['resource'],
                $row['column'],
                $row['declares'] ? ($row['staticDeclare'] ? 'static(错)' : '是') : '—',
                (string) $row['sampled'],
                (string) $row['dangerRows'],
                match ($row['verdict']) {
                    'danger'   => '<fg=red>危险</>',
                    'declared' => '<fg=green>已保键</>',
                    'ok'       => '<fg=green>安全</>',
                    default    => '<fg=yellow>跳过</>',
                },
                $row['paths'] === [] ? ($row['note'] !== '' ? Str::limit($row['note'], 40) : '—') : implode('、', $row['paths']),
            ], $report)
        );

        $danger = array_values(array_filter($report, static fn (array $row): bool => $row['verdict'] === 'danger'));
        if ($danger === []) {
            return;
        }

        $this->line('');
        $this->line('<fg=yellow>需要确认的列（危险 ≠ 一定出问题，要同时满足「按值取标签」或「读回再保存」）：</>');
        foreach ($danger as $row) {
            $this->line("  • <fg=white>{$row['package']}</> {$row['resource']}::{$row['column']}"
                . "  <fg=gray>危险行 {$row['dangerRows']}/{$row['sampled']}，路径 " . implode('、', $row['paths']) . '</>');
        }
        $this->line('');
        $this->line('<fg=gray>两种收口方式（按成本选）：</>');
        $this->line('<fg=gray>  ① 该 Resource 是手写文件 → 加 `public $preserveKeys = true;`（必须是实例属性；生成物上加会被 -f 覆盖）；</>');
        $this->line('<fg=gray>  ② 改形状：出参不要给整数键映射，转成 `[{key|value, label}]`（前端与校验同口径，最不容易再踩）。</>');
    }

    /**
     * 递归列出目录下的 .php 文件（`glob('**')` 在 PHP 里不递归，不能用来扫子目录）。
     *
     * @return list<string> 按路径排序，保证输出稳定
     */
    private function phpFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * 从文件里取 namespace + class。
     *
     * @return array{class: string, basename: string}|null
     */
    private function classMeta(string $file): ?array
    {
        $source = (string) file_get_contents($file);
        if (! preg_match('/^namespace\s+([^;]+);/m', $source, $ns)) {
            return null;
        }
        if (! preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $source, $class)) {
            return null;
        }

        return ['class' => trim($ns[1]) . '\\' . $class[1], 'basename' => $class[1]];
    }

    /**
     * 表名：显式 `$table` 优先，否则按 Eloquent 约定推导。
     */
    private function tableOf(string $source, string $fqcn): string
    {
        if (preg_match("/protected\s+\\\$table\s*=\s*'([^']+)'/", $source, $m)) {
            return $m[1];
        }

        return Str::snake(Str::pluralStudly(class_basename($fqcn)));
    }

    /**
     * Resource 里透出的列名（`whenHas('x')` 与 `$this->x`）。
     *
     * @return list<string>
     */
    private function exposedColumns(string $source): array
    {
        $columns = [];

        if (preg_match_all("/whenHas\(\s*'([a-z0-9_]+)'/i", $source, $m)) {
            $columns = [...$columns, ...$m[1]];
        }
        if (preg_match_all('/\$this->([a-z0-9_]+)\b/i', $source, $m)) {
            // 过滤 JsonResource 自身的方法/属性：这些不是列名
            $reserved = ['resource', 'additional', 'preserveKeys', 'collects', 'wrap', 'with', 'when', 'whenHas', 'toArray', 'resolve', 'response'];
            $columns  = [...$columns, ...array_values(array_diff($m[1], $reserved))];
        }

        return array_values(array_unique($columns));
    }

    /**
     * 扫描根归属的包名：`<...>/vendor/mooeen/<pkg>/src` → `<pkg>`；宿主自身 → `host`。
     */
    private function packageOf(string $root): string
    {
        $parts = explode('/', trim($root, '/'));

        // vendor/<vendor>/<包>/src 形态：取倒数第二段（包名）
        $srcIndex = array_search('src', array_reverse($parts, true), true);
        if ($srcIndex !== false && isset($parts[$srcIndex - 1]) && in_array('vendor', $parts, true)) {
            return (string) $parts[$srcIndex - 1];
        }

        return str_contains($root, 'vendor') ? 'vendor' : 'host';
    }
}
