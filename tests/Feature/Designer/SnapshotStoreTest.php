<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Designer\SchemaDiffService;
use Mooeen\Scaffold\Designer\SchemaLoader;
use Mooeen\Scaffold\Designer\SnapshotStore;
use Mooeen\Scaffold\Tests\Feature\Designer\Support\FixtureSchema;
use Symfony\Component\Yaml\Yaml;

/**
 * SnapshotStore 行为测试(plan 36 + plan-37 P1-1)。
 *
 * 重点验:
 *   - capture()       全量覆盖
 *   - captureTables() 只 merge 指定表子树 → P0/P1 核心修复
 *
 * 隔绝:用 fixtures/database/Demo.yaml,不动 production yaml。
 *   - beforeEach 把 fixture 拷到 sysTmp sandbox
 *   - 测试在 sandbox 上随便改
 *   - afterEach 删 sandbox(SIGKILL 也无所谓,反正 fixture 文件没动)
 */
beforeEach(function () {
    // sandbox = sys tmp / pid / test
    $this->sandbox = sys_get_temp_dir() . '/scaffold_snap_test_' . uniqid();
    @mkdir($this->sandbox . '/.snapshots', 0755, true);

    // copy fixture → sandbox
    copy(FixtureSchema::sourcePath(), $this->sandbox . '/Demo.yaml');
    copy(FixtureSchema::snapshotPath(), $this->sandbox . '/.snapshots/Demo.yaml');

    // 把 scaffold.database.schema 指向 sandbox
    app()['config']->set('scaffold.database.schema', $this->sandbox . '/');
    app()->forgetInstance(SchemaLoader::class);
    app()->forgetInstance(SnapshotStore::class);

    $this->snapshot = app(SnapshotStore::class);
    $this->diff     = app(SchemaDiffService::class);
    $this->fs       = app(Filesystem::class);

    $this->yamlPath = $this->sandbox . '/Demo.yaml';
    $this->snapPath = $this->snapshot->snapshotPath('Demo');
});

