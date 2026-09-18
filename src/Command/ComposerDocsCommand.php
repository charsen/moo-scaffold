<?php declare(strict_types=1);

/*
 * @Description: 按宿主三份 Composer manifest 生成「私包清单表」（Markdown）—— 供各 Host 的
 *   PRIVATE-COMPOSER-PACKAGES.md 复用，替代人工手抄（记数 / 版本 / 来源容易抄错）。
 *
 * 用法:
 *   moo:composer:docs --root=/path/to/host                  # 打印 8 列私包清单表 + 公开包小表
 *   moo:composer:docs --root=/path/to/host --format=matrix  # 每包三档约束对照
 *   moo:composer:docs --root=/path/to/host --check          # 校验文档是否过期(过期非 0)
 *   moo:composer:docs --root=/path/to/host --write          # 就地写回(仅替换 marker 区间)
 *
 * 纯只读(table / matrix / --check);--write 只在文档已有 marker 区间时替换区间内容,
 * 没有 marker 一律拒绝(不猜插入位置)。
 *
 * 判定口径:
 *   - 私包顺序严格跟 `extra.moo-private-packages`(不重排、不按名排序)
 *   - 约束取三份 manifest 的 require.<name>;URL 取 test / production 的
 *     repositories.<repo-key>.url,两者不同则在表里显式标冲突
 *   - 不在 `extra.moo-private-packages`、但以 charsen/ 开头的 require 归入
 *     「走 Packagist 的公开包」小表(moo-feedback 即当前生态的真实形态)
 */

namespace Mooeen\Scaffold\Command;

use Mooeen\Scaffold\Command\Concerns\ResolvesHostPaths;
use Mooeen\Scaffold\Support\Paths;

class ComposerDocsCommand extends Command
{
    use ResolvesHostPaths;

    public const BEGIN_MARKER = '<!-- BEGIN moo-manifest-table -->';

    public const END_MARKER = '<!-- END moo-manifest-table -->';

    /** 三档 profile：env key => 文件名（顺序即表内「本地/测试/生产」列顺序）。 */
    private const PROFILES = [
        'local'      => 'composer.json',
        'test'       => 'composer.test.json',
        'production' => 'composer.production.json',
    ];

    protected bool $requiresLocalEnvironment = false;     // 只读,任何环境可跑

    protected string $title = 'Private Composer Package Docs';

    protected $name = 'moo:composer:docs';

    protected $description = 'Render/verify the host private composer package manifest table (read-only unless --write)';

