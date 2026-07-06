<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Support\DocsRepository;
use Mooeen\Scaffold\Support\PackageRegistry;

/**
 * plan-52 DocsRepository 单测:frontmatter 解析 + 排序 + 路径安全(防穿越) + readonly 硬拒。
 * sandbox:setBasePath 到 temp dir,docs 落 sandbox/docs,跑完整目录删。
 */
beforeEach(function () {
    $this->sandbox = sys_get_temp_dir() . '/scaffold_docs_' . uniqid();
    @mkdir($this->sandbox, 0755, true);
    $this->origBase = base_path();
    app()->setBasePath($this->sandbox);
    config(['scaffold.docs.path' => 'docs', 'scaffold.config_ui.readonly' => false]);
    $this->repo = app(DocsRepository::class);
});

afterEach(function () {
    app()->setBasePath($this->origBase);
    (new Filesystem)->deleteDirectory($this->sandbox);
});

it('save() 写盘后 find() 解析 frontmatter + 正文', function () {
    $this->repo->save('设计/流程', "---\ntitle: 订单流程\ngroup: 设计\norder: 5\ntags: [a, b]\n---\n\n# 正文\n");
    $doc = $this->repo->find('设计/流程');
    expect($doc)->not->toBeNull();
    expect($doc['title'])->toBe('订单流程');
    expect($doc['group'])->toBe('设计');
    expect($doc['meta']['order'])->toBe(5);
    expect(trim($doc['body']))->toBe('# 正文');
});

it('无 frontmatter 时 title 取文件名、group 归未分组(根目录)', function () {
    $this->repo->save('随手记', "就是正文，没有 frontmatter。\n");
    $doc = $this->repo->find('随手记');
    expect($doc['title'])->toBe('随手记');
    expect($doc['group'])->toBe('未分组');
});

it('无 group 的嵌套文档用首段目录当分组', function () {
    $this->repo->save('市场/评价', "正文\n");
    expect($this->repo->find('市场/评价')['group'])->toBe('市场');
});

// ─── 扩展包文档源(plan-53 出身模型):显式 origin 参数 + 隔离 + sources 自动发现 + navTree 分块 ───

/** 沙箱里造一个符合约定的假扩展包(可选 docs/ 目录)。 */
function makeDocsPkg(string $root, string $name, string $ns, bool $withDocs = true): string
{
    @mkdir($root . '/scaffold/database', 0755, true);
    if ($withDocs) {
        @mkdir($root . '/docs', 0755, true);
    }
    file_put_contents($root . '/composer.json', json_encode([
        'name'     => $name,
        'autoload' => ['psr-4' => [$ns => 'src/']],
    ], JSON_UNESCAPED_SLASHES));

    return $root;
}

it('origin 参数读写落包 docs/、跟 host 隔离', function () {
    $pkgRoot = makeDocsPkg($this->sandbox . '/pkg', 'acme/pkg', 'Acme\\Pkg\\');
    app()->instance(PackageRegistry::class, new PackageRegistry([$pkgRoot]));

    $this->repo->save('host篇', "host 内容\n");
    $this->repo->save('pkg篇', "pkg 内容\n", 'pkg');

    // 物理落点不同(包 docs 按约定固定在 {包根}/docs/)
    expect(file_exists($this->sandbox . '/docs/host篇.md'))->toBeTrue();
    expect(file_exists($pkgRoot . '/docs/pkg篇.md'))->toBeTrue();
    // baseDir 指向包目录
    expect($this->repo->baseDir('pkg'))->toBe($pkgRoot . '/docs/');
    // 隔离:互相看不到对方的文档
    expect($this->repo->find('pkg篇'))->toBeNull();
    expect($this->repo->find('host篇', 'pkg'))->toBeNull();
    expect($this->repo->find('pkg篇', 'pkg'))->not->toBeNull();
});

it('sources():host(key null) + 带 docs/ 的包自动发现,无 docs/ 的包不进源', function () {
    $withDocs = makeDocsPkg($this->sandbox . '/pkg', 'acme/pkg', 'Acme\\Pkg\\');
    $noDocs   = makeDocsPkg($this->sandbox . '/nodocs', 'acme/nodocs', 'Acme\\N\\', withDocs: false);
    app()->instance(PackageRegistry::class, new PackageRegistry([$withDocs, $noDocs]));

    $keys = array_column(app(DocsRepository::class)->sources(), 'key');
    expect($keys)->toContain(null);
    expect($keys)->toContain('pkg');
    expect($keys)->not->toContain('nodocs');
    expect($this->repo->isKnownSource('pkg'))->toBeTrue();
    expect($this->repo->isKnownSource('nodocs'))->toBeFalse();
});

it('navTree():host 分组原层级,包源一个 📦 顶层组(sub_groups);host-only 形态不变', function () {
    $this->repo->save('指南/host篇', "---\ngroup: 指南\n---\n");

    // host-only:形态跟 tree() 逐项一致(parity)
    expect($this->repo->navTree())->toBe($this->repo->tree());

    $pkgRoot = makeDocsPkg($this->sandbox . '/pkg', 'acme/pkg', 'Acme\\Pkg\\');
    app()->instance(PackageRegistry::class, new PackageRegistry([$pkgRoot]));
    $this->repo->save('设计/pkg篇', "---\ngroup: 设计\n---\n", 'pkg');

    $nav = $this->repo->navTree();
    $pkgGroup = collect($nav)->firstWhere('key', 'pkg:pkg');
    expect($pkgGroup)->not->toBeNull();
    expect($pkgGroup['icon'])->toBe('package');
    expect($pkgGroup['count'])->toBe(1);
    expect($pkgGroup['sub_groups'][0]['label'])->toBe('设计');
    // 包 item key 带 origin 前缀(跨源同名 slug 高亮不撞),href 带 src
    $item = $pkgGroup['sub_groups'][0]['items'][0];
    expect($item['key'])->toBe('pkg:设计/pkg篇');
    expect($item['href'])->toContain('src=pkg');
    // host item 无前缀 / 无 src
    expect($nav[0]['items'][0]['key'])->toBe('指南/host篇');
    expect($nav[0]['items'][0]['href'])->not->toContain('src=');
});

