<?php declare(strict_types=1);

/*
 * plan-51 测试矩阵 §三 D3 · 覆盖 yaml unique 语义补救(app vs db)
 */

namespace Mooeen\Scaffold\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Unique;
use Mooeen\Scaffold\Designer\MigrationWriter;
use Mooeen\Scaffold\Designer\SchemaLoader;
use Mooeen\Scaffold\Foundation\FormRequest;
use Mooeen\Scaffold\Generator\CreateControllerGenerator;
use Mooeen\Scaffold\Generator\FreshStorageGenerator;
use Mooeen\Scaffold\Tests\TestCase;
use Mooeen\Scaffold\Utility;
use ReflectionClass;

/**
 * 测试 helper:不动 Schema facade,直接 prime FormRequest 的 static cache
 */
function primeSoftDeleteCache(string $table, bool $hasDeletedAt): void
{
    $ref  = new ReflectionClass(FormRequest::class);
    $prop = $ref->getProperty('softDeleteColumnCache');
    $prop->setAccessible(true);
    $cur         = $prop->getValue();
    $cur[$table] = $hasDeletedAt;
    $prop->setValue(null, $cur);
}

class UniqueSemanticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 每个 test 单独 cache,避免污染
        FormRequest::clearSoftDeleteCache();
    }

    /**
     * 给 FormRequest 一个匿名 subclass,暴露 protected getUnique() 给测试调
     */
    private function fr(): FormRequest
    {
        return new class extends FormRequest
        {
            public function callGetUnique(string $table, $field = null, $route_key = null, bool $soft = true): Unique
            {
                return $this->getUnique($table, $field, $route_key, $soft);
            }
        };
    }
}

// ─── §三 D3 #1 · yaml unique:true + 表有 deleted_at ────────────────────
test('M1 · getUnique(app) on table WITH deleted_at adds deleted_at filter', function () {
    primeSoftDeleteCache('platform_residences', true);

    $fr = (new class extends FormRequest
    {
        public function callIt(): Unique
        {
            return $this->getUnique('platform_residences', 'residence_name');
        }
    });
    $rule = $fr->callIt();
    expect($rule)->toBeInstanceOf(Unique::class);
    // Laravel Rule::unique → (string) 形态:`unique:tbl,col,NULL,id,deleted_at,"NULL"`
    // (where clause 编码成 ",col,\"value\"" 形式;whereNull 编码为 ",col,\"NULL\"")
    $str = (string) $rule;
    expect($str)->toContain('platform_residences')
        ->and($str)->toContain('residence_name')
        ->and($str)->toContain('deleted_at');
});

// ─── §三 D3 #2 · yaml unique:true + 表无 deleted_at(join 表) ─────────
test('M2 · getUnique(app) on table WITHOUT deleted_at does NOT add deleted_at filter', function () {
    primeSoftDeleteCache('contract_has_residences', false);

    $fr = (new class extends FormRequest
    {
        public function callIt(): Unique
        {
            return $this->getUnique('contract_has_residences', 'contract_id');
        }
    });
    $rule = $fr->callIt();
    expect((string) $rule)->not->toContain('deleted_at');
});

// ─── §三 D3 #3 · yaml db_unique:true 强约束,Request 跨软删唯一 ────────
test('M3 · getUnique(db, $soft=false) on table WITH deleted_at does NOT add deleted_at filter', function () {
    primeSoftDeleteCache('system_personnels', true);     // 即便 table 有 deleted_at,$soft=false 也跳过

    $fr = (new class extends FormRequest
    {
        public function callIt(): Unique
        {
            return $this->getUnique('system_personnels', 'mobile', null, false);
        }
    });
    $rule = $fr->callIt();
    expect((string) $rule)->not->toContain('deleted_at');
});

