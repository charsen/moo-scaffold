<?php declare(strict_types=1);

use Mooeen\Scaffold\Designer\SchemaLoader;
use Mooeen\Scaffold\Designer\SchemaLoadException;
use Mooeen\Scaffold\Tests\Feature\Designer\Support\FixtureSchema;

/**
 * SchemaLoader 回归测试 — 只覆盖 readonly + 纯函数 API。
 *
 * 写操作(createTable / createSchema / deleteTable / saveModule)走 Playwright e2e 测,
 * Pest 单测不动 production yaml 避免污染。
 */
beforeEach(function () {
    // plan-37 P1-1:跑 bundled Demo + Laravel fixture,脱钩下游 production yaml(无需 PEST_HOST_SCAFFOLD_PATH)
    FixtureSchema::activate(app());
    $this->loader = app(SchemaLoader::class);
});

it('listSchemaFiles returns schemas in ksort + _fields.yaml excluded + Laravel always present', function () {
    // 不写死 schema 列表 — 各下游 engine fixture(宿主项目 / 某下游项目 / ...)schema 集合不同,
    // 测公共不变量就够:Laravel.yaml 任何 Laravel-based fixture 必有 / 下划线开头跳过 / keys 字典序
    $files = $this->loader->listSchemaFiles();
    expect($files)->toHaveKey('Laravel');
    expect($files)->not->toHaveKey('_fields');
    $keys   = array_keys($files);
    $sorted = $keys;
    sort($sorted, SORT_STRING);
    expect($keys)->toBe($sorted);
});

// applyRenameHints:改名时同步索引块里的字段名。原来只处理单字段索引(string 形式),
// 多字段复合索引(fields:[a,b])保留旧列名 → yaml 索引引用不存在的列、破坏下次 diff/migration(2026-06-09 修)。
it('applyRenameHints · 多字段复合索引随改名同步,单字段索引/字段集一并改', function () {
    $ref = new ReflectionMethod($this->loader, 'applyRenameHints');
    $ref->setAccessible(true);

    $yamlFields = ['col_a' => [], 'col_c' => []];
    $yamlTable  = ['index' => [
        'idx_ab' => ['type' => 'index', 'fields' => ['col_a', 'col_b']],  // 多字段复合
        'uq_c'   => ['type' => 'unique', 'fields' => 'col_c'],            // 单字段(string)
    ]];

    $ref->invokeArgs($this->loader, [&$yamlFields, &$yamlTable, ['col_a' => 'col_x']]);

    // 复合索引里 col_a → col_x,col_b 不动(bug 版本整条 fields 保留 ['col_a','col_b'])
    expect($yamlTable['index']['idx_ab']['fields'])->toBe(['col_x', 'col_b']);
    // 单字段索引不受影响
    expect($yamlTable['index']['uq_c']['fields'])->toBe('col_c');
    // 字段集本身也改名
    expect($yamlFields)->toHaveKey('col_x')->and($yamlFields)->not->toHaveKey('col_a');
});

it('listModules exposes name / tables_count / fields_count / locked / desc per schema', function () {
    $modules = $this->loader->listModules();
    expect($modules)->toHaveKey('Demo');
    $p = $modules['Demo'];
    expect($p)
        ->toHaveKey('folder')
        ->toHaveKey('name')
        ->toHaveKey('tables_count')
        ->toHaveKey('fields_count')
        ->toHaveKey('last_migration')
        ->toHaveKey('locked')
        ->toHaveKey('desc');
    expect($p['tables_count'])->toBeGreaterThan(0);
    expect($p['fields_count'])->toBeGreaterThan(0);
    expect($p['locked'])->toBeBool();
});

it('loadModule returns {name, folder, desc}', function () {
    $m = $this->loader->loadModule('Demo');
    expect($m)->toHaveKeys(['name', 'folder', 'desc']);
    expect($m['folder'])->toBe('demo');
});

it('loadStats aggregates modules / tables / fields / migrations counts', function () {
    $stats = $this->loader->loadStats();
    expect($stats)->toHaveKeys(['modules', 'tables', 'fields', 'migrations']);
    expect($stats['modules'])->toBeGreaterThan(0);
    expect($stats['tables'])->toBeGreaterThan(0);
    expect($stats['fields'])->toBeGreaterThan(0);
    // tables = sum of per-module tables_count
    $modules   = $this->loader->listModules();
    $sumTables = array_sum(array_column($modules, 'tables_count'));
    expect($stats['tables'])->toBe($sumTables);
});

it('yamlPath returns absolute path under the schema database dir', function () {
    $path = $this->loader->yamlPath('Demo');
    expect($path)->toEndWith('/Demo.yaml');
    expect($path)->toContain('/database/');
});

