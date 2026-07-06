<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Designer\SchemaLoader;
use Mooeen\Scaffold\Designer\SchemaLoadException;
use Mooeen\Scaffold\Designer\SnapshotStore;
use Mooeen\Scaffold\Support\PackageRegistry;
use Mooeen\Scaffold\Tests\Feature\Designer\Support\FixtureSchema;

/**
 * plan-53 Phase 2:设计器出身模型单测。
 * host = FixtureSchema fixtures(Demo / Laravel);扩展包 = sys temp 假包(PkgDemo.yaml)。
 */

/** 造一个带 schema 的假扩展包,返回包根。 */
function makeOriginPkg(string $root, string $name = 'acme/moo-pkgdemo', string $ns = 'Acme\\PkgDemo\\', string $schema = 'PkgDemo'): string
{
    @mkdir($root . '/scaffold/database', 0755, true);
    @mkdir($root . '/src', 0755, true);
    file_put_contents($root . '/composer.json', json_encode([
        'name'     => $name,
        'autoload' => ['psr-4' => [$ns => 'src/']],
    ], JSON_UNESCAPED_SLASHES));
    file_put_contents($root . "/scaffold/database/{$schema}.yaml", <<<YAML
module:
    folder: {$schema}
    name: 包演示模块
tables:
    pkg_items:
        attrs: { name: 包条目 }
        fields:
            id: {  }
            title: { name: 标题, type: varchar, size: 64 }
YAML);

    return $root;
}

beforeEach(function () {
    FixtureSchema::activate(app());
    $this->sandbox = sys_get_temp_dir() . '/scaffold_origin_' . uniqid();
    $this->pkgRoot = makeOriginPkg($this->sandbox . '/moo-pkgdemo');
    app()->instance(PackageRegistry::class, new PackageRegistry([$this->pkgRoot]));
    app()->forgetInstance(SchemaLoader::class);
    $this->loader = app(SchemaLoader::class);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->sandbox);
});

it('listSchemaFiles 聚合 host + 扩展包;originOf 按出身返回', function () {
    $files = $this->loader->listSchemaFiles();
    expect($files)->toHaveKey('Demo');       // host fixture
    expect($files)->toHaveKey('PkgDemo');    // 包 schema
    expect($files['PkgDemo'])->toBe(realpath($this->pkgRoot . '/scaffold/database/PkgDemo.yaml'));

    expect($this->loader->originOf('Demo'))->toBeNull();
    expect($this->loader->originOf('PkgDemo'))->toBe('moo-pkgdemo');
    expect($this->loader->originOf('NotExist'))->toBeNull();     // 未知按 host
});

it('yamlPath 按出身解析:包 schema 指向包目录', function () {
    expect($this->loader->yamlPath('PkgDemo'))->toBe($this->pkgRoot . '/scaffold/database/PkgDemo.yaml');
    expect($this->loader->yamlPath('Demo'))->toBe(rtrim(FixtureSchema::fixtureDir(), '/') . '/Demo.yaml');
});

it('schema 名跨源重名 → 抛错(全局唯一)', function () {
    // 往包里放一个跟 host 同名的 Demo.yaml
    copy(FixtureSchema::sourcePath(), $this->pkgRoot . '/scaffold/database/Demo.yaml');
    app()->forgetInstance(SchemaLoader::class);

    expect(fn () => app(SchemaLoader::class)->listSchemaFiles())
        ->toThrow(SchemaLoadException::class, '跨源重名');
});

it('migrationDirFor:包 schema 指向包内 database/migrations,host 指向 database_path', function () {
    expect($this->loader->migrationDirFor('PkgDemo'))->toBe($this->pkgRoot . '/database/migrations');
    expect($this->loader->migrationDirFor('Demo'))->toBe(rtrim(database_path('migrations'), '/'));
});

it('listModules 条目携带 origin(host=null / 包=key)', function () {
    $mods = $this->loader->listModules();
    expect($mods['Demo']['origin'])->toBeNull();
    expect($mods['PkgDemo']['origin'])->toBe('moo-pkgdemo');
});