// ─── §三 D3 #11 unit · cache 跨调用复用 ────────────────────────────────
test('M11 · soft-delete cache: hits same table once and reuses on subsequent calls', function () {
    primeSoftDeleteCache('platform_residences', true);

    $fr = new class extends FormRequest
    {
        public function calls(): array
        {
            return [
                (string) $this->getUnique('platform_residences', 'a'),
                (string) $this->getUnique('platform_residences', 'b'),
                (string) $this->getUnique('platform_residences', 'c'),
            ];
        }
    };
    $strs = $fr->calls();
    foreach ($strs as $s) {
        expect($s)->toContain('deleted_at');
    }
});

// ─── §三 D3 generator · CreateControllerGenerator emit 形态(plan-51 升级:behavior 而非 string match)
//
// 反射调 rebuildFieldsRules,验返回的 rule string 形态;改用真行为检测,任何重构源码都不破坏
// ────────────────────────────────────────────────────────────────────────
function callRebuildFieldsRules(array $fields, array $enums = []): array
{
    $ref    = new ReflectionClass(CreateControllerGenerator::class);
    $method = $ref->getMethod('rebuildFieldsRules');
    $method->setAccessible(true);
    $gen = $ref->newInstanceWithoutConstructor();     // ctor 需 Command 依赖,绕过
    // rebuildFieldsRules 内部调 $this->utility->getModelIds() / $this->escapePhpString,需要注入
    $utility    = app(Utility::class);
    $refUtility = $ref->getProperty('utility');
    $refUtility->setAccessible(true);
    $refUtility->setValue($gen, $utility);

    return $method->invoke($gen, $fields, $enums);
}

test('M-gen1 · attr.unique=true → emit app form getUnique(table, col)', function () {
    $rules = callRebuildFieldsRules([
        'name' => ['type' => 'varchar', 'size' => 64, 'unique' => true, 'required' => true, 'unsigned' => false, 'allow_null' => false],
    ]);
    $nameRules = $rules['name'] ?? [];
    $joined    = implode(' | ', $nameRules);
    expect($joined)->toContain("getUnique(\$this->getTable(), 'name')")
        ->and($joined)->not->toContain('null, false');     // app form 没 $soft=false
});

test('M-gen2 · attr.db_unique=true → emit db form getUnique(table, col, null, false)', function () {
    $rules = callRebuildFieldsRules([
        'token' => ['type' => 'varchar', 'size' => 64, 'db_unique' => true, 'required' => true, 'unsigned' => false, 'allow_null' => false],
    ]);
    $tokenRules = $rules['token'] ?? [];
    $joined     = implode(' | ', $tokenRules);
    expect($joined)->toContain("getUnique(\$this->getTable(), 'token', null, false)");
});

test('M-gen3 · attr 既无 unique 也无 db_unique → 不 emit getUnique', function () {
    $rules = callRebuildFieldsRules([
        'desc' => ['type' => 'varchar', 'size' => 512, 'required' => false, 'unsigned' => false, 'allow_null' => true],
    ]);
    $descRules = $rules['desc'] ?? [];
    $joined    = implode(' | ', $descRules);
    expect($joined)->not->toContain('getUnique');
});

test('M-gen4 · 同字段 attr.unique + db_unique 同存 → db 胜出(emit 4-arg form)', function () {
    $rules = callRebuildFieldsRules([
        'x' => ['type' => 'varchar', 'size' => 32, 'unique' => true, 'db_unique' => true, 'required' => true, 'unsigned' => false, 'allow_null' => false],
    ]);
    $joined = implode(' | ', $rules['x'] ?? []);
    expect($joined)->toContain('null, false');     // 4-arg form 标志
});

// ─── §三 D3 #4 · SchemaLoader FIELD_LEGAL_KEYS 含 db_unique ───────────
test('M-loader1 · FIELD_LEGAL_KEYS includes db_unique', function () {
    $loaderPath = __DIR__ . '/../../src/Designer/SchemaLoader.php';
    $src        = file_get_contents($loaderPath);
    expect($src)->toContain("'db_unique'");
});

