<?php declare(strict_types=1);

use Mooeen\Scaffold\Designer\CompactBlockedException;
use Mooeen\Scaffold\Designer\MigrationCompacter;
use Mooeen\Scaffold\Designer\SchemaLoader;

/**
 * plan-49 MigrationCompacter 单测。
 *
 * 覆盖:
 *   - happy path:1 create + N updates → compact → 1 create rewritten + N 文件删
 *   - rename / drop 中间态 → CompactBlockedException
 *   - 无 create / 多 create → CompactBlockedException
 *   - preview 返结构(create_file / update_files / preview_php / schema_drift / git_pushed)
 *
 * scope-out(单测复杂度太高 / 需真 DB / 真 git remote):
 *   - schema drift 检测细粒度(需 mock Schema facade,这里 happy path 走 no_db_table 分支即可)
 *   - git push 检测细粒度(需真 git remote,e2e spec 里覆盖)
 */
beforeEach(function () {
    // tmp dir for yaml schemas
    $this->yamlDir = sys_get_temp_dir() . '/scaffold-compact-yaml-' . uniqid('', true) . '/database';
    mkdir($this->yamlDir, 0777, true);
    config(['scaffold.database.schema' => $this->yamlDir . '/']);

    // tmp dir for migrations(MigrationCompacter::migrationPath 用 database_path('migrations'))
    $this->migDir = sys_get_temp_dir() . '/scaffold-compact-mig-' . uniqid('', true);
    mkdir($this->migDir . '/migrations', 0777, true);
    app()->useDatabasePath($this->migDir);

    // forget singletons,让 DI 重新 resolve 新 config
    app()->forgetInstance(SchemaLoader::class);
    app()->forgetInstance(MigrationCompacter::class);
    $this->compacter = app(MigrationCompacter::class);
    $this->loader    = app(SchemaLoader::class);
});

afterEach(function () {
    if (is_dir(dirname($this->yamlDir))) {
        shell_exec('rm -rf ' . escapeshellarg(dirname($this->yamlDir)));
    }
    if (is_dir($this->migDir)) {
        shell_exec('rm -rf ' . escapeshellarg($this->migDir));
    }
});

// ─── 文件扫描 + rename/drop/multi/no-create 兜底 ──────────────────────────

it('findTableMigrationFiles returns 1 create + N updates sorted', function () {
    // seed 4 file:1 create + 3 update
    touch($this->migDir . '/migrations/2026_05_21_120000_create_demo_users_table.php');
    touch($this->migDir . '/migrations/2026_05_21_150000_update_demo_users_table.php');
    touch($this->migDir . '/migrations/2026_05_21_170000_update_demo_users_table.php');
    touch($this->migDir . '/migrations/2026_05_23_103000_update_demo_users_table.php');
    touch($this->migDir . '/migrations/2026_05_21_120000_create_other_table.php');     // 干扰

    $files = $this->compacter->findTableMigrationFiles('demo_users', 'Demo');
    expect(basename($files['create']))->toBe('2026_05_21_120000_create_demo_users_table.php');
    expect(count($files['updates']))->toBe(3);
    expect(basename($files['updates'][0]))->toBe('2026_05_21_150000_update_demo_users_table.php');
    expect(basename($files['updates'][2]))->toBe('2026_05_23_103000_update_demo_users_table.php');     // sorted ASC
});

it('findTableMigrationFiles throws on rename intermediate state', function () {
    touch($this->migDir . '/migrations/2026_05_21_120000_create_demo_users_table.php');
    touch($this->migDir . '/migrations/2026_05_22_100000_rename_old_to_demo_users_table.php');
    touch($this->migDir . '/migrations/2026_05_21_150000_update_demo_users_table.php');

    expect(fn () => $this->compacter->findTableMigrationFiles('demo_users', 'Demo'))
        ->toThrow(CompactBlockedException::class, '暂不支持表名变更后的合并历史');
});

it('findTableMigrationFiles throws on drop intermediate state', function () {
    touch($this->migDir . '/migrations/2026_05_21_120000_create_demo_users_table.php');
    touch($this->migDir . '/migrations/2026_05_22_100000_drop_demo_users_table.php');

    expect(fn () => $this->compacter->findTableMigrationFiles('demo_users', 'Demo'))
        ->toThrow(CompactBlockedException::class, '暂不支持表被 drop 后的合并历史');
});

it('findTableMigrationFiles throws when no create file', function () {
    touch($this->migDir . '/migrations/2026_05_21_150000_update_demo_users_table.php');     // 只有 update,没 create

    expect(fn () => $this->compacter->findTableMigrationFiles('demo_users', 'Demo'))
        ->toThrow(CompactBlockedException::class, '找不到 demo_users 的 create migration 文件');
});

it('findTableMigrationFiles throws when multiple create files (异常状态)', function () {
    touch($this->migDir . '/migrations/2026_05_21_120000_create_demo_users_table.php');
    touch($this->migDir . '/migrations/2026_05_22_120000_create_demo_users_table.php');

    expect(fn () => $this->compacter->findTableMigrationFiles('demo_users', 'Demo'))
        ->toThrow(CompactBlockedException::class, '有 2 个 create 文件');
});

