<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Yaml\Yaml;

/**
 * plan-52 文档中心存储层。
 *
 * MD 文档存 config('scaffold.docs.path')（默认 scaffold/docs，相对 base_path，入 git）。
 * 每篇头部可选 YAML frontmatter（title / group / order / tags…），其余为正文。
 *
 * 设计取舍（对齐 scaffold 尺子）：
 *   - 纯文件、无 DB、无索引文件：导航树每次扫目录现算。
 *   - 历史/版本走 git：不做快照 / undo / 审计。删除是 unlink，git-tracked 可 `git restore` 找回。
 *   - 写类（save/delete）production / 强制只读时硬拒（跟 AiSettingStore 字面一致），
 *     另有 EnforceScaffoldWritable 的 docs/* 锁双层兜底。
 *   - slug = 文档相对 docs 根的路径（去 .md，用 / 分隔）。slug 永远走 query/body，不进路由 path，
 *     避免 unicode 路由正则坑；入口 isValidSlug + realpath 收敛双层防穿越（ship-checklist #13）。
 *
 * plan-53 出身模型：文档身份 = (origin, slug)。origin null = host，string = 扩展包 key
 * （PackageRegistry 自动发现、带 docs/ 目录的包）。所有读写方法带显式 $origin 参数，
 * 没有"切源"状态 —— navTree() 一棵树同屏呈现全部源，编辑/保存按出身落对应 docs/，
 * 写权同软链硬线（vendor 拷贝包只读）。
 */
class DocsRepository
{
    /** slug 段非法字符：控制符 + 文件系统危险字符。中文/空格/字母数字 _ - . 允许。 */
    private const SLUG_FORBIDDEN = '/[\x00-\x1f\x7f<>:"\\\\|?*]/u';

    public function __construct(private readonly Filesystem $fs, private readonly Utility $utility) {}

    /** all() 的单请求 memo(按 origin 分桶,'' = host):一次渲染 navTree + find 会多次扫同源。save/delete 后置空对应桶。 */
    private array $allCache = [];

    /**
     * 文档源列表(host + 带 docs/ 目录的扩展包,PackageRegistry 自动发现)。
     *
     * @return list<array{key:?string,label:string,writable:bool}>
     */
    public function sources(): array
    {
        $out = [['key' => null, 'label' => 'Host', 'writable' => true]];
        foreach (app(PackageRegistry::class)->all() as $key => $pkg) {
            if (! is_dir($pkg['base_path'] . 'docs')) {
                continue;   // 包没有 docs/ 目录 → 不进文档源(约定即配置,不做空目录兜底)
            }
            $out[] = ['key' => $key, 'label' => $key, 'writable' => (bool) $pkg['writable']];
        }

        return $out;
    }

    /** origin 是否已知源(null = host 恒真;包 key 须在 sources() 里)。 */
    public function isKnownSource(?string $origin): bool
    {
        if ($origin === null) {
            return true;
        }
        foreach ($this->sources() as $s) {
            if ($s['key'] === $origin) {
                return true;
            }
        }

        return false;
    }

    /** 某源是否可写(host 恒真 — 环境级只读由 assertWritable 统一判;包看软链硬线)。 */
    public function sourceWritable(?string $origin): bool
    {
        foreach ($this->sources() as $s) {
            if ($s['key'] === $origin) {
                return $s['writable'];
            }
        }

        return false;
    }

    /** docs 根目录绝对路径（含末尾 /）。host 沿用 config('scaffold.docs.path');包走包目录。 */
    public function baseDir(?string $origin = null): string
    {
        return $this->utility->targetContext($origin)->pathFor('docs');
    }

    /** 相对 base_path 的展示路径（给 UI / 提示用）。包源显示 `[包key]/docs`。 */
    public function relBaseDir(?string $origin = null): string
    {
        if ($origin === null) {
            return trim((string) config('scaffold.docs.path', 'scaffold/docs'), '/');
        }

        return "[{$origin}]/docs";
    }