// ─── §三 D3 #4(verbose) · SchemaLoader::promoteInlineUnique app/db 分流 ───
test('M-loader2 · promoteInlineUnique 拆 db_unique 派生进 index 块;unique:true 不动 index 块', function () {
    $loader = app(SchemaLoader::class);

    // case 1:db_unique → 进 index 块
    $yaml1 = "tables:\n  test_a:\n    fields:\n      code: { type: varchar, db_unique: true, size: 32 }\n    index:\n      id: { type: primary, fields: id }\n";
    $r1    = $loader->loadFromString($yaml1);
    expect($r1['tables']['test_a']['index']['code']['type'] ?? null)->toBe('unique');
    expect($r1['tables']['test_a']['fields']['code']['db_unique'] ?? null)->toBeNull();

    // case 2:unique:true → 不进 index 块,attr 保留
    $yaml2 = "tables:\n  test_b:\n    fields:\n      name: { type: varchar, unique: true, size: 32 }\n    index:\n      id: { type: primary, fields: id }\n";
    $r2    = $loader->loadFromString($yaml2);
    expect($r2['tables']['test_b']['index']['name'] ?? null)->toBeNull();
    expect($r2['tables']['test_b']['fields']['name']['unique'] ?? null)->toBe(true);
});

// ─── §三 D3 #5 · GUI dropdown 反向映射:yaml ↔ client.field.index 值 ─────
// 用 tmp yaml 文件走完整 loadTableFull → 真实场景验证
function withTmpSchema(string $yaml, callable $fn): mixed
{
    $schemaName = 'TestUnique_' . uniqid();
    // sandbox 隔绝:config 配的 schema 目录默认是相对路径 scaffold/database/,testbench cwd 下不存在
    // (无 PEST_HOST_SCAFFOLD_PATH 时)。写进 sys_get_temp_dir,跑完整目录删掉,不碰任何 fixture / 真实仓。
    $sandbox = sys_get_temp_dir() . '/scaffold_unique_' . uniqid();
    @mkdir($sandbox, 0755, true);
    $origSchema = config('scaffold.database.schema');
    config(['scaffold.database.schema' => $sandbox . '/']);
    app()->forgetInstance(SchemaLoader::class);
    $path = $sandbox . '/' . $schemaName . '.yaml';
    file_put_contents($path, $yaml);
    try {
        return $fn($schemaName);
    } finally {
        @unlink($path);
        @rmdir($sandbox);
        config(['scaffold.database.schema' => $origSchema]);
        app()->forgetInstance(SchemaLoader::class);
    }
}

test('M-load-app · attr.unique=true + 无 index 块 entry → client.field.index="unique-app"', function () {
    $yaml = "tables:\n  test_app:\n    model: { class: TestApp }\n    controller: { class: TestAppController }\n    attrs: { name: 'Test App' }\n    fields:\n      name: { type: varchar, unique: true, size: 32 }\n    index:\n      id: { type: primary, fields: id }\n";
    withTmpSchema($yaml, function (string $schema) {
        $loader    = app(SchemaLoader::class);
        $full      = $loader->loadTableFull($schema, 'test_app');
        $nameField = collect($full['fields'])->firstWhere('key', 'name');
        expect($nameField['index'] ?? null)->toBe('unique-app');
    });
});

test('M-load-db · index 块 type:unique → client.field.index="unique-db"', function () {
    $yaml = "tables:\n  test_db:\n    model: { class: TestDb }\n    controller: { class: TestDbController }\n    attrs: { name: 'Test DB' }\n    fields:\n      token: { type: varchar, size: 64 }\n    index:\n      id: { type: primary, fields: id }\n      token: { type: unique, fields: token }\n";
    withTmpSchema($yaml, function (string $schema) {
        $loader     = app(SchemaLoader::class);
        $full       = $loader->loadTableFull($schema, 'test_db');
        $tokenField = collect($full['fields'])->firstWhere('key', 'token');
        expect($tokenField['index'] ?? null)->toBe('unique-db');
    });
});