it('loadModuleTables returns per-table {key, name, locked, fields}', function () {
    $tables = $this->loader->loadModuleTables('Demo');
    expect($tables)->toHaveKey('demo_users');
    $r = $tables['demo_users'];
    expect($r)
        ->toHaveKey('key')
        ->toHaveKey('name')
        ->toHaveKey('locked')
        ->toHaveKey('fields');
    expect($r['key'])->toBe('demo_users');
    expect($r['fields'])->toBeInt()->toBeGreaterThan(0);
});

it('loadTableFull returns rich shape with attrs / fields / index', function () {
    $t = $this->loader->loadTableFull('Demo', 'demo_users');
    expect($t)
        ->toHaveKey('key')
        ->toHaveKey('name')
        ->toHaveKey('fields')
        ->toHaveKey('index')
        ->toHaveKey('enums')
        ->toHaveKey('prefix');
    expect($t['fields'])->toBeArray()->not->toBeEmpty();
    // fields 是 list of {key, name, type, ...} objects(shapeField 结果),不是 dict
    // 只锁 id / created_at / updated_at(MUST have)— deleted_at 是 yaml 可选字段(软删表才有),
    // 不能强 assertion 否则 yaml drift 时此 test 假阳;真实回归用下面 rebuildFieldRows 专项 test 锁
    $fieldKeys = array_column($t['fields'], 'key');
    expect($fieldKeys)->toContain('id');
    expect($fieldKeys)->toContain('created_at');
    expect($fieldKeys)->toContain('updated_at');
});

it('loadFromString parses inline yaml + returns normalized shape', function () {
    $yaml = <<<'YAML'
module:
    name: Demo
    folder: Demo
tables:
    demo_users:
        attrs: { name: 演示用户 }
        fields:
            id: {}
            user_name: { type: varchar, size: 64, name: 用户名 }
YAML;
    $r = $this->loader->loadFromString($yaml);
    expect($r)->toHaveKey('tables');
    expect($r['tables'])->toHaveKey('demo_users');
    expect($r['tables']['demo_users']['fields'])->toHaveKey('user_name');
});

it('loadFromString throws SchemaLoadException on invalid yaml', function () {
    $bad = "tables:\n    demo:\n      fields:\n  - id"; // bad indentation
    expect(fn () => $this->loader->loadFromString($bad))->toThrow(SchemaLoadException::class);
});

it('collectNamingSamples returns {key,name} pairs, deduped, capped, framework keys skipped', function () {
    $samples = $this->loader->collectNamingSamples(20);
    expect($samples)->toBeArray();
    expect(count($samples))->toBeLessThanOrEqual(20);
    $skip = ['id', 'parent_id', 'created_at', 'updated_at', 'deleted_at'];
    foreach ($samples as $s) {
        expect($s)->toHaveKey('key')->toHaveKey('name');
        expect($s['key'])->toBeString();
        expect($s['name'])->toBeString();
        // hardcoded skip list(business *_at like finished_at / cancelled_at 不在 skip 内,会保留)
        expect(in_array($s['key'], $skip, true))->toBeFalse();
        expect($s['key'])->toMatch('/^[a-z][a-z0-9_]*$/');     // snake_case 过滤生效
    }
});

it('loadRawText returns raw yaml content for a schema', function () {
    $raw = $this->loader->loadRawText('Demo');
    expect($raw)->toBeString();
    expect($raw)->toContain('tables:');
    expect($raw)->toContain('demo_users');
});

it('loadRawTableText returns yaml fragment for a single table', function () {
    $raw = $this->loader->loadRawTableText('Demo', 'demo_users');
    expect($raw)->toBeString();
    expect($raw)->toContain('demo_users');
});

it('loadModule throws SchemaLoadException for unknown schema', function () {
    expect(fn () => $this->loader->loadModule('NonExistentSchema_xyz'))
        ->toThrow(SchemaLoadException::class);
});

// ─── 2026-05-22:rebuildFieldRows · GUI 加 system 字段(yaml 原没有)写空 entry ──
// 之前 bug:platform_regions yaml 没声明 deleted_at,user 在 designer 加,save 完
// yaml 不写入(line 670 continue 跳过),GUI 重载又消失。修法:yaml 缺 system 字段时
// 写空 entry [],由 normalize 阶段(line 1155)派生 _system + type:timestamp。

it('rebuildFieldRows · client 加 yaml 缺的 system 字段 → 写空 entry', function () {
    $ref = new ReflectionMethod($this->loader, 'rebuildFieldRows');
    $ref->setAccessible(true);

    $yamlFields = [
        'id'   => [],
        'name' => ['type' => 'varchar', 'size' => 64],
    ];
    $clientFields = [
        ['name' => 'id', 'display_name' => null, 'index' => null],
        ['name' => 'name', 'display_name' => '姓名', 'type' => 'varchar', 'size' => 64],
        // user 在 designer 加的 system 字段,yaml 原没有
        ['name' => 'deleted_at', 'display_name' => null, 'index' => null],
        ['name' => 'created_at', 'display_name' => null, 'index' => null],
        ['name' => 'updated_at', 'display_name' => null, 'index' => null],
    ];
    $result = $ref->invoke($this->loader, $yamlFields, $clientFields);
    expect($result)->toHaveKeys(['id', 'name', 'deleted_at', 'created_at', 'updated_at']);
    expect($result['deleted_at'])->toBe([]);     // 空 entry,normalize 阶段会派生 _system + type:timestamp
    expect($result['created_at'])->toBe([]);
    expect($result['updated_at'])->toBe([]);
});