    public function isReadonly(): bool
    {
        if (function_exists('app') && app()->environment('production')) {
            return true;
        }

        return (bool) config('scaffold.config_ui.readonly', false);
    }

    // -------------------------------------------------------------------------
    // 读
    // -------------------------------------------------------------------------

    /**
     * 某源全部文档的扁平列表（按 group→order→title 排序后）。
     *
     * @return list<array{slug:string,title:string,group:string,order:int,tags:list<string>,mtime:int}>
     */
    public function all(?string $origin = null): array
    {
        $bucket = $origin ?? '';
        if (isset($this->allCache[$bucket])) {
            return $this->allCache[$bucket];
        }

        $base = $this->baseDir($origin);
        if (! $this->fs->isDirectory($base)) {
            return $this->allCache[$bucket] = [];
        }

        $docs = [];
        foreach ($this->fs->allFiles($base) as $file) {
            if (strtolower($file->getExtension()) !== 'md') {
                continue;
            }
            // 隐藏文件 / 下划线前缀（约定的草稿/局部）跳过
            $relPath = str_replace('\\', '/', $file->getRelativePathname());
            $slug    = preg_replace('/\.md$/i', '', $relPath);
            $segs    = explode('/', $slug);
            if ($this->anySegmentHidden($segs)) {
                continue;
            }

            $parsed = $this->parse((string) $this->fs->get($file->getPathname()));
            $meta   = $parsed['meta'];
            $docs[] = [
                'slug'  => $slug,
                'title' => $this->deriveTitle($meta, $slug),
                'group' => $this->deriveGroup($meta, $slug),
                'order' => (int) ($meta['order'] ?? 999),
                'tags'  => $this->normalizeTags($meta['tags'] ?? []),
                'mtime' => (int) $file->getMTime(),
            ];
        }

        usort($docs, static function ($a, $b) {
            return [$a['group'], $a['order'], $a['title']] <=> [$b['group'], $b['order'], $b['title']];
        });

        return $this->allCache[$bucket] = $docs;
    }

    /**
     * 单源的侧边导航分组（喂给 <x-scaffold::side-tree :groups>）。
     * item key 带 origin 前缀(`包key:slug`)避免跨源同名 slug 高亮撞车;href 带 src 参数。
     *
     * @return list<array{key:string,label:string,count:int,items:list<array{key:string,label:string,href:string,data:array}>}>
     */
    public function tree(?string $origin = null): array
    {
        $groups = [];
        foreach ($this->all($origin) as $doc) {
            $g = $doc['group'];
            if (! isset($groups[$g])) {
                $groups[$g] = ['key' => $g, 'label' => $g, 'count' => 0, 'items' => []];
            }
            $groups[$g]['count']++;
            $groups[$g]['items'][] = [
                'key'   => self::docKey($doc['slug'], $origin),
                'label' => $doc['title'],
                'href'  => route('docs.index', ['doc' => $doc['slug']] + ($origin !== null ? ['src' => $origin] : [])),
                // 侧栏只读 order 序号:显式 order 显数值,未设(落 999 默认)显「–」。看得到全组顺序,
                // 要调就开那篇改 frontmatter 的 order: 行(那行就在编辑器 textarea 顶部)。
                'index' => $doc['order'] >= 999 ? '–' : (string) $doc['order'],
                'data'  => ['doc' => $doc['slug']],
            ];
        }

        return array_values($groups);
    }

    /**
     * 全源聚合导航树(plan-53 分块):host 分组保持原层级(host-only 时 UI 逐字节不变),
     * 每个有文档的包源追加一个带 📦 icon 的顶层组,包内分组降为 sub_groups。
     */
    public function navTree(): array
    {
        $groups = $this->tree(null);
        foreach ($this->sources() as $src) {
            if ($src['key'] === null) {
                continue;
            }
            $pkgGroups = $this->tree($src['key']);
            if ($pkgGroups === []) {
                continue;   // 包有 docs/ 但还没有文档 → 不渲染空块
            }
            $groups[] = [
                'key'        => 'pkg:' . $src['key'],
                'label'      => $src['key'],
                'icon'       => 'package',
                'count'      => array_sum(array_column($pkgGroups, 'count')),
                'sub_groups' => $pkgGroups,
            ];
        }

        return $groups;
    }