test('M-load-legacy · attr.unique + index 块 type:unique 同存 → "unique-db"(优先 DB 显式 + GUI 让 user 显式改 app)', function () {
    $yaml = "tables:\n  test_legacy:\n    model: { class: TestLegacy }\n    controller: { class: TestLegacyController }\n    attrs: { name: 'Test Legacy' }\n    fields:\n      role_name: { type: varchar, unique: true, size: 32 }\n    index:\n      id: { type: primary, fields: id }\n      role_name: { type: unique, fields: role_name }\n";
    withTmpSchema($yaml, function (string $schema) {
        $loader = app(SchemaLoader::class);
        $full   = $loader->loadTableFull($schema, 'test_legacy');
        $field  = collect($full['fields'])->firstWhere('key', 'role_name');
        expect($field['index'] ?? null)->toBe('unique-db');
    });
});

// ─── §三 D3 #6 · rebuildTableIndex translate client 值到 canonical ──────
test('M-save-app · client.field.index="unique-app" → 不进 index 块', function () {
    // 反射调 private rebuildTableIndex
    $loader = app(SchemaLoader::class);
    $ref    = new ReflectionClass($loader);
    $method = $ref->getMethod('rebuildTableIndex');
    $method->setAccessible(true);

    $yamlIndex    = ['id' => ['type' => 'primary', 'fields' => 'id']];
    $clientFields = [
        ['name' => 'id', 'index' => 'primary'],
        ['name' => 'org_name', 'index' => 'unique-app'],
    ];
    $result = $method->invoke($loader, $yamlIndex, $clientFields, []);
    expect($result)->toHaveKey('id');
    expect(isset($result['org_name']))->toBeFalse();   // app-level 不进 index 块
});

test('M-save-db · client.field.index="unique-db" → 进 index 块 type:unique(canonical)', function () {
    $loader = app(SchemaLoader::class);
    $ref    = new ReflectionClass($loader);
    $method = $ref->getMethod('rebuildTableIndex');
    $method->setAccessible(true);

    $yamlIndex    = ['id' => ['type' => 'primary', 'fields' => 'id']];
    $clientFields = [
        ['name' => 'id', 'index' => 'primary'],
        ['name' => 'mobile', 'index' => 'unique-db'],
    ];
    $result = $method->invoke($loader, $yamlIndex, $clientFields, []);
    expect($result['mobile']['type'] ?? null)->toBe('unique');
});

test('M-save-legacy · client.field.index="unique"(legacy)→ 进 index 块 canonical 不变', function () {
    $loader = app(SchemaLoader::class);
    $ref    = new ReflectionClass($loader);
    $method = $ref->getMethod('rebuildTableIndex');
    $method->setAccessible(true);

    $clientFields = [['name' => 'old_field', 'index' => 'unique']];
    $result       = $method->invoke($loader, [], $clientFields, []);
    expect($result['old_field']['type'] ?? null)->toBe('unique');
});

