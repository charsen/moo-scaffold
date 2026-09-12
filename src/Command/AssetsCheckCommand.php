<?php declare(strict_types=1);

/*
 * @Description: 检测宿主 `public/vendor/<pkg>` 的已发布副本是否与包内 `public/` 一致。
 *
 * 本轮真实踩到的坑：三个 Host 的前端 JS 陈旧、两个 Host 根本没有副本，只靠人工比 md5
 * 才发现。本命令把这件事变成一条只读体检：
 *   moo:assets:check --root=/path/to/host
 *   moo:assets:check --root=/path/to/host --out=storage/app/assets-check.md
 *
 * 分类报告「缺失 / 内容不一致 / 多余」：
 *   - 缺失 + 内容不一致 = 发布副本落后于包内源码 → 非 0 退出（真正会让前端跑旧 JS）
 *   - 多余 = 发布目录里包内已删除的陈旧残留 → 默认只提示不判失败（vendor:publish
 *     不会删旧文件，各 Host 普遍存在），--strict 时同样算不一致
 *
 * 纯只读：绝不自动 publish；修复命令只做提示
 * （publish-tag 从宿主 manifest 的 extra.moo-private-packages 条目读）。
 */

namespace Mooeen\Scaffold\Command;

use FilesystemIterator;
use Mooeen\Scaffold\Command\Concerns\ResolvesHostPaths;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class AssetsCheckCommand extends Command
{
    use ResolvesHostPaths;

    private const PROFILE_FILES = ['composer.json', 'composer.test.json', 'composer.production.json'];

    protected bool $requiresLocalEnvironment = false;     // 只读,任何环境可跑

    protected string $title = 'Published Assets Check';

    protected $name = 'moo:assets:check';

    protected $description = 'Compare host public/vendor/<pkg> published copy against package public/ (read-only)';

    protected $signature = 'moo:assets:check
        {--root= : Host 仓根目录（默认 base_path() 的上一级）}
        {--package=charsen/moo-scaffold : 要检查的包}
        {--publish-dir= : 已发布目录（默认 <app>/public/vendor/<manifest repo-key>；无条目时用包短名）}
        {--out= : 可选报告文件}
        {--strict : 把「多余」也算作不一致（CI 用）}
        {--publish-command : 额外输出修复用 vendor:publish 命令提示}';

    public function handle(): int
    {
        $this->showTitle();

        $root    = $this->resolveHostRoot();
        $appRoot = $this->resolveAppRoot($root);

        $package = trim((string) ($this->option('package') ?: 'charsen/moo-scaffold'));
        if (! str_contains($package, '/')) {
            $this->console()->error("包名格式应为 vendor/name，收到：{$package}");

            return self::FAILURE;
        }
        $short = substr($package, strpos($package, '/') + 1);

        $sourceDir = $this->resolveSourceDir($appRoot, $package);
        if ($sourceDir === null) {
            $this->console()->error("找不到包内 public/ 源目录（试过 {$appRoot}/vendor/{$package}/public 与本包 public/）");

            return self::FAILURE;
        }

        $publishOption = trim((string) ($this->option('publish-dir') ?? ''));
        // 已发布目录名 = manifest 里的 repo-key（scaffold 发布到 public/vendor/scaffold，
        // 不是 composer 短名 moo-scaffold）；manifest 没有该包条目时才退回包短名。
        $publishName = $this->manifestRepoKey($appRoot, $package) ?? $short;
        $publishDir  = $publishOption !== ''
            ? $this->absolutePath($publishOption, $root)
            : $appRoot . '/public/vendor/' . $publishName;

        $source    = $this->scanTree($sourceDir);
        $published = is_dir($publishDir) ? $this->scanTree($publishDir) : [];

        $missing = array_diff_key($source, $published);
        $extra   = array_diff_key($published, $source);
        $changed = [];
        foreach (array_intersect_key($source, $published) as $relative => $hash) {
            if ($hash !== $published[$relative]) {
                $changed[$relative] = $hash;
            }
        }

        $strict       = (bool) $this->option('strict');
        $inconsistent = $missing !== [] || $changed !== [] || ($strict && $extra !== []);

        $report = $this->buildReport($root, $package, $sourceDir, $publishDir, $source, $published, $missing, $changed, $extra, $strict);

        $out = trim((string) ($this->option('out') ?? ''));
        if ($out !== '') {
            $outPath = $this->absolutePath($out, $root);
            $dir     = dirname($outPath);
            if (! is_dir($dir)) {
                $this->filesystem->makeDirectory($dir, 0755, true);
            }
            $this->filesystem->put($outPath, $report . "\n");
            $this->line("报告已写入 <fg=cyan>{$outPath}</>");
        }

        $detail = sprintf('缺失 %d / 内容不一致 %d / 多余 %d', count($missing), count($changed), count($extra));

        if ($inconsistent) {
            $this->console()->warn("发布副本与包内 public/ 不一致：{$detail}");
        } elseif ($extra !== []) {
            $this->console()->success('已发布副本覆盖包内 public/ 全部文件、内容一致（' . count($source) . ' 个文件；缺失 0 / 内容不一致 0 / 多余 ' . count($extra) . '）。');
            $this->line('<fg=gray>  多余 = 发布副本里包内已删除的陈旧残留，默认不判失败；--strict 会算不一致。</>');
        } else {
            $this->console()->success('发布副本与包内 public/ 一致（' . count($source) . ' 个文件，md5 全等）。');
        }

        $this->printDetail($missing, $changed, $extra, $out === '');

        if ($inconsistent || $this->option('publish-command')) {
            $this->printPublishHint($appRoot, $package);
        }

        return $inconsistent ? self::FAILURE : self::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────
    // 目录扫描与分类
    // ─────────────────────────────────────────────────────────────

    /**
     * 源目录：优先宿主 `vendor/<package>/public`（path symlink 与实体目录都在这里），
     * 本包自身再回退到仓库内 `public/`（脱离宿主直接跑本包时用）。
     */
    protected function resolveSourceDir(string $appRoot, string $package): ?string
    {
        $candidates = [$appRoot . '/vendor/' . $package . '/public'];

        $self = $this->readJsonManifest(dirname(__DIR__, 2) . '/composer.json');
        if (($self['name'] ?? null) === $package) {
            $candidates[] = dirname(__DIR__, 2) . '/public';
        }

        foreach ($candidates as $dir) {
            if (is_dir($dir)) {
                return $dir;
            }
        }

        return null;
    }

    /**
     * 递归扫描成 `相对路径 => md5`。
     *
     * @return array<string, string>
     */
    protected function scanTree(string $dir): array
    {
        $out      = [];
        $prefix   = rtrim($dir, '/') . '/';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $path           = str_replace('\\', '/', $file->getPathname());
            $relative       = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $file->getFilename();
            $out[$relative] = (string) md5_file($file->getPathname());
        }

        ksort($out);

        return $out;
    }

    /**
     * @param array<string, string> $source
     * @param array<string, string> $published
     * @param array<string, string> $missing
     * @param array<string, string> $changed
     * @param array<string, string> $extra
     */
    protected function buildReport(
        string $root,
        string $package,
        string $sourceDir,
        string $publishDir,
        array $source,
        array $published,
        array $missing,
        array $changed,
        array $extra,
        bool $strict,
    ): string {
        $inconsistent = $missing !== [] || $changed !== [] || ($strict && $extra !== []);

        $lines = [
            '# moo:assets:check 报告',
            '',
            '- 包：' . $package,
            '- 宿主根：' . $root,
            '- 源目录：' . $sourceDir,
            '- 发布目录：' . $publishDir . (is_dir($publishDir) ? '' : '（不存在）'),
            '- 文件数：源 ' . count($source) . ' / 发布 ' . count($published),
            '- 结果：' . ($inconsistent ? '不一致' : '一致')
                . '（缺失 ' . count($missing) . ' / 内容不一致 ' . count($changed) . ' / 多余 ' . count($extra) . '）',
            '- mode：' . ($strict ? 'strict（多余也判失败）' : 'default（多余仅提示）'),
            '',
        ];

        $lines = array_merge($lines, $this->reportSection('缺失（包内有、发布副本没有）', array_keys($missing)));

        $changedLines = [];
        foreach ($changed as $relative => $hash) {
            $changedLines[] = $relative . '  md5: 源=' . $hash . ' 发布=' . ($published[$relative] ?? '');
        }
        $lines = array_merge($lines, $this->reportSection('内容不一致（md5 不同）', $changedLines));

        $lines = array_merge($lines, $this->reportSection('多余（发布副本有、包内没有）', array_keys($extra)));

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $items
     *
     * @return list<string>
     */
    protected function reportSection(string $title, array $items): array
    {
        if ($items === []) {
            return [];
        }

        $lines = ['## ' . $title, ''];
        foreach ($items as $item) {
            $lines[] = '- ' . $item;
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * 控制台明细：默认形态只打摘要，给了 --out 才把完整分类清单也打出来。
     *
     * @param array<string, string> $missing
     * @param array<string, string> $changed
     * @param array<string, string> $extra
     */
    protected function printDetail(array $missing, array $changed, array $extra, bool $summaryOnly): void
    {
        if ($summaryOnly) {
            if ($missing !== [] || $changed !== [] || $extra !== []) {
                $this->line('<fg=gray>  （本模式只打印摘要；完整分类清单请加 --out=<报告文件>）</>');
            }

            return;
        }

        foreach ([
            '缺失'       => array_keys($missing),
            '内容不一致' => array_keys($changed),
            '多余'       => array_keys($extra),
        ] as $label => $items) {
            foreach ($items as $item) {
                $this->line("  <fg=yellow>{$label}</> {$item}");
            }
        }
    }

    /**
     * 修复提示：publish-tag 从宿主三份 manifest 的 extra.moo-private-packages 条目读。
     */
    protected function printPublishHint(string $appRoot, string $package): void
    {
        $entry = $this->manifestEntry($appRoot, $package);

        if ($entry === null) {
            $this->console()->warn("包 {$package} 未纳入宿主 manifest（extra.moo-private-packages），无法据此推导 publish-tag。");

            return;
        }

        $tag = $entry['publish-tag'] ?? null;
        if (is_string($tag) && $tag !== '') {
            $this->line("<fg=gray>  修复：php artisan vendor:publish --tag={$tag} --force</>");

            return;
        }

        $this->console()->warn("包 {$package} 在 manifest 里但没有 publish-tag，需先补 publish-tag 才能据此 publish。");
    }

    /**
     * 在宿主 manifest 的 extra.moo-private-packages 里找该包条目（三份任一份命中即可）。
     *
     * @return array<string, mixed>|null
     */
    protected function manifestEntry(string $appRoot, string $package): ?array
    {
        foreach (self::PROFILE_FILES as $file) {
            $manifest = $this->readJsonManifest($appRoot . '/' . $file);
            if ($manifest === null) {
                continue;
            }

            foreach (($manifest['extra']['moo-private-packages'] ?? []) as $item) {
                if (is_array($item) && ($item['name'] ?? null) === $package) {
                    return $item;
                }
            }
        }

        return null;
    }

    /**
     * 已发布目录名 = manifest 里的 repo-key（缺失返回 null，调用方退回包短名）。
     */
    protected function manifestRepoKey(string $appRoot, string $package): ?string
    {
        $repoKey = $this->manifestEntry($appRoot, $package)['repo-key'] ?? null;

        return is_string($repoKey) && $repoKey !== '' ? $repoKey : null;
    }

    /**
     * 相对路径按 --root 展开；绝对路径原样返回。
     */
    protected function absolutePath(string $path, string $root): string
    {
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            return $path;
        }

        return rtrim($root, '/') . '/' . ltrim($path, '/');
    }
}