    /** side-tree 高亮用的 item key(跨源唯一)。 */
    public static function docKey(string $slug, ?string $origin): string
    {
        return ($origin !== null ? $origin . ':' : '') . $slug;
    }

    /**
     * 取单篇文档。slug 非法 / 文件不存在返回 null。
     *
     * @return array{slug:string,title:string,group:string,meta:array,body:string,raw:string,mtime:int}|null
     */
    public function find(string $slug, ?string $origin = null): ?array
    {
        if (! $this->isValidSlug($slug)) {
            return null;
        }
        $abs = $this->absPath($slug, $origin);
        if (! $this->fs->isFile($abs) || ! $this->withinBase($abs, $origin)) {
            return null;
        }

        $raw    = (string) $this->fs->get($abs);
        $parsed = $this->parse($raw);

        return [
            'slug'  => $slug,
            'title' => $this->deriveTitle($parsed['meta'], $slug),
            'group' => $this->deriveGroup($parsed['meta'], $slug),
            'meta'  => $parsed['meta'],
            'body'  => $parsed['body'],
            'raw'   => $raw,
            'mtime' => (int) $this->fs->lastModified($abs),
        ];
    }

    public function exists(string $slug, ?string $origin = null): bool
    {
        return $this->isValidSlug($slug) && $this->fs->isFile($this->absPath($slug, $origin));
    }

    /** 某源没有任何文档时返回 null；否则返回排序后第一篇的 slug。 */
    public function firstSlug(?string $origin = null): ?string
    {
        $all = $this->all($origin);

        return $all[0]['slug'] ?? null;
    }

    // -------------------------------------------------------------------------
    // 写（production / 只读硬拒）
    // -------------------------------------------------------------------------

    /**
     * 写入（新建或覆盖）。返回规范化后的 slug。
     */
    public function save(string $slug, string $raw, ?string $origin = null): string
    {
        $this->assertWritable($origin);
        if (! $this->isValidSlug($slug)) {
            throw new InvalidArgumentException('文档路径非法（不能含 .. / 控制符 / 文件系统保留字符，每段非空且不以点开头）。');
        }
        $abs = $this->absPath($slug, $origin);
        // realpath 收敛：父目录解析后必须仍在 docs 根内（双层防穿越）
        $this->fs->ensureDirectoryExists(dirname($abs));
        if (! $this->withinBase($abs, $origin)) {
            throw new InvalidArgumentException('文档路径越界，拒绝写入。');
        }

        // 统一换行 + 末尾留一个换行
        $raw = str_replace("\r\n", "\n", $raw);
        $raw = rtrim($raw, "\n") . "\n";
        $this->fs->put($abs, $raw);
        unset($this->allCache[$origin ?? '']);   // 列表变了,作废该源 memo

        return $slug;
    }

    /**
     * 删除。slug 非法 / 文件不存在抛异常。docs 入 git，删错可 `git restore`。
     */
    public function delete(string $slug, ?string $origin = null): void
    {
        $this->assertWritable($origin);
        if (! $this->isValidSlug($slug)) {
            throw new InvalidArgumentException('文档路径非法。');
        }
        $abs = $this->absPath($slug, $origin);
        if (! $this->fs->isFile($abs) || ! $this->withinBase($abs, $origin)) {
            throw new InvalidArgumentException('文档不存在或路径越界。');
        }
        $this->fs->delete($abs);
        unset($this->allCache[$origin ?? '']);   // 列表变了,作废该源 memo
    }