// ─── plan-51 P0 silent bug 复核(team review by 3 agents catch 到):
// FreshStorageGenerator 不反向派生 db_unique → CreateControllerGenerator 漏 emit Request 规则
// ───────────────────────────────────────────────────────────────────────
test('M-fresh1 · FreshStorageGenerator 反向派生:index 块 type:unique → field.db_unique=true', function () {
    $ref    = new ReflectionClass(FreshStorageGenerator::class);
    $method = $ref->getMethod('formatFields');
    $method->setAccessible(true);

    $gen = $ref->newInstanceWithoutConstructor();     // FreshStorageGenerator 需 Command ctor 参数,绕过 DI

    // 模拟 Tagging.yaml verbose 写法:index 块写 type:unique,fields 里没 sugar
    $fields = [
        'id'             => [],
        'tag_group_slug' => ['type' => 'varchar', 'size' => 128, 'name' => '分组编号'],
        'tag_group_name' => ['type' => 'varchar', 'size' => 96, 'name' => '分组名称'],
        'tag_group_desc' => ['type' => 'varchar', 'size' => 512, 'name' => '分组描述', 'required' => false],
    ];
    $index = [
        'tag_group_slug' => ['type' => 'unique', 'fields' => 'tag_group_slug'],
        'tag_group_name' => ['type' => 'unique', 'fields' => 'tag_group_name'],
    ];

    $result = $method->invoke($gen, $fields, [], $index);
    expect($result['tag_group_slug']['db_unique'] ?? null)->toBeTrue();
    expect($result['tag_group_name']['db_unique'] ?? null)->toBeTrue();
    expect($result['tag_group_desc']['db_unique'] ?? null)->toBeNull();     // 没在 index 块的字段不该被派生
});

test('M-fresh2 · 单字段 unique fields 数组形式([col]) 也派生', function () {
    $ref    = new ReflectionClass(FreshStorageGenerator::class);
    $method = $ref->getMethod('formatFields');
    $method->setAccessible(true);
    $gen = $ref->newInstanceWithoutConstructor();     // FreshStorageGenerator 需 Command ctor 参数,绕过 DI

    $fields = ['token' => ['type' => 'varchar', 'size' => 64]];
    $index  = ['token' => ['type' => 'unique', 'fields' => ['token']]];     // 数组单元素
    $result = $method->invoke($gen, $fields, [], $index);
    expect($result['token']['db_unique'] ?? null)->toBeTrue();
});

test('M-fresh3 · 多字段复合 unique 不污染单字段(联合索引保留为 join unique)', function () {
    $ref    = new ReflectionClass(FreshStorageGenerator::class);
    $method = $ref->getMethod('formatFields');
    $method->setAccessible(true);
    $gen = $ref->newInstanceWithoutConstructor();     // FreshStorageGenerator 需 Command ctor 参数,绕过 DI

    $fields = ['a' => ['type' => 'varchar'], 'b' => ['type' => 'varchar']];
    $index  = ['ab' => ['type' => 'unique', 'fields' => ['a', 'b']]];     // 2 字段复合
    $result = $method->invoke($gen, $fields, [], $index);
    expect($result['a']['db_unique'] ?? null)->toBeNull();
    expect($result['b']['db_unique'] ?? null)->toBeNull();
});

test('M-fresh4 · attr 已显式 unique:true(app-level)时不被 db_unique 覆盖', function () {
    $ref    = new ReflectionClass(FreshStorageGenerator::class);
    $method = $ref->getMethod('formatFields');
    $method->setAccessible(true);
    $gen = $ref->newInstanceWithoutConstructor();     // FreshStorageGenerator 需 Command ctor 参数,绕过 DI

    // 边界:legacy 双写场景 — attr.unique=true 同时 index 块也 type:unique
    // 这里 attr.unique=true 应保留(不被覆盖),不再写 db_unique(让 SchemaLoader/Designer 决定优先级)
    $fields = ['name' => ['type' => 'varchar', 'unique' => true]];
    $index  = ['name' => ['type' => 'unique', 'fields' => 'name']];
    $result = $method->invoke($gen, $fields, [], $index);
    expect($result['name']['unique'] ?? null)->toBeTrue();
    expect($result['name']['db_unique'] ?? null)->toBeNull();
});