afterEach(function () {
    foreach (glob($this->sandbox . '/.snapshots/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($this->sandbox . '/.snapshots');
    foreach (glob($this->sandbox . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($this->sandbox);
    app()->forgetInstance(SchemaLoader::class);
    app()->forgetInstance(SnapshotStore::class);
});

it('captureTables only updates specified tables — P0 fix', function () {
    // 给 fixture 加一张额外的表(模拟多表场景)
    $parsed                         = Yaml::parseFile($this->yamlPath);
    $parsed['tables']['demo_extra'] = [
        'name'   => '额外表', 'desc' => '',
        'index'  => ['id' => ['type' => 'primary', 'fields' => 'id']],
        'fields' => ['id' => [], 'extra_name' => ['type' => 'varchar', 'size' => 32, 'name' => '额外名']],
    ];
    file_put_contents($this->yamlPath, Yaml::dump($parsed, 6, 4));

    // 同步把 demo_extra 也加进 snap(模拟 plan-36 init state — 已经 capture 过一轮的稳态)
    $snapData                         = Yaml::parseFile($this->snapPath);
    $snapData['tables']['demo_extra'] = $parsed['tables']['demo_extra'];
    file_put_contents($this->snapPath, Yaml::dump($snapData, 6, 4));

    // 现在改两张表各加一字段
    $parsed['tables']['demo_users']['fields']['p0_a'] = ['type' => 'varchar', 'size' => 32, 'name' => 'P0_A'];
    $parsed['tables']['demo_extra']['fields']['p0_b'] = ['type' => 'varchar', 'size' => 32, 'name' => 'P0_B'];
    file_put_contents($this->yamlPath, Yaml::dump($parsed, 6, 4));

    app()->forgetInstance(SchemaLoader::class);
    $diffSrv = app()->make(SchemaDiffService::class);

    $diff1 = $diffSrv->diff('Demo');
    expect($diff1['is_empty'])->toBeFalse();
    expect($diff1['tables']['demo_users']['status'])->toBe('updated');
    expect($diff1['tables']['demo_extra']['status'])->toBe('updated');

    // 模拟「只 migrate demo_users」
    $this->snapshot->captureTables('Demo', ['demo_users']);

    app()->forgetInstance(SchemaLoader::class);
    $diffSrv = app()->make(SchemaDiffService::class);
    $diff2   = $diffSrv->diff('Demo');

    // 关键:demo_users 已 unchanged,demo_extra 仍 drift
    expect($diff2['is_empty'])->toBeFalse();
    expect($diff2['tables']['demo_users']['status'])->toBe('unchanged');
    expect($diff2['tables']['demo_extra']['status'])->toBe('updated');
});

it('captureTables drops table from snapshot if removed from current yaml', function () {
    // 先给 snapshot 加一张 demo_extra
    $snapData                         = Yaml::parseFile($this->snapPath);
    $snapData['tables']['demo_extra'] = ['name' => '额外', 'desc' => '', 'index' => [], 'fields' => ['id' => []]];
    file_put_contents($this->snapPath, Yaml::dump($snapData, 6, 4));

    $this->snapshot->captureTables('Demo', ['demo_extra']);     // yaml 没有 → 应被删

    $snap = Yaml::parseFile($this->snapPath);
    expect($snap['tables'])->not->toHaveKey('demo_extra');
    expect($snap['tables'])->toHaveKey('demo_users');
});

it('captureTables no-op on empty tableKeys array', function () {
    $before = $this->fs->get($this->snapPath);
    $this->snapshot->captureTables('Demo', []);
    expect($this->fs->get($this->snapPath))->toBe($before);
});

it('captureTables creates snapshot from current when no snapshot exists', function () {
    $this->fs->delete($this->snapPath);
    expect($this->snapshot->exists('Demo'))->toBeFalse();

    $this->snapshot->captureTables('Demo', ['demo_users']);

    expect($this->snapshot->exists('Demo'))->toBeTrue();
    $snap = Yaml::parseFile($this->snapPath);
    expect($snap['tables'])->toHaveKey('demo_users');
});

it('capture() does full overwrite (snapshot:init / 全表 migrate 路径)', function () {
    // 改 demo_users 一个字段
    $parsed                                                   = Yaml::parseFile($this->yamlPath);
    $parsed['tables']['demo_users']['fields']['full_capture'] = ['type' => 'varchar', 'size' => 16];
    file_put_contents($this->yamlPath, Yaml::dump($parsed, 6, 4));

    $this->snapshot->capture('Demo');     // 全量

    // 2026-06-10:capture() 改走 YamlFormatter normalized(不再 verbatim byte-copy,跟 captureTables
    // 同格式避免 git churn)→ 验"全表都在 + 本次改动被 capture",而非字节相等。
    $snap = Yaml::parse($this->fs->get($this->snapPath));
    $yaml = Yaml::parse($this->fs->get($this->yamlPath));
    expect($snap['tables'])->toHaveKeys(array_keys($yaml['tables']));   // full overwrite:所有表
    expect($snap['tables']['demo_users']['fields'])->toHaveKey('full_capture');
});

// ─── 格式一致性(2026-06-10 修)──────────────────────────────────────────────
// capture() 原是 verbatim 拷贝(带注释 + 原 key 序),captureTables / unsetTables 走 normalized
// YamlFormatter::dump → 同一 baseline 文件随 migrate 路径在两种格式间横跳,git 跨成员同步 churn。
// 修后 capture() 也走 YamlFormatter → 两条路径产出完全一致。

it('capture() 与 captureTables(全表) 产出完全一致的 baseline 文件(格式不随 migrate 路径漂移)', function () {
    // A) 全量 capture
    $this->snapshot->capture('Demo');
    $afterCapture = $this->fs->get($this->snapPath);

    // B) 删掉重来,captureTables 覆盖全部表(冷启动 merge 全表 = 同一份数据)
    @unlink($this->snapPath);
    $tableKeys = array_keys((array) (Yaml::parse($this->fs->get($this->yamlPath))['tables'] ?? []));
    $this->snapshot->captureTables('Demo', $tableKeys);
    $afterCaptureTables = $this->fs->get($this->snapPath);

    // bug 版本:capture verbatim ≠ captureTables normalized
    expect($afterCapture)->toBe($afterCaptureTables);
    // 且都是合法 yaml、数据无损
    expect(Yaml::parse($afterCapture)['tables'])->toHaveKeys($tableKeys);
});

// ─── 失败路径可见化(2026-09-11)─────────────────────────────────────────────
// 原先两条失败路径都只 Log::warning,而 captureTables 是 void → MigrationWriter::write()
// 照旧返回 files_written → 4 个消费方向用户报成功(web 是绿色 toast「migration 已生成 N 个文件」)。
// 后果:baseline 没推进 ⇒ 下次 preview 重报本次变更 ⇒ 用户再点一次生成就产出重复 migration。
// 现在 captureTables 返回状态,调用方必须回报。这两条用例把两条失败路径钉住。

it('captureTables: 源 yaml 解析失败 → advanced=false + 原因，且不动 snapshot', function () {
    $before = $this->fs->get($this->snapPath);

    // 模拟 git 合并冲突标记落在 schema yaml 上(多人同步的真实场景)
    file_put_contents(
        $this->yamlPath,
        "<<<<<<< HEAD\ntables:\n    demo_users:\n        name: 用户\n=======\ntables:\n    demo_users:\n        name: 会员\n>>>>>>> other\n",
    );

    $res = $this->snapshot->captureTables('Demo', ['demo_users']);

    // bug 版本:void → 调用方拿不到任何信号
    expect($res['advanced'])->toBeFalse();
    expect($res['rebuilt_from_scratch'])->toBeFalse();
    expect($res['reason'])->toBeString()->toContain('baseline 未推进');
    // 提示可直接给用户看(带 ⚠)
    expect(SnapshotStore::baselineNote($res))->toStartWith('⚠ ');

    // baseline 没被碰过 —— 下次 diff 会照旧重报本次变更,所以文案必须提醒"别再点一次生成"
    expect($this->fs->get($this->snapPath))->toBe($before);
});

it('captureTables: snapshot 自身损坏 → 从零重建，并回报"其它表 baseline 已被丢弃"', function () {
    // 先让 snapshot 里有"其它表"的 baseline
    $snap                         = Yaml::parseFile($this->snapPath);
    $snap['tables']['demo_extra'] = ['name' => '额外', 'desc' => '', 'index' => [], 'fields' => ['id' => []]];
    file_put_contents($this->snapPath, Yaml::dump($snap, 6, 4));

    // 把 snapshot 弄坏(同:冲突标记落进 .snapshots/Demo.yaml,而它也是入 git 的共享文件)
    file_put_contents($this->snapPath, "tables: [unclosed\n");

    $res = $this->snapshot->captureTables('Demo', ['demo_users']);

    expect($res['advanced'])->toBeTrue();                    // 本次这几张表确实就位了
    expect($res['rebuilt_from_scratch'])->toBeTrue();        // 但代价是从零重建
    expect($res['reason'])->toBeString()->toContain('其它表的 baseline 已被丢弃');

    // 锁定这个(既有的)副作用:重建后只剩本次 capture 的表,demo_extra 的 baseline 没了
    // → 之后它会走 baseline_drift 拒生成。调用方看到 reason 才知道要去 git 还原。
    $after = Yaml::parse($this->fs->get($this->snapPath));
    expect($after['tables'])->toHaveKey('demo_users');
    expect($after['tables'])->not->toHaveKey('demo_extra');
});

it('captureTables: 正常路径返回 advanced=true / reason=null（前端据此不弹提示）', function () {
    $res = $this->snapshot->captureTables('Demo', ['demo_users']);

    expect($res)->toBe(['advanced' => true, 'rebuilt_from_scratch' => false, 'reason' => null]);
    expect(SnapshotStore::baselineNote($res))->toBe('');

    // 空 tableKeys 是 no-op,同样算"没有异常"
    expect($this->snapshot->captureTables('Demo', []))->toBe([
        'advanced' => true, 'rebuilt_from_scratch' => false, 'reason' => null,
    ]);
});