// ─── happy path:execute 真删 update + 改写 create ───────────────────────

it('execute rewrites create + deletes updates(end-to-end,文件物理删除)', function () {
    // 1) 准备 yaml(SchemaLoader 能 loadNormalized)
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'demo_users', '演示用户');
    // 注:createTable 默认建 id / 系统时间戳字段,真实 user 经 designer save 加业务列

    // 2) seed migration 文件(内容随意,只测合并后 create 文件被改写 + update 被删)
    $migDir = $this->migDir . '/migrations';
    file_put_contents($migDir . '/2026_05_21_120000_create_demo_users_table.php', '<?php // stub create');
    file_put_contents($migDir . '/2026_05_21_150000_update_demo_users_table.php', '<?php // stub update 1');
    file_put_contents($migDir . '/2026_05_21_170000_update_demo_users_table.php', '<?php // stub update 2');

    // 3) execute(不开 clean_db / force)
    //    本地无 git push,detectGitPushed 返 [] → 通过
    $result = $this->compacter->execute('Demo', 'demo_users', ['clean_db' => false]);

    // 4) 验:create 文件还在 + 内容改了;update 都删了
    expect(file_exists($migDir . '/2026_05_21_120000_create_demo_users_table.php'))->toBeTrue();
    $newCreate = file_get_contents($migDir . '/2026_05_21_120000_create_demo_users_table.php');
    expect($newCreate)->not->toBe('<?php // stub create');     // 被改写
    expect($newCreate)->toContain('Schema::create');           // 真正的 migration 内容
    expect($newCreate)->toContain('demo_users');

    expect(file_exists($migDir . '/2026_05_21_150000_update_demo_users_table.php'))->toBeFalse();
    expect(file_exists($migDir . '/2026_05_21_170000_update_demo_users_table.php'))->toBeFalse();

    expect($result['rewritten'])->toBe('2026_05_21_120000_create_demo_users_table.php');
    expect($result['deleted'])->toHaveCount(2);
    expect($result['db_cleaned'])->toBe(0);
});

// ─── preview 结构 ───────────────────────────────────────────────

it('preview returns expected structure (create_file/update_files/preview_php/drift/git)', function () {
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'demo_users', '演示用户');

    $migDir = $this->migDir . '/migrations';
    touch($migDir . '/2026_05_21_120000_create_demo_users_table.php');
    touch($migDir . '/2026_05_21_150000_update_demo_users_table.php');

    $preview = $this->compacter->preview('Demo', 'demo_users');

    expect($preview)->toHaveKeys(['create_file', 'update_files', 'preview_php', 'schema_drift', 'git_pushed']);
    expect($preview['create_file'])->toBe('2026_05_21_120000_create_demo_users_table.php');
    expect($preview['update_files'])->toBe(['2026_05_21_150000_update_demo_users_table.php']);
    expect($preview['preview_php'])->toContain('Schema::create');
    expect($preview['preview_php'])->toContain('demo_users');
    // drift 在 sqlite/无 DB 测试环境 走 no_db_table 分支
    expect($preview['schema_drift'])->toBeArray();
    expect($preview['git_pushed'])->toBeArray();
});

it('preview throws when table not in yaml', function () {
    $this->loader->createSchema('Demo', '演示');

    $migDir = $this->migDir . '/migrations';
    touch($migDir . '/2026_05_21_120000_create_orphan_table.php');

    expect(fn () => $this->compacter->preview('Demo', 'orphan'))
        ->toThrow(CompactBlockedException::class, 'yaml 里找不到 Demo.orphan');
});

// ─── git push 守护(本地 repo / 无 remote 时跳过 → 允许) ────────────────

it('execute proceeds when no remote configured(本地无 push) ', function () {
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'demo_users', '演示用户');

    $migDir = $this->migDir . '/migrations';
    file_put_contents($migDir . '/2026_05_21_120000_create_demo_users_table.php', '<?php // stub');
    file_put_contents($migDir . '/2026_05_21_150000_update_demo_users_table.php', '<?php // stub');

    // tmp dir 不在 git repo,detectGitPushed 返 [](提前 return on git rev-parse 失败)
    $result = $this->compacter->execute('Demo', 'demo_users');
    expect($result['deleted'])->toHaveCount(1);
});

// ─── 2026-06-11 加固:删除假成功 / git fail-closed / rename 正则误伤 ─────────