it('SnapshotStore:包 schema 的快照落包内 .snapshots/(随包仓走)', function () {
    $store = app(SnapshotStore::class);
    expect($store->snapshotPath('PkgDemo'))->toBe($this->pkgRoot . '/scaffold/database/.snapshots/PkgDemo.yaml');

    $store->capture('PkgDemo');
    expect(is_file($this->pkgRoot . '/scaffold/database/.snapshots/PkgDemo.yaml'))->toBeTrue();
});

it('写权硬线:vendor 拷贝包(非软链)的 schema 一切写操作硬拒', function () {
    // 在 base_path('vendor') 里造一个拷贝形态的包(realpath 不逃出 vendor → 只读)
    $roPkg = makeOriginPkg(base_path('vendor/acme-ro/moo-ropkg'), 'acme-ro/moo-ropkg', 'AcmeRo\\Pkg\\', 'RoPkg');
    app()->instance(PackageRegistry::class, new PackageRegistry([$this->pkgRoot, $roPkg]));
    app()->forgetInstance(SchemaLoader::class);
    $loader = app(SchemaLoader::class);

    expect($loader->originOf('RoPkg'))->toBe('moo-ropkg');
    expect(fn () => $loader->createTable('RoPkg', 'x_items', '条目'))->toThrow(SchemaLoadException::class, '只读');
    expect(fn () => $loader->saveModule('RoPkg', ['tables' => []]))->toThrow(SchemaLoadException::class, '只读');
    expect(fn () => $loader->deleteTable('RoPkg', 'pkg_items'))->toThrow(SchemaLoadException::class, '只读');
    expect(fn () => $loader->deleteSchema('RoPkg'))->toThrow(SchemaLoadException::class, '只读');
    expect(fn () => $loader->renameSchema('RoPkg', 'RoPkgX'))->toThrow(SchemaLoadException::class, '只读');
    // yaml 未被动过
    expect(is_file($roPkg . '/scaffold/database/RoPkg.yaml'))->toBeTrue();

    (new Filesystem)->deleteDirectory(base_path('vendor/acme-ro'));
});

it('软链包(realpath 逃出 vendor)schema 可写:createTable 成功落包 yaml', function () {
    $link = base_path('vendor/acme-ln/moo-pkgdemo');
    @mkdir(dirname($link), 0755, true);
    @symlink($this->pkgRoot, $link);
    app()->instance(PackageRegistry::class, new PackageRegistry([$link]));
    app()->forgetInstance(SchemaLoader::class);
    $loader = app(SchemaLoader::class);

    $loader->createTable('PkgDemo', 'pkg_tags', '包标签');
    $raw = file_get_contents($this->pkgRoot . '/scaffold/database/PkgDemo.yaml');
    expect($raw)->toContain('pkg_tags');

    @unlink($link);
    (new Filesystem)->deleteDirectory(base_path('vendor/acme-ln'));
});

it('renameSchema 保出身:包内草稿 schema 改名后仍在包目录', function () {
    $this->loader->renameSchema('PkgDemo', 'PkgRenamed');
    expect(is_file($this->pkgRoot . '/scaffold/database/PkgRenamed.yaml'))->toBeTrue();
    expect(is_file($this->pkgRoot . '/scaffold/database/PkgDemo.yaml'))->toBeFalse();
    // 不应被搬进 host fixture 目录
    expect(is_file(rtrim(FixtureSchema::fixtureDir(), '/') . '/PkgRenamed.yaml'))->toBeFalse();
    // 还原(fixture 包在 sandbox,afterEach 会整删,但保持幂等)
    $this->loader->renameSchema('PkgRenamed', 'PkgDemo');
});

// ─── 2026-07-03 复盘审查修复的回归锁 ───────────────────────────────────