    protected $signature = 'moo:composer:docs
        {--root= : Host 仓根目录（默认 base_path() 的上一级，即含 engine/ 的目录）}
        {--format=table : 输出形态，table（8 列清单表）| matrix（每包三档约束对照）}
        {--bare : 只输出 Markdown 表格本体（无 ### 标题 / 计数说明 / 空行块），便于嵌进宿主已有小节}
        {--write : 就地写回文档（仅替换 marker 区间）}
        {--check : 只校验，过期则非 0 退出}
        {--file=PRIVATE-COMPOSER-PACKAGES.md : --write/--check 的目标文件（相对 --root）}';

    public function handle(): int
    {
        $this->showTitle();

        $format = (string) ($this->option('format') ?? 'table');
        if (! in_array($format, ['table', 'matrix'], true)) {
            $this->console()->error("未知 --format：{$format}（只支持 table | matrix）");

            return self::FAILURE;
        }

        if ($this->option('write') && $this->option('check')) {
            $this->console()->error('--write 与 --check 互斥，请二选一。');

            return self::FAILURE;
        }

        $root    = $this->resolveHostRoot();
        $appRoot = $this->resolveAppRoot($root);

        $profiles = [];
        foreach (self::PROFILES as $env => $file) {
            $path           = $appRoot . '/' . $file;
            $profiles[$env] = $this->readJsonManifest($path);
            if ($profiles[$env] === null) {
                $this->console()->error("缺少或无法解析 manifest：{$path}");

                return self::FAILURE;
            }
        }

        $rendered = $this->renderBlock($profiles, $format, (bool) $this->option('bare'));
        $file     = (string) ($this->option('file') ?: 'PRIVATE-COMPOSER-PACKAGES.md');
        // --file 相对宿主仓根；绝对路径（含 Windows 盘符）原样。
        $docPath = Paths::absolute($file, $root);

        if ($this->option('check')) {
            return $this->checkDoc($docPath, $rendered);
        }

        if ($this->option('write')) {
            return $this->writeDoc($docPath, $rendered);
        }

        // 默认形态：把 marker 区间该有的内容直接打到 stdout，方便人工复制或 diff
        // （逐行输出，便于控制台断言/分页，也与 --write 落盘内容逐字节一致）。
        foreach (explode("\n", $rendered) as $line) {
            $this->console()->line($line);
        }

        return self::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────
    // 渲染
    // ─────────────────────────────────────────────────────────────

    /**
     * $bare = true 时只出表格本体（表头 + 分隔行 + 数据行），不带 `### 标题`、计数说明与
     * 装饰空行块 —— 便于嵌进宿主文档已有的 `## 本仓私包清单` 小节。公开包小表在 bare 下
     * 保留表体（两张表之间仅留一个换行以维持 Markdown 合法），但不带标题与说明文字。
     *
     * @param array<string, array<string, mixed>> $profiles
     */
    protected function renderBlock(array $profiles, string $format, bool $bare = false): string
    {
        $private = $this->privateRows($profiles);
        $public  = $this->publicRows($profiles);

        if ($format === 'matrix') {
            $rows = [];
            foreach ($private as $row) {
                $same   = $row['本地约束'] === $row['测试约束'] && $row['测试约束'] === $row['生产约束'];
                $rows[] = [
                    'name'         => $row['name'],
                    '本地约束'     => $row['本地约束'],
                    '测试约束'     => $row['测试约束'],
                    '生产约束'     => $row['生产约束'],
                    '是否三档一致' => $same ? '✓' : '✗',
                ];
            }
            $privateTable = $this->markdownTable(['name', '本地约束', '测试约束', '生产约束', '是否三档一致'], $rows);
            $privateTitle = '### 私包三档约束对照（`extra.moo-private-packages`，共 ' . count($private) . ' 个）';
        } else {
            $privateTable = $this->markdownTable(
                ['name', 'repo-key', 'provider-rel', 'publish-tag', '本地约束', '测试约束', '生产约束', '仓库 URL'],
                $private,
            );
            $privateTitle = '### 私包清单（`extra.moo-private-packages`，共 ' . count($private) . ' 个）';
        }

        $lines = $bare ? $privateTable : array_merge([$privateTitle, ''], $privateTable);

        if ($public !== []) {
            $publicTable = $this->markdownTable(
                ['name', '本地约束', '测试约束', '生产约束', '是否有 repository'],
                $public,
            );

            if ($bare) {
                // 两张表之间留一个换行，否则 Markdown 会把它们并成一张表。
                $lines = array_merge($lines, [''], $publicTable);
            } else {
                $lines = array_merge($lines, [
                    '',
                    '### 走 Packagist 的公开包（不在 `extra.moo-private-packages`，共 ' . count($public) . ' 个）',
                    '',
                ], $publicTable);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * 私包行：顺序严格跟 `extra.moo-private-packages`。
     *
     * 只读不校验：形态问题（name 重复、三份不一致）由 `ComposerProfiles::manifestProblems()` 报，
     * 这里遇到畸形条目跳过即可 —— 生成文档不能因为清单写歪就报错收场。宿主单份 composer.json 的
     * 第三个消费者是 `AuditResourceKeysCommand::privatePackageRoots()`，见该处注释（三处不合并）。
     *
     * @param array<string, array<string, mixed>> $profiles
     *
     * @return list<array<string, string>>
     */
    protected function privateRows(array $profiles): array
    {
        $declared = $profiles['local']['extra']['moo-private-packages'] ?? [];

        if (! is_array($declared) || $declared === []) {
            foreach (['test', 'production'] as $env) {
                $candidate = $profiles[$env]['extra']['moo-private-packages'] ?? [];
                if (is_array($candidate) && $candidate !== []) {
                    $declared = $candidate;
                    break;
                }
            }
        }

        $rows = [];
        foreach ($declared as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = (string) ($item['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $repoKey = (string) ($item['repo-key'] ?? '');

            $rows[] = [
                'name'         => $name,
                'repo-key'     => $repoKey !== '' ? $repoKey : '—',
                'provider-rel' => $this->orDash($item['provider-rel'] ?? null),
                'publish-tag'  => $this->orDash($item['publish-tag'] ?? null),
                '本地约束'     => $this->constraint($profiles, 'local', $name),
                '测试约束'     => $this->constraint($profiles, 'test', $name),
                '生产约束'     => $this->constraint($profiles, 'production', $name),
                '仓库 URL'     => $this->repoUrl($profiles, $repoKey),
            ];
        }

        return $rows;
    }

    /**
     * 公开包行：三份 manifest 里出现、以 charsen/ 开头、但不在私包清单里的 require。
     *
     * @param array<string, array<string, mixed>> $profiles
     *
     * @return list<array<string, string>>
     */
    protected function publicRows(array $profiles): array
    {
        $private = [];
        foreach ($this->privateRows($profiles) as $row) {
            $private[$row['name']] = true;
        }

        $names = [];
        foreach ($profiles as $manifest) {
            foreach (array_keys($manifest['require'] ?? []) as $name) {
                if (! is_string($name) || ! str_starts_with($name, 'charsen/') || isset($private[$name])) {
                    continue;
                }
                $names[$name] = true;
            }
        }

        $names = array_keys($names);
        sort($names);

        $rows = [];
        foreach ($names as $name) {
            $rows[] = [
                'name'              => $name,
                '本地约束'          => $this->constraint($profiles, 'local', $name),
                '测试约束'          => $this->constraint($profiles, 'test', $name),
                '生产约束'          => $this->constraint($profiles, 'production', $name),
                '是否有 repository' => $this->hasRepository($profiles, $name) ? '是' : '否',
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     */
    protected function constraint(array $profiles, string $env, string $name): string
    {
        $value = $profiles[$env]['require'][$name] ?? null;

        return is_string($value) && $value !== '' ? $value : '—';
    }

    /**
     * 仓库 URL 取 test / production 的 repositories.<repo-key>.url；两者不同则显式标冲突。
     *
     * @param array<string, array<string, mixed>> $profiles
     */
    protected function repoUrl(array $profiles, string $repoKey): string
    {
        $test = $this->repoUrlOf($profiles['test'], $repoKey);
        $prod = $this->repoUrlOf($profiles['production'], $repoKey);

        if ($test === null && $prod === null) {
            return '—';
        }

        if ($test === $prod) {
            return (string) $test;
        }

        return '⚠ 冲突：test=' . ($test ?? '—') . ' / prod=' . ($prod ?? '—');
    }

    /**
     * @param array<string, mixed> $manifest
     */
    protected function repoUrlOf(array $manifest, string $repoKey): ?string
    {
        if ($repoKey === '') {
            return null;
        }

        $url = $manifest['repositories'][$repoKey]['url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * 公开包是否也在 repositories 里挂了仓库（按 options.versions 键或 url 末段匹配短名）。
     *
     * @param array<string, array<string, mixed>> $profiles
     */
    protected function hasRepository(array $profiles, string $name): bool
    {
        $short = substr($name, strpos($name, '/') + 1);

        foreach ($profiles as $manifest) {
            foreach (($manifest['repositories'] ?? []) as $repo) {
                if (! is_array($repo)) {
                    continue;
                }

                if (isset($repo['options']['versions'][$name])) {
                    return true;
                }

                $url  = (string) ($repo['url'] ?? '');
                $base = preg_replace('/\.git$/', '', basename(rtrim($url, '/')));
                if ($base !== '' && $base === $short) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<string>               $headers
     * @param list<array<string,string>> $rows
     *
     * @return list<string>
     */
    protected function markdownTable(array $headers, array $rows): array
    {
        $lines = [
            '| ' . implode(' | ', $headers) . ' |',
            '| ' . implode(' | ', array_fill(0, count($headers), '---')) . ' |',
        ];

        foreach ($rows as $row) {
            $cells = [];
            foreach ($headers as $header) {
                $cells[] = $this->orDash($row[$header] ?? null);
            }
            $lines[] = '| ' . implode(' | ', $cells) . ' |';
        }

        return $lines;
    }

    // ─────────────────────────────────────────────────────────────
    // --check / --write
    // ─────────────────────────────────────────────────────────────

    protected function checkDoc(string $docPath, string $rendered): int
    {
        if (! $this->filesystem->isFile($docPath)) {
            $this->console()->error("文档不存在：{$docPath}");

            return self::FAILURE;
        }

        $existing = $this->extractBlock((string) $this->filesystem->get($docPath));
        if ($existing === null) {
            $this->console()->error('文档里没有 marker 区间（' . self::BEGIN_MARKER . ' / ' . self::END_MARKER . '），无法校验。');

            return self::FAILURE;
        }

        $doc = $this->parseBlock($existing);
        $gen = $this->parseBlock($rendered);

        $added   = array_diff_key($gen, $doc);
        $removed = array_diff_key($doc, $gen);

        $changed = [];
        foreach (array_intersect_key($gen, $doc) as $key => $genRow) {
            $docRow    = $doc[$key];
            $fieldDiff = [];

            foreach ($genRow['fields'] as $field => $value) {
                $old = $docRow['fields'][$field] ?? null;
                if ($old !== $value) {
                    $fieldDiff[$field] = [$old, $value];
                }
            }
            foreach ($docRow['fields'] as $field => $value) {
                if (! array_key_exists($field, $genRow['fields'])) {
                    $fieldDiff[$field] = [$value, null];
                }
            }

            if ($fieldDiff !== []) {
                $changed[$key] = $fieldDiff;
            }
        }

        if ($added === [] && $removed === [] && $changed === []) {
            $this->console()->success('文档与三份 manifest 一致（无过期）。');

            return self::SUCCESS;
        }

        $this->console()->warn(sprintf(
            '文档已过期：新增 %d / 删除 %d / 变更 %d',
            count($added),
            count($removed),
            count($changed),
        ));

        foreach (array_keys($added) as $name) {
            $this->console()->line("  <fg=green>+ 新增</> {$name}");
        }
        foreach (array_keys($removed) as $name) {
            $this->console()->line("  <fg=red>- 删除</> {$name}");
        }
        foreach ($changed as $name => $fields) {
            $this->console()->line("  <fg=yellow>~ 变更</> {$name}");
            foreach ($fields as $field => [$old, $new]) {
                $this->console()->line("      {$field}: 文档=<fg=red>" . $this->orDash($old) . '</> → manifest=<fg=green>' . $this->orDash($new) . '</>');
            }
        }

        $this->console()->line('<fg=gray>  确认后重跑：php artisan moo:composer:docs --write（仅替换 marker 区间）</>');

        return self::FAILURE;
    }

    protected function writeDoc(string $docPath, string $rendered): int
    {
        if (! $this->filesystem->isFile($docPath)) {
            $this->console()->error("文档不存在：{$docPath}");

            return self::FAILURE;
        }

        $content = (string) $this->filesystem->get($docPath);
        if ($this->extractBlock($content) === null) {
            $this->console()->error(
                '文档里没有 marker 区间（' . self::BEGIN_MARKER . ' / ' . self::END_MARKER . '），'
                . '--write 拒绝执行：不猜插入位置。请先手工加上 marker 区间。',
            );

            return self::FAILURE;
        }

        $updated = preg_replace(
            '/' . preg_quote(self::BEGIN_MARKER, '/') . '.*?' . preg_quote(self::END_MARKER, '/') . '/s',
            self::BEGIN_MARKER . "\n" . $rendered . "\n" . self::END_MARKER,
            $content,
            1,
        );

        if ($updated === null) {
            $this->console()->error('marker 区间替换失败（正则异常），未写入。');

            return self::FAILURE;
        }

        if ($updated === $content) {
            $this->console()->info('文档已是最新，无需写回。');

            return self::SUCCESS;
        }

        if ($this->filesystem->put($docPath, $updated) === false) {
            $this->console()->error("写入失败：{$docPath}");

            return self::FAILURE;
        }

        $this->console()->success("已更新 {$docPath}（仅替换 marker 区间）");

        return self::SUCCESS;
    }

    /**
     * 取 marker 区间内的内容；没有 marker 返回 null（调用方据此拒绝写入 / 判失败）。
     */
    protected function extractBlock(string $content): ?string
    {
        $begin = strpos($content, self::BEGIN_MARKER);
        $end   = strpos($content, self::END_MARKER);

        if ($begin === false || $end === false || $end < $begin) {
            return null;
        }

        $begin += strlen(self::BEGIN_MARKER);

        return trim(substr($content, $begin, $end - $begin), "\r\n ");
    }

    /**
     * 把 Markdown 表解析成 `包名 => ['fields' => [列名 => 值]]`，用于对人可读的差异摘要。
     *
     * @return array<string, array{table:string, fields:array<string,string>}>
     */
    protected function parseBlock(string $block): array
    {
        $lines   = preg_split('/\R/', $block) ?: [];
        $result  = [];
        $headers = null;
        $seq     = 0;

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || ! str_starts_with($line, '|')) {
                $headers = null;

                continue;
            }

            $cells = array_map('trim', explode('|', trim($line, '|')));

            if ($headers === null) {
                $headers = $cells;

                continue;
            }

            $separator = true;
            foreach ($cells as $cell) {
                if (preg_match('/^:?-{2,}:?$/', $cell) !== 1) {
                    $separator = false;

                    break;
                }
            }
            if ($separator || count($cells) !== count($headers)) {
                continue;
            }

            $row = array_combine($headers, $cells);
            $key = (string) ($row[$headers[0]] ?? '');
            if ($key === '') {
                continue;
            }
            if (isset($result[$key])) {
                $key .= '#' . (++$seq);
            }

            $result[$key] = ['table' => $headers[0], 'fields' => $row];
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────
    // 小工具
    // ─────────────────────────────────────────────────────────────

    protected function orDash(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        $value = trim((string) $value);

        return $value === '' ? '—' : $value;
    }
}