it('rebuildFieldRows · yaml 原有 system 字段保留(plan-40 P1 Round 2 不回归)', function () {
    $ref = new ReflectionMethod($this->loader, 'rebuildFieldRows');
    $ref->setAccessible(true);
    // yaml 原 deleted_at 有 desc 'soft-delete'(模拟 user 之前手编),client 给的是只 name 的 base entry
    // → server 应该保留 yaml 原 entry(line 671-672 array_key_exists 分支),不被 client base 覆盖
    $yamlFields = [
        'id'         => [],
        'deleted_at' => ['desc' => 'soft-delete', '_some_legacy' => 'keep'],
    ];
    $clientFields = [
        ['name' => 'id', 'display_name' => null, 'index' => null],
        ['name' => 'deleted_at', 'display_name' => null, 'index' => null],
    ];
    $result = $ref->invoke($this->loader, $yamlFields, $clientFields);
    expect($result['deleted_at'])->toBe(['desc' => 'soft-delete', '_some_legacy' => 'keep']);
});

// ─── plan-40 §三 R-14:unsigned 仅 numeric 类型,non-numeric 字段静默 strip + warn ──

it('R-14 · normalize strips unsigned: true on varchar field and warns', function () {
    $yaml = <<<'YAML'
module: { name: T, folder: T }
tables:
  t_demo:
    fields:
      bad_field: { type: varchar, size: 32, unsigned: true, name: 测试 }
YAML;
    $norm  = $this->loader->loadFromString($yaml, 'TestSchema');
    $field = $norm['tables']['t_demo']['fields']['bad_field'];
    expect($field['unsigned'])->toBeFalse();     // strip
    $warnMsgs = array_map(fn ($w) => $w['msg'] ?? '', $norm['warnings']);
    expect(implode("\n", $warnMsgs))->toContain('unsigned 仅对数值类型');
    expect(implode("\n", $warnMsgs))->toContain('varchar');
});

it('R-14 · normalize 保留 unsigned: true on int / decimal / float 类型', function () {
    $yaml = <<<'YAML'
module: { name: T, folder: T }
tables:
  t_demo:
    fields:
      good_int: { type: int, unsigned: true }
      good_decimal: { type: decimal, size: '10,2', unsigned: true }
      good_float: { type: float, unsigned: true }
YAML;
    $norm = $this->loader->loadFromString($yaml, 'TestSchema');
    expect($norm['tables']['t_demo']['fields']['good_int']['unsigned'])->toBeTrue();
    expect($norm['tables']['t_demo']['fields']['good_decimal']['unsigned'])->toBeTrue();
    expect($norm['tables']['t_demo']['fields']['good_float']['unsigned'])->toBeTrue();
});

// ─── Round 2 P2:type alias 兼容 ──────────────────────────────

it('canonicalizeType maps Laravel migration API names to scaffold canonical types', function () {
    $ref = new ReflectionMethod($this->loader, 'canonicalizeType');
    $ref->setAccessible(true);
    // Laravel API → scaffold canonical
    expect($ref->invoke($this->loader, 'longText'))->toBe('longtext');
    expect($ref->invoke($this->loader, 'mediumText'))->toBe('mediumtext');
    expect($ref->invoke($this->loader, 'tinyText'))->toBe('tinytext');
    expect($ref->invoke($this->loader, 'bigInteger'))->toBe('bigint');
    expect($ref->invoke($this->loader, 'smallInteger'))->toBe('smallint');
    expect($ref->invoke($this->loader, 'mediumInteger'))->toBe('mediumint');
    expect($ref->invoke($this->loader, 'tinyInteger'))->toBe('tinyint');
    expect($ref->invoke($this->loader, 'integer'))->toBe('int');
    expect($ref->invoke($this->loader, 'unsignedBigInteger'))->toBe('bigint');
    expect($ref->invoke($this->loader, 'string'))->toBe('varchar');
    expect($ref->invoke($this->loader, 'bool'))->toBe('boolean');
    expect($ref->invoke($this->loader, 'dateTime'))->toBe('datetime');     // strtolower 自动
    // 已是 canonical 形态保持不变
    expect($ref->invoke($this->loader, 'bigint'))->toBe('bigint');
    expect($ref->invoke($this->loader, 'varchar'))->toBe('varchar');
    // char 是一等 canonical 类型(UUID 主键 = char(36),MigrationWriter/生成器全链路支持),
    // 绝不能归一成 varchar —— 那会把定长列/UUID 主键改写成变长列
    expect($ref->invoke($this->loader, 'char'))->toBe('char');
});