it('execute:update 删除失败 → 不算已删、出 warning、文件保留(原先假成功还会误清 migrations 表)', function () {
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'demo_users', '演示用户');

    $migDir = $this->migDir . '/migrations';
    file_put_contents($migDir . '/2026_05_21_120000_create_demo_users_table.php', '<?php // stub');
    file_put_contents($migDir . '/2026_05_21_150000_update_demo_users_table.php', '<?php // u1');
    file_put_contents($migDir . '/2026_05_21_170000_update_demo_users_table.php', '<?php // u2');
    $u1 = $migDir . '/2026_05_21_150000_update_demo_users_table.php';

    // 部分 mock Filesystem:只让 u1 的 delete 失败,其余照常
    $fs = Mockery::mock(\Illuminate\Filesystem\Filesystem::class)->makePartial();
    $fs->shouldReceive('delete')->with($u1)->andReturn(false);

    $compacter = new \Mooeen\Scaffold\Designer\MigrationCompacter(
        app(SchemaLoader::class),
        app(\Mooeen\Scaffold\Designer\MigrationWriter::class),
        app(\Mooeen\Scaffold\Designer\GitInspector::class),
        $fs,
        base_path(),
    );

    $result = $compacter->execute('Demo', 'demo_users');

    expect($result['deleted'])->toBe(['2026_05_21_170000_update_demo_users_table.php']);   // 只报真删掉的
    expect(implode(' ', $result['warnings']))->toContain('删除失败');
    expect(file_exists($u1))->toBeTrue();                                                  // 失败的文件还在
});

it('detectGitPushed:detached HEAD 无法确认推送状态 → fail-closed 拒绝(原先静默放行)', function () {
    // 造一个 detached HEAD 的真 git repo
    $repo = sys_get_temp_dir() . '/scaffold-compact-git-' . uniqid('', true);
    mkdir($repo, 0777, true);
    shell_exec('cd ' . escapeshellarg($repo) . ' && git init -q && git config user.email t@t.t && git config user.name t'
        . ' && touch a.txt && git add a.txt && git commit -qm init && git checkout -q --detach');

    try {
        $compacter = new \Mooeen\Scaffold\Designer\MigrationCompacter(
            app(SchemaLoader::class),
            app(\Mooeen\Scaffold\Designer\MigrationWriter::class),
            new \Mooeen\Scaffold\Designer\GitInspector(cwd: $repo),
            new \Illuminate\Filesystem\Filesystem,
            $repo,
        );

        expect(fn () => $compacter->detectGitPushed([$repo . '/x.php']))
            ->toThrow(CompactBlockedException::class, 'detached HEAD');
    } finally {
        shell_exec('rm -rf ' . escapeshellarg($repo));
    }
});

it('rename 正则不误伤近邻表名(rename_user_logs_to_xxx 不拦 user 表;真 rename 仍拦)', function () {
    $migDir = $this->migDir . '/migrations';
    touch($migDir . '/2026_05_21_120000_create_user_table.php');
    touch($migDir . '/2026_05_22_100000_rename_user_logs_to_audit_logs_table.php');   // 近邻表,不该拦

    $files = $this->compacter->findTableMigrationFiles('user', 'Demo');
    expect(basename($files['create']))->toBe('2026_05_21_120000_create_user_table.php');

    // 真·本表 rename 仍是 blocker
    touch($migDir . '/2026_05_23_100000_rename_user_to_users_table.php');
    expect(fn () => $this->compacter->findTableMigrationFiles('user', 'Demo'))
        ->toThrow(CompactBlockedException::class, '暂不支持表名变更');
});

it('detectGitPushed:Laravel 在 git 仓子目录(engine/)+ 已推送文件 → 必须命中(2026-06-11 真机揭穿的恒放行洞)', function () {
    $base = sys_get_temp_dir() . '/scaffold-compact-sub-' . uniqid('', true);
    $root = $base . '/repo';
    $bare = $base . '/origin.git';
    mkdir($root . '/engine/database/migrations', 0777, true);
    $mig = $root . '/engine/database/migrations/2026_01_01_000000_create_demo_users_table.php';
    file_put_contents($mig, '<?php // pushed migration');

    shell_exec('git init -q --bare ' . escapeshellarg($bare));
    shell_exec('cd ' . escapeshellarg($root) . ' && git init -q -b master && git config user.email t@t.t && git config user.name t'
        . ' && git add -A && git commit -qm init'
        . ' && git remote add origin ' . escapeshellarg($bare) . ' && git push -qu origin master 2>/dev/null');

    try {
        // cwd = engine 子目录,模拟宿主 base_path ≠ repo root(宿主项目 布局)
        $compacter = new \Mooeen\Scaffold\Designer\MigrationCompacter(
            app(SchemaLoader::class),
            app(\Mooeen\Scaffold\Designer\MigrationWriter::class),
            new \Mooeen\Scaffold\Designer\GitInspector(cwd: $root . '/engine'),
            new \Illuminate\Filesystem\Filesystem,
            $root . '/engine',
        );

        $pushed = $compacter->detectGitPushed([$mig]);
        // 修复前:git log 在 engine/ 下按相对 repo root 的 pathspec 查 → 永远空 → 恒放行
        expect($pushed)->toBe([$mig]);
    } finally {
        shell_exec('rm -rf ' . escapeshellarg($base));
    }
});