    public function assertWritable(?string $origin = null): void
    {
        if (function_exists('app') && app()->environment('production')) {
            throw new InvalidArgumentException('生产环境为只读预览，禁止编辑文档。');
        }
        if ((bool) config('scaffold.config_ui.readonly', false)) {
            throw new InvalidArgumentException('当前为强制只读模式（SCAFFOLD_CONFIG_READONLY），禁止编辑文档。');
        }
        // 写权硬线(plan-53):扩展包源须软链装(写 vendor = 写真仓);vcs 拷贝一律拒写
        if ($origin !== null && ! $this->utility->targetContext($origin)->writable) {
            throw new InvalidArgumentException("扩展包 [{$origin}] 是 vendor 拷贝(非软链安装),只读 —— 编辑请在软链装该包的开发环境进行。");
        }
    }

    // -------------------------------------------------------------------------
    // 内部
    // -------------------------------------------------------------------------

    /**
     * 公开版 frontmatter 拆分（实时预览用：textarea 里是整篇含 frontmatter 的原文）。
     *
     * @return array{meta:array,body:string}
     */
    public function parseRaw(string $raw): array
    {
        return $this->parse($raw);
    }

    /**
     * 拆 frontmatter + 正文。
     *
     * @return array{meta:array,body:string}
     */
    private function parse(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        if (preg_match('/^---\n(.*?)\n---\n?(.*)$/s', $raw, $m)) {
            try {
                $meta = Yaml::parse($m[1]) ?: [];
            } catch (\Throwable) {
                $meta = [];
            }
            if (! is_array($meta)) {
                $meta = [];
            }

            return ['meta' => $meta, 'body' => $m[2]];
        }

        return ['meta' => [], 'body' => $raw];
    }

    private function deriveTitle(array $meta, string $slug): string
    {
        $title = trim((string) ($meta['title'] ?? ''));
        if ($title !== '') {
            return $title;
        }
        $base = basename($slug);

        return $base !== '' ? $base : $slug;
    }

    private function deriveGroup(array $meta, string $slug): string
    {
        $group = trim((string) ($meta['group'] ?? ''));
        if ($group !== '') {
            return $group;
        }
        // 无 group：嵌套目录用首段当分组，根目录归「未分组」
        if (str_contains($slug, '/')) {
            return explode('/', $slug)[0];
        }

        return '未分组';
    }

    /** @return list<string> */
    private function normalizeTags(mixed $tags): array
    {
        if (is_string($tags)) {
            $tags = preg_split('/[,，]\s*/', $tags) ?: [];
        }
        if (! is_array($tags)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($t) => trim((string) $t), $tags), static fn ($t) => $t !== ''));
    }

    private function absPath(string $slug, ?string $origin): string
    {
        return $this->baseDir($origin) . $slug . '.md';
    }

    /** @param list<string> $segs */
    private function anySegmentHidden(array $segs): bool
    {
        foreach ($segs as $seg) {
            if ($seg !== '' && str_starts_with($seg, '_')) {
                return true;
            }
        }

        return false;
    }

    private function isValidSlug(string $slug): bool
    {
        if ($slug === '' || strlen($slug) > 200) {
            return false;
        }
        if (str_contains($slug, '..') || str_contains($slug, "\0") || str_starts_with($slug, '/')) {
            return false;
        }
        if (preg_match(self::SLUG_FORBIDDEN, $slug)) {
            return false;
        }
        foreach (explode('/', $slug) as $seg) {
            if ($seg === '' || $seg === '.' || str_starts_with($seg, '.')) {
                return false;
            }
        }

        return true;
    }

    /** realpath 收敛：abs 解析后必须落在该源 docs 根内（文件可不存在，则按其父目录判断）。 */
    private function withinBase(string $abs, ?string $origin): bool
    {
        $realBase = realpath(rtrim($this->baseDir($origin), '/'));
        if ($realBase === false) {
            return false;
        }
        $target = realpath($abs);
        if ($target === false) {
            // 文件尚不存在（新建）：按父目录判断
            $target = realpath(dirname($abs));
            if ($target === false) {
                return false;
            }
        }

        return $target === $realBase || str_starts_with($target, $realBase . DIRECTORY_SEPARATOR);
    }
}
