<?php declare(strict_types=1);

use Mooeen\Scaffold\Designer\SchemaDiffService;
use Mooeen\Scaffold\Designer\SchemaLoader;
use Mooeen\Scaffold\Designer\SnapshotStore;
use Mooeen\Scaffold\Tests\Feature\Designer\Support\FixtureSchema;

/**
 * SchemaDiffService 回归测试(plan 36 + plan-37 P1-1 fixture 化)。
 *
 * 「every schema」shape 测仍跑 production yaml(本来就只读 + 测 shape,无副作用风险)。
 * 「fresh snapshot is_empty=true」+ 「cold start = created」改用 fixture 隔绝(plan-37 P1-2 一并解)。
 */
beforeEach(function () {
    $this->diff     = app(SchemaDiffService::class);
    $this->snapshot = app(SnapshotStore::class);
});

it('returns a complete top-level shape for every schema', function (string $schema) {
    // plan-37 P1-1:跑 bundled fixture(Demo + Laravel),shape 契约 schema-agnostic,无需 production yaml
    $orig = FixtureSchema::activate(app());
    try {
        $result = app()->make(SchemaDiffService::class)->diff($schema);
        expect($result)
            ->toHaveKey('schema')
            ->toHaveKey('is_empty')
            ->toHaveKey('tables')
            ->toHaveKey('suspected_renames');
        expect($result)->not->toHaveKey('parser_warnings');     // plan 36 砍
        expect($result['schema'])->toBe($schema);
        expect($result['is_empty'])->toBeBool();
        expect($result['tables'])->toBeArray();
        expect($result['suspected_renames'])->toBeArray();
    } finally {
        FixtureSchema::deactivate(app(), $orig);
    }
})->with(function () {
    // 扫 fixture 目录,跟 SchemaLoader::listSchemaFiles 同算法(下划线开头跳过 + 去后缀)。
    // dataset 闭包在 Pest 收集阶段跑,不能用 app(),但可用 FixtureSchema 静态方法。
    $names = [];
    foreach (glob(FixtureSchema::fixtureDir() . '/*.yaml') ?: [] as $f) {
        $base = basename($f, '.yaml');
        if ($base !== '' && $base[0] !== '_') {
            $names[] = $base;
        }
    }
    sort($names, SORT_STRING);

    return $names;
});

it('marks each table with one of unchanged / updated / created / dropped', function () {
    $orig = FixtureSchema::activate(app());
    try {
        $result = app()->make(SchemaDiffService::class)->diff(FixtureSchema::SCHEMA);
        expect($result['tables'])->not->toBeEmpty();
        foreach ($result['tables'] as $tableKey => $tableDiff) {
            expect($tableDiff)->toHaveKey('status');
            expect($tableDiff['status'])->toBeIn(['unchanged', 'updated', 'created', 'dropped']);
        }
    } finally {
        FixtureSchema::deactivate(app(), $orig);
    }
});

it('reports is_empty=true immediately after snapshot capture (plan 36 核心, fixture)', function () {
    // plan 36 关键性质:baseline == current,diff 应当 is_empty=true
    // P1-1 fixture 化:Demo schema baseline 跟 source byte-identical,跑测稳定
    $orig = FixtureSchema::activate(app());
    try {
        $diff   = app()->make(SchemaDiffService::class);
        $result = $diff->diff(FixtureSchema::SCHEMA);
        expect($result['is_empty'])->toBeTrue();
    } finally {
        FixtureSchema::deactivate(app(), $orig);
    }
});