it('写权硬线:vendor 拷贝包(非软链)save 硬拒', function () {
    $vendorPkg = makeDocsPkg(base_path('vendor/acme-docs/readonly-pkg'), 'acme-docs/readonly-pkg', 'AcmeDocs\\R\\');
    app()->instance(PackageRegistry::class, new PackageRegistry([$vendorPkg]));

    expect($this->repo->sourceWritable('readonly-pkg'))->toBeFalse();
    expect(fn () => $this->repo->save('x', '内容', 'readonly-pkg'))->toThrow(InvalidArgumentException::class);
    expect(file_exists($vendorPkg . '/docs/x.md'))->toBeFalse();

    (new Filesystem)->deleteDirectory(base_path('vendor/acme-docs'));
});

it('all() 按 group→order→title 排序;firstSlug 取最前', function () {
    $this->repo->save('b', "---\ntitle: B\ngroup: 指南\norder: 2\n---\n");
    $this->repo->save('a', "---\ntitle: A\ngroup: 指南\norder: 1\n---\n");
    $slugs = array_column($this->repo->all(), 'slug');
    expect($slugs)->toBe(['a', 'b']);
    expect($this->repo->firstSlug())->toBe('a');
});

it('tree() 产出 side-tree 分组结构(key/label/count/items)', function () {
    $this->repo->save('指南/x', "---\ngroup: 指南\n---\n");
    $tree = $this->repo->tree();
    expect($tree)->toHaveCount(1);
    expect($tree[0]['label'])->toBe('指南');
    expect($tree[0]['count'])->toBe(1);
    expect($tree[0]['items'][0]['data']['doc'])->toBe('指南/x');
});

it('下划线前缀文件不进列表(草稿/局部约定)', function () {
    $this->repo->save('_draft', "草稿\n");
    $this->repo->save('keep', "保留\n");
    expect(array_column($this->repo->all(), 'slug'))->toBe(['keep']);
});

it('路径穿越被拒:save/find/delete 对 .. 与控制符', function () {
    expect(fn () => $this->repo->save('../evil', 'x'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->repo->save('a/../../evil', 'x'))->toThrow(InvalidArgumentException::class);
    expect($this->repo->find('../../etc/passwd'))->toBeNull();
    // 没有越界文件被写出
    expect(file_exists($this->sandbox . '/evil.md'))->toBeFalse();
});

it('readonly 时 save/delete 硬拒', function () {
    $this->repo->save('x', "正文\n");
    config(['scaffold.config_ui.readonly' => true]);
    expect(fn () => $this->repo->save('x', '改'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->repo->delete('x'))->toThrow(InvalidArgumentException::class);
});

it('delete() 移除文件', function () {
    $this->repo->save('tmp', "正文\n");
    expect($this->repo->exists('tmp'))->toBeTrue();
    $this->repo->delete('tmp');
    expect($this->repo->exists('tmp'))->toBeFalse();
});

// ---- 边界 probe(loop 自测加固) ----

it('isValidSlug 拒绝:首点段 / 反斜杠 / 超长 / 纯点段', function () {
    expect(fn () => $this->repo->save('.git/hook', 'x'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->repo->save('a/.hidden', 'x'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->repo->save('a\\b', 'x'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->repo->save(str_repeat('x', 201), 'x'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->repo->save('a/./b', 'x'))->toThrow(InvalidArgumentException::class);
});

it('save() 规范 CRLF→LF 且补末尾换行', function () {
    $this->repo->save('crlf', "---\r\ntitle: X\r\n---\r\n\r\n第一行\r\n第二行");
    $raw = file_get_contents($this->sandbox . '/docs/crlf.md');
    expect($raw)->not->toContain("\r");
    expect(substr($raw, -1))->toBe("\n");
    expect($this->repo->find('crlf')['title'])->toBe('X');
});

it('坏 YAML frontmatter → meta 退空,正文仍剥离 frontmatter(不静默吞正文)', function () {
    $this->repo->save('bad', "---\nfoo: [unclosed\n---\n\n# 正文\n");
    $doc = $this->repo->find('bad');
    expect(trim($doc['body']))->toBe('# 正文');
    expect($doc['title'])->toBe('bad');   // meta 无 title → 退文件名
});

it('tags 写成逗号串(中英文混)→ 归一成数组', function () {
    $this->repo->save('t', "---\ntitle: T\ntags: a, b，c\n---\n");
    $row = collect($this->repo->all())->firstWhere('slug', 't');
    expect($row['tags'])->toBe(['a', 'b', 'c']);
});

it('save 自动建多级嵌套目录', function () {
    $this->repo->save('深/二/三/doc', "正文\n");
    expect(is_file($this->sandbox . '/docs/深/二/三/doc.md'))->toBeTrue();
    expect($this->repo->find('深/二/三/doc'))->not->toBeNull();
});

it('扩展名大小写不敏感:.MD 也列出', function () {
    @mkdir($this->sandbox . '/docs', 0755, true);
    file_put_contents($this->sandbox . '/docs/UP.MD', "---\ntitle: 大写\n---\n");
    expect(collect($this->repo->all())->pluck('slug')->all())->toContain('UP');
});