it('审查#1:renameSchema 跨源重名闸 — 包 schema 改成 host 已有名被拒(反之亦然)', function () {
    // 包 PkgDemo → host 已有的 Demo:同目录查不到,但全局闸必须拦下(否则 listSchemaFiles fail-fast 打死设计器)
    expect(fn () => $this->loader->renameSchema('PkgDemo', 'Demo'))
        ->toThrow(SchemaLoadException::class, '跨源全局唯一');
    // 没有产生跨源重名文件
    expect(is_file($this->pkgRoot . '/scaffold/database/Demo.yaml'))->toBeFalse();
    // host Demo → 包已有的 PkgDemo 同样被拒(Demo 是锁定态会先撞 draft 闸?— 用全局闸消息断言前先确认次序:全局闸在 draft 闸前)
    expect(fn () => $this->loader->renameSchema('Demo', 'PkgDemo'))
        ->toThrow(SchemaLoadException::class, '跨源全局唯一');
});

it('审查#2:SnapshotStore 写权闸 — vcs 拷贝包的 capture/captureTables/unsetTables 硬拒', function () {
    $roPkg = makeOriginPkg(base_path('vendor/acme-snap/moo-rosnap'), 'acme-snap/moo-rosnap', 'AcmeSnap\\P\\', 'RoSnap');
    app()->instance(PackageRegistry::class, new PackageRegistry([$this->pkgRoot, $roPkg]));
    app()->forgetInstance(SchemaLoader::class);
    app()->forgetInstance(SnapshotStore::class);
    $store = app(SnapshotStore::class);

    expect(fn () => $store->capture('RoSnap'))->toThrow(SchemaLoadException::class, '只读');
    expect(fn () => $store->captureTables('RoSnap', ['pkg_items']))->toThrow(SchemaLoadException::class, '只读');
    expect(fn () => $store->unsetTables('RoSnap', ['pkg_items']))->toThrow(SchemaLoadException::class, '只读');
    // vendor 拷贝没被弄脏
    expect(is_dir($roPkg . '/scaffold/database/.snapshots'))->toBeFalse();

    (new Filesystem)->deleteDirectory(base_path('vendor/acme-snap'));
});

it('审查#3:detectGitPushed 按出身切仓 — 包内已 push 的 migration 必须命中(不再被「repo 外」放行)', function () {
    // 把假包做成真 git 仓:migration commit + push 到本地 bare origin
    $pkg = $this->pkgRoot;
    @mkdir($pkg . '/database/migrations', 0755, true);
    $mig = $pkg . '/database/migrations/2026_01_01_000000_create_pkg_items_table.php';
    file_put_contents($mig, "<?php // probe\n");
    $bare = $this->sandbox . '/origin.git';
    shell_exec('git init -q --bare ' . escapeshellarg($bare));
    shell_exec('cd ' . escapeshellarg($pkg) . ' && git init -q -b main . && git add -A && git -c user.email=t@t -c user.name=t commit -qm x && git remote add origin ' . escapeshellarg($bare) . ' && git push -q origin main');

    app()->forgetInstance(\Mooeen\Scaffold\Designer\MigrationCompacter::class);
    $compacter = app(\Mooeen\Scaffold\Designer\MigrationCompacter::class);

    $pushed = $compacter->detectGitPushed([$mig], 'PkgDemo');
    expect($pushed)->toBe([$mig]);
});

it('审查#4:moo:fresh 表名跨源重复 fail-fast(不再静默 last-wins 覆盖)', function () {
    // 第二个包也定义 pkg_items(与 PkgDemo 同表名,schema 文件名不同 → 不触发 schema 名闸)
    $pkg2 = makeOriginPkg($this->sandbox . '/moo-dup', 'acme/moo-dup', 'Acme\\Dup\\', 'DupSchema');
    app()->instance(PackageRegistry::class, new PackageRegistry([$this->pkgRoot, $pkg2]));

    $gen = new \Mooeen\Scaffold\Generator\FreshStorageGenerator(
        new \Symfony\Component\Console\Output\NullOutput,
        app(\Illuminate\Filesystem\Filesystem::class),
        app(\Mooeen\Scaffold\Utility::class),
    );
    expect(fn () => $gen->start(clean: false, silence: true))
        ->toThrow(InvalidArgumentException::class, '表名跨 schema 重复');
});