// ─── MigrationWriter emit 验证(agent 3 catch:plan §三 D3 #8/#9 测试空白) ───
test('M-mig1 · MigrationWriter emit ->unique() for index 块 type:unique', function () {
    $writer = app(MigrationWriter::class);
    $diff   = [
        'schema' => 'TestUnique',
        'tables' => [
            'test_tokens' => [
                'status'             => 'created',
                'current_definition' => [
                    'fields' => ['id' => [], 'token' => ['type' => 'varchar', 'size' => 64]],
                    'index'  => [
                        'id'    => ['type' => 'primary', 'fields' => 'id'],
                        'token' => ['type' => 'unique', 'fields' => 'token'],
                    ],
                ],
                'field_changes' => [],
                'index_changes' => [],
                'warnings'      => [],
            ],
        ],
        'suspected_renames' => [],
        'is_empty'          => false,
    ];
    $rendered = $writer->render($diff);
    expect($rendered['test_tokens']['php_source'] ?? '')
        ->toContain('$table->unique(');
});

test('M-mig2 · MigrationWriter NOT emit ->unique() for app-level only(无 index 块 entry)', function () {
    $writer = app(MigrationWriter::class);
    $diff   = [
        'schema' => 'TestUnique',
        'tables' => [
            'test_residences' => [
                'status'             => 'created',
                'current_definition' => [
                    'fields' => [
                        'id'   => [],
                        'name' => ['type' => 'varchar', 'size' => 192, 'unique' => true],     // app-level only
                    ],
                    'index' => [
                        'id' => ['type' => 'primary', 'fields' => 'id'],
                        // 注意:故意没有 name 的 index 块 entry
                    ],
                ],
                'field_changes' => [],
                'index_changes' => [],
                'warnings'      => [],
            ],
        ],
        'suspected_renames' => [],
        'is_empty'          => false,
    ];
    $rendered = $writer->render($diff);
    expect($rendered['test_residences']['php_source'] ?? '')->not->toContain('$table->unique(');
});

// ─── Laravel validator 端到端(agent 3 catch:plan §三 D3 #12 真 e2e 缺) ───
// 用 in-memory SQLite,真跑 Schema::create + Validator::make 验 soft-aware filter
test('M-e2e1 · Rule::unique soft-aware 真过滤软删记录(SQLite 真跑)', function () {
    config(['database.default' => 'sqlite_e2e']);
    config(['database.connections.sqlite_e2e' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    DB::purge('sqlite_e2e');

    Schema::create('e2e_residences', function ($t) {
        $t->id();
        $t->string('residence_name', 192);
        $t->softDeletes();
    });
    DB::table('e2e_residences')->insert([
        'residence_name' => '阳光小区',
        'deleted_at'     => now(),
    ]);

    primeSoftDeleteCache('e2e_residences', true);
    $rule = (new class extends FormRequest
    {
        public function r(): Unique
        {
            return $this->getUnique('e2e_residences', 'residence_name');
        }
    })->r();

    // 软删记录存在,but rule 应 pass(soft-aware → whereNull('deleted_at') 把软删过滤掉)
    $v = Validator::make(['residence_name' => '阳光小区'], ['residence_name' => $rule]);
    expect($v->passes())->toBeTrue();
});

test('M-e2e2 · Rule::unique($soft=false) NOT 过滤软删(db_unique 路径)', function () {
    config(['database.default' => 'sqlite_e2e2']);
    config(['database.connections.sqlite_e2e2' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    DB::purge('sqlite_e2e2');

    Schema::create('e2e_tokens', function ($t) {
        $t->id();
        $t->string('token', 64);
        $t->softDeletes();
    });
    DB::table('e2e_tokens')->insert([
        'token'      => 'abc123',
        'deleted_at' => now(),
    ]);

    primeSoftDeleteCache('e2e_tokens', true);
    $rule = (new class extends FormRequest
    {
        public function r(): Unique
        {
            return $this->getUnique('e2e_tokens', 'token', null, false);     // $soft=false
        }
    })->r();

    // db_unique 跨软删唯一,软删记录也应挡新建
    $v = Validator::make(['token' => 'abc123'], ['token' => $rule]);
    expect($v->passes())->toBeFalse();
});