it('treats missing snapshot as cold start — all tables show as created (fixture)', function () {
    // P1-1 + P1-2 fixture 化:不再用 rename($path, $backup) — fixture sandbox 可丢
    $orig = FixtureSchema::activate(app());
    try {
        $snap     = app()->make(SnapshotStore::class);
        $snapPath = $snap->snapshotPath(FixtureSchema::SCHEMA);

        // sandbox 复制一份 snapshot 出来 mv 走
        $sandbox = sys_get_temp_dir() . '/scaffold_diff_test_' . uniqid();
        @mkdir($sandbox . '/.snapshots', 0755, true);
        copy(FixtureSchema::sourcePath(), $sandbox . '/' . FixtureSchema::SCHEMA . '.yaml');
        // 故意不复 snapshot → 模拟冷启动
        app()['config']->set('scaffold.database.schema', $sandbox . '/');
        app()->forgetInstance(SchemaLoader::class);
        app()->forgetInstance(SnapshotStore::class);

        $diff   = app()->make(SchemaDiffService::class);
        $result = $diff->diff(FixtureSchema::SCHEMA);

        $statuses = array_unique(array_column($result['tables'], 'status'));
        expect($statuses)->toBe(['created']);
        expect($result['is_empty'])->toBeFalse();

        // sandbox cleanup
        foreach (glob($sandbox . '/.snapshots/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($sandbox . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($sandbox . '/.snapshots');
        @rmdir($sandbox);
    } finally {
        FixtureSchema::deactivate(app(), $orig);
    }
});

it('returns identical schema name in result', function () {
    // round-trip:diff()['schema'] 必 == 传入名。用 fixture 的 Demo + Laravel 两个 schema 验
    $orig = FixtureSchema::activate(app());
    try {
        $diff = app()->make(SchemaDiffService::class);
        foreach (['Demo', 'Laravel'] as $schema) {
            $result = $diff->diff($schema);
            expect($result['schema'])->toBe($schema);
        }
    } finally {
        FixtureSchema::deactivate(app(), $orig);
    }
});

// ─── Round 2 P1 #1 + plan-40 §四 A-2:findReverseDepsBatch grep -n + 批量 regression ──

it('findReverseDepsBatch returns hits per field with line number and snippet', function () {
    // 造 sandbox 含 app/<file.php> 引用 'sandbox_test_field',验 grep -Hn 输出格式 + 按字段分桶
    $sandbox = sys_get_temp_dir() . '/scaffold-revdeps-' . uniqid('', true);
    mkdir($sandbox . '/app', 0755, true);
    file_put_contents($sandbox . '/app/SomeRequest.php',
        "<?php\nreturn ['sandbox_test_field' => ['required', 'string', 'max:64']];\n");

    $origBase = base_path();
    app()->setBasePath($sandbox);
    try {
        $diff = app(SchemaDiffService::class);
        $ref  = new ReflectionMethod($diff, 'findReverseDepsBatch');
        $ref->setAccessible(true);
        $byField = $ref->invoke($diff, ['sandbox_test_field']);

        expect($byField)->toHaveKey('sandbox_test_field');
        $hits = $byField['sandbox_test_field'];
        expect($hits)->not->toBeEmpty();
        foreach ($hits as $hit) {
            // 升级后格式: "app/SomeRequest.php:2  return ['sandbox_test_field' => ...]"
            expect($hit)->toMatch('/^app\/SomeRequest\.php:\d+\s+/');
            expect($hit)->toContain("'sandbox_test_field'");
        }
        expect(count($hits))->toBeLessThanOrEqual(8);
    } finally {
        app()->setBasePath($origBase);
        shell_exec('rm -rf ' . escapeshellarg($sandbox));
    }
});

it('findReverseDepsBatch 一次 grep 多字段,按字段分桶(plan-40 §四 A-2)', function () {
    // 验:2 个字段一次 batch grep,按字段名分桶
    $sandbox = sys_get_temp_dir() . '/scaffold-revdeps-batch-' . uniqid('', true);
    mkdir($sandbox . '/app', 0755, true);
    file_put_contents($sandbox . '/app/MultiRequest.php',
        "<?php\nreturn [\n    'foo_field' => 'required',\n    'bar_field' => 'string',\n];\n");

    $origBase = base_path();
    app()->setBasePath($sandbox);
    try {
        $diff = app(SchemaDiffService::class);
        $ref  = new ReflectionMethod($diff, 'findReverseDepsBatch');
        $ref->setAccessible(true);
        $byField = $ref->invoke($diff, ['foo_field', 'bar_field', 'nonexistent_xyz']);

        expect($byField)->toHaveKeys(['foo_field', 'bar_field', 'nonexistent_xyz']);
        expect($byField['foo_field'])->not->toBeEmpty();
        expect($byField['bar_field'])->not->toBeEmpty();
        expect($byField['nonexistent_xyz'])->toBe([]);     // 未出现的字段桶空

        // 验各桶 hit 字符串确实含对应字段名
        expect($byField['foo_field'][0])->toContain("'foo_field'");
        expect($byField['bar_field'][0])->toContain("'bar_field'");
    } finally {
        app()->setBasePath($origBase);
        shell_exec('rm -rf ' . escapeshellarg($sandbox));
    }
});

it('findReverseDepsBatch skips too-short / illegal field names (noise reduction)', function () {
    $diff = app(SchemaDiffService::class);
    $ref  = new ReflectionMethod($diff, 'findReverseDepsBatch');
    $ref->setAccessible(true);
    // < 3 字符 / 非法 ident 应在入口被 filter,返回空 map
    expect($ref->invoke($diff, ['id', 'a', '1bad', 'CAPS']))->toBe([]);
});

// ─── plan-41 §三 A:reverse dep warning 加 dep_kind 分类 ─────────────────

it('classifyDepHit · *Trait.php hit 归 auto', function () {
    $diff = app(SchemaDiffService::class);
    $ref  = new ReflectionMethod($diff, 'classifyDepHit');
    $ref->setAccessible(true);
    expect($ref->invoke($diff, "app/Models/UserTrait.php:42  'foo' => fake()"))->toBe('auto');
    expect($ref->invoke($diff, "app/Models/Order/OrderItemTrait.php:5  \$casts = ['foo' => ...]"))->toBe('auto');
});

it('classifyDepHit · Enums/<Field>.php hit 归 auto(buildEnum 每次重写)', function () {
    $diff = app(SchemaDiffService::class);
    $ref  = new ReflectionMethod($diff, 'classifyDepHit');
    $ref->setAccessible(true);
    expect($ref->invoke($diff, 'app/Models/Order/Enums/NoteStatus.php:8  case foo = 1;'))->toBe('auto');
});

it('classifyDepHit · *Filter.php / Request / lang / api yaml / Seeder hit 归 manual', function () {
    $diff = app(SchemaDiffService::class);
    $ref  = new ReflectionMethod($diff, 'classifyDepHit');
    $ref->setAccessible(true);
    expect($ref->invoke($diff, "app/Http/Filters/OrderFilter.php:33  ->where('foo', \$v)"))->toBe('manual');
    expect($ref->invoke($diff, "app/Http/Requests/Admin/Order/StoreRequest.php:18  'foo' => ['required']"))->toBe('manual');
    expect($ref->invoke($diff, "lang/zh_CN/model.php:55  'order_foo' => '中文'"))->toBe('manual');
    expect($ref->invoke($diff, 'scaffold/api/admin/order.yaml:33  foo: { type: string }'))->toBe('manual');
    expect($ref->invoke($diff, "database/seeders/OrderSeeder.php:22  'foo' => fake()->name()"))->toBe('manual');
    // Model.php(non-trait)
    expect($ref->invoke($diff, 'app/Models/Order.php:42  public function getFoo()'))->toBe('manual');
});

it('warningCheck REVERSE_DEP_DROP 输出含 dep_kind / dep_field / dep_hit', function () {
    // 整段路径走 warningCheck 太重,直接 unit 调 classifyDepHit + 验 warning array shape
    // 实际 e2e 覆盖在 designer.spec.ts 的 preview drawer round-trip
    $diff = app(SchemaDiffService::class);
    $ref  = new ReflectionMethod($diff, 'warningCheck');
    $ref->setAccessible(true);

    // 造 fieldChanges:1 个 drop;mock depMap 通过 reflection 设置 私有 - 这里走真 grep 太脆,
    // 改用 sandbox base_path 让 findReverseDepsBatch 真扫一个 fixture
    $sandbox = sys_get_temp_dir() . '/scaffold-warning-' . uniqid('', true);
    mkdir($sandbox . '/app/Models', 0755, true);
    file_put_contents($sandbox . '/app/Models/UserTrait.php',
        "<?php\nreturn ['drop_test_field' => 'auto']; // codegen 每次重写\n");
    file_put_contents($sandbox . '/app/SomeFilter.php',
        "<?php\nreturn ['drop_test_field' => 'manual'];\n");

    $origBase = base_path();
    app()->setBasePath($sandbox);
    try {
        $fieldChanges = [
            ['op' => 'drop', 'field' => 'drop_test_field'],
        ];
        $warnings = $ref->invoke($diff, $fieldChanges, [], []);

        // 找 REVERSE_DEP_DROP warnings
        $deps = array_values(array_filter($warnings, fn ($w) => ($w['code'] ?? '') === 'REVERSE_DEP_DROP'));
        expect($deps)->not->toBeEmpty();
        foreach ($deps as $w) {
            expect($w)->toHaveKey('dep_kind');
            expect($w)->toHaveKey('dep_field');
            expect($w)->toHaveKey('dep_hit');
            expect(in_array($w['dep_kind'], ['auto', 'manual'], true))->toBeTrue();
            expect($w['dep_field'])->toBe('drop_test_field');
        }

        // 至少有 1 auto(UserTrait.php) + 1 manual(SomeFilter.php)
        $kinds = array_column($deps, 'dep_kind');
        expect(in_array('auto', $kinds, true))->toBeTrue();
        expect(in_array('manual', $kinds, true))->toBeTrue();
    } finally {
        app()->setBasePath($origBase);
        shell_exec('rm -rf ' . escapeshellarg($sandbox));
    }
});

// ─── Round 2 P2:baseline_missing flag for UI banner ──────────────

it('exposes baseline_missing=true when .snapshots/{schema}.yaml absent (fixture cold start)', function () {
    $orig = FixtureSchema::activate(app());
    try {
        // FixtureSchema 默认 byte-aligned baseline 存在;先 mv snapshot 走再测
        $snap     = app(SnapshotStore::class);
        $snapPath = $snap->snapshotPath(FixtureSchema::SCHEMA);

        if (file_exists($snapPath)) {
            $backup = $snapPath . '.bak-' . uniqid();
            rename($snapPath, $backup);
            try {
                $diff   = app(SchemaDiffService::class);
                $result = $diff->diff(FixtureSchema::SCHEMA);
                expect($result)->toHaveKey('baseline_missing');
                expect($result['baseline_missing'])->toBeTrue();
            } finally {
                rename($backup, $snapPath);
            }
        } else {
            // snapshot 本来就没有 → 直接验
            $diff   = app(SchemaDiffService::class);
            $result = $diff->diff(FixtureSchema::SCHEMA);
            expect($result['baseline_missing'])->toBeTrue();
        }
    } finally {
        FixtureSchema::deactivate(app(), $orig);
    }
});

it('exposes baseline_missing=false when snapshot present', function () {
    $orig = FixtureSchema::activate(app());
    try {
        $diff   = app(SchemaDiffService::class);
        $result = $diff->diff(FixtureSchema::SCHEMA);
        expect($result['baseline_missing'])->toBeFalse();
    } finally {
        FixtureSchema::deactivate(app(), $orig);
    }
});

// ─── enum-key 默认值 diff(2026-06-10 修)──────────────────────────────────
// int/float 列 `default: <enum-key>`(如 `default: high`,migration 端 resolveEnumDefault 映射成
// 实际 int),原 normalizeDefault 用 (int)$raw 把所有 enum key 塌成 0 → 改默认值(high→low)
// 被判"无变化"、不生成 migration,DB 默认值留旧。

it('normalizeDefault:enum-key int 默认值原样保留(不塌成 0),数值字面仍强转', function () {
    $ref = new ReflectionMethod(SchemaDiffService::class, 'normalizeDefault');
    $ref->setAccessible(true);
    $svc = app(SchemaDiffService::class);

    expect($ref->invoke($svc, 'high', 'int'))->toBe('high');
    expect($ref->invoke($svc, 'low', 'int'))->toBe('low');
    // 数值字面:'5' 与 5 归一相等
    expect($ref->invoke($svc, '5', 'int'))->toBe(5);
    expect($ref->invoke($svc, 5, 'int'))->toBe(5);
    // decimal 同理
    expect($ref->invoke($svc, 'pending', 'decimal'))->toBe('pending');
});

it('defaultsEqual:改 enum-key int 默认值被识别为不等(bug 版本两者都 (int)0 → 漏报)', function () {
    $ref = new ReflectionMethod(SchemaDiffService::class, 'defaultsEqual');
    $ref->setAccessible(true);
    $svc = app(SchemaDiffService::class);

    expect($ref->invoke($svc, 'high', 'low', 'int'))->toBeFalse();   // bug: true(0===0)
    expect($ref->invoke($svc, 'high', 'high', 'int'))->toBeTrue();
    expect($ref->invoke($svc, '5', 5, 'int'))->toBeTrue();           // 数值跨类型仍相等
});

/* ---------------------------------------------------------------------------
 * filterToTable — moo:migration / moo:free 的 -t 单表模式(纯静态,手造 diff 即可测)
 * ------------------------------------------------------------------------ */

function diffSvc_fakeDiff(): array
{
    return [
        'schema'           => 'Demo',
        'is_empty'         => false,
        'baseline_missing' => false,
        'tables'           => [
            'demo_users' => ['status' => 'updated',   'field_changes' => [['op' => 'add', 'field' => 'age']], 'index_changes' => [], 'warnings' => []],
            'demo_logs'  => ['status' => 'unchanged', 'field_changes' => [], 'index_changes' => [], 'warnings' => []],
            'demo_old'   => ['status' => 'dropped',   'field_changes' => [], 'index_changes' => [], 'warnings' => []],
        ],
        'suspected_renames' => [
            ['table' => 'demo_users', 'drop' => 'name', 'add' => 'full_name'],
            ['table' => 'demo_old',   'drop' => 'x',    'add' => 'y'],
        ],
    ];
}

it('filterToTable 只留指定表 + 该表的 suspected_renames(updated → is_empty=false)', function () {
    $out = SchemaDiffService::filterToTable(diffSvc_fakeDiff(), 'demo_users');
    expect(array_keys($out['tables']))->toBe(['demo_users']);
    expect($out['suspected_renames'])->toHaveCount(1);
    expect($out['suspected_renames'][0]['table'])->toBe('demo_users');
    expect($out['is_empty'])->toBeFalse();
});

it('filterToTable 收窄到 unchanged 表 → is_empty=true + 无 rename', function () {
    $out = SchemaDiffService::filterToTable(diffSvc_fakeDiff(), 'demo_logs');
    expect(array_keys($out['tables']))->toBe(['demo_logs']);
    expect($out['is_empty'])->toBeTrue();
    expect($out['suspected_renames'])->toBe([]);
});

it('filterToTable 收窄到 dropped 表 → is_empty=false + 仅该表 rename', function () {
    $out = SchemaDiffService::filterToTable(diffSvc_fakeDiff(), 'demo_old');
    expect($out['is_empty'])->toBeFalse();
    expect($out['suspected_renames'])->toHaveCount(1);
    expect($out['suspected_renames'][0]['table'])->toBe('demo_old');
});

it('filterToTable 表 key 不存在 → null', function () {
    expect(SchemaDiffService::filterToTable(diffSvc_fakeDiff(), 'no_such_table'))->toBeNull();
});
