<?php declare(strict_types=1);

use Mooeen\Scaffold\Designer\MigrationWriter;
use Mooeen\Scaffold\Designer\SchemaLoader;
use Mooeen\Scaffold\Designer\SchemaLoadException;
use Mooeen\Scaffold\Designer\SnapshotStore;
use Symfony\Component\Yaml\Yaml;

/**
 * SchemaLoader 写操作单测 — 用 tmp dir 隔离,不动 production scaffold/database。
 *
 * 覆盖:
 *   - createSchema(name 校验 / 重复 / 落盘内容)
 *   - createTable(table_key 校验 / 重复 / schema 不存在 / 落盘内容)
 *   - deleteTable(table 不存在 / 落盘后节点消失)
 *   - saveModule 端到端 round-trip(client 数据 → yaml 写盘)
 *
 * 跟 Playwright e2e 互补:e2e 测端到端 user flow,这里测 PHP 层 API 直接调用 + 异常路径。
 */
beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/scaffold-loader-write-' . uniqid('', true) . '/database';
    mkdir($this->tmpDir, 0777, true);
    config(['scaffold.database.schema' => $this->tmpDir . '/']);
    // 重新 resolve SchemaLoader 让它读 new config
    app()->forgetInstance(SchemaLoader::class);
    $this->loader = app(SchemaLoader::class);
});

afterEach(function () {
    if (is_dir(dirname($this->tmpDir))) {
        shell_exec('rm -rf ' . escapeshellarg(dirname($this->tmpDir)));
    }
});

// ─── createSchema ──────────────────────────────────────────────

it('createSchema writes a minimal yaml with module block', function () {
    $this->loader->createSchema('TestModule', '测试模块', '描述');
    $path = $this->tmpDir . '/TestModule.yaml';
    expect(file_exists($path))->toBeTrue();
    $content = file_get_contents($path);
    expect($content)->toContain('module:');
    expect($content)->toContain('name: 测试模块');
    expect($content)->toContain('folder: TestModule');
    expect($content)->toContain('desc: 描述');
    expect($content)->toContain('tables: {}');
});

it('createSchema rejects non-PascalCase name', function () {
    expect(fn () => $this->loader->createSchema('lower_case', '名字'))
        ->toThrow(SchemaLoadException::class, 'PascalCase');
});

it('createSchema rejects empty display name', function () {
    expect(fn () => $this->loader->createSchema('Demo', ''))
        ->toThrow(SchemaLoadException::class, '必填');
});

it('createSchema rejects when yaml already exists', function () {
    $this->loader->createSchema('Demo', '演示');
    expect(fn () => $this->loader->createSchema('Demo', '演示'))
        ->toThrow(SchemaLoadException::class, '已存在');
});

// ─── createTable ──────────────────────────────────────────────

it('createTable appends new table node with id + creator/updater + softDeletes + timestamps defaults', function () {
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'demo_users', '演示用户', '描述', 'user');
    $content = file_get_contents($this->tmpDir . '/Demo.yaml');
    expect($content)->toContain('demo_users:');
    // yaml dump 不 quote 中文字符串(Symfony Yaml 默认行为),只验内容
    expect($content)->toContain('演示用户');
    expect($content)->toContain('prefix: user');
    expect($content)->toContain('id:');
    expect($content)->toContain('creator_id:');
    expect($content)->toContain('updater_id:');
    expect($content)->toContain('deleted_at:');
    expect($content)->toContain('created_at:');
    expect($content)->toContain('updated_at:');
    // 通用字段不带表 prefix(不是 user_creator_id)
    expect($content)->not->toContain('user_creator_id');
    expect($content)->not->toContain('user_updater_id');
    // name 已承担显示名,不要再写重复 comment。
    expect($content)->toContain('creator_id: { name: 创建人ID, type: bigint, unsigned: true }');
    expect($content)->toContain('updater_id: { name: 更新人ID, type: bigint, unsigned: true }');
    expect($content)->not->toContain('comment: 创建人 ID');
    expect($content)->not->toContain('comment: 更新人 ID');
    // 顺序:id → creator_id → updater_id → deleted_at → created_at → updated_at
    expect($content)->toMatch('/id:[\s\S]*creator_id:[\s\S]*updater_id:[\s\S]*deleted_at:[\s\S]*created_at:[\s\S]*updated_at:/');
});

it('createTable rejects non-snake_case table key', function () {
    $this->loader->createSchema('Demo', '演示');
    expect(fn () => $this->loader->createTable('Demo', 'BadKey', '名'))
        ->toThrow(SchemaLoadException::class, 'snake_case');
});

it('createTable rejects empty display name', function () {
    $this->loader->createSchema('Demo', '演示');
    expect(fn () => $this->loader->createTable('Demo', 'good_key', ''))
        ->toThrow(SchemaLoadException::class, '必填');
});

it('loadNormalized merges id defaults while preserving explicit id overrides', function () {
    file_put_contents($this->tmpDir . '/Demo.yaml', <<<'YAML'
module:
    name: 演示
    folder: Demo
tables:
    demo_users:
        attrs:
            name: 演示用户
        fields:
            id: {  }
    uuid_docs:
        attrs:
            name: UUID 文档
        fields:
            id: { type: char, size: 36, increment: false }
YAML);

    $normalized = $this->loader->loadNormalized('Demo');

    expect($normalized['tables']['demo_users']['fields']['id'])->toMatchArray([
        'name'     => 'ID',
        'type'     => 'bigint',
        'unsigned' => true,
        'required' => true,
        '_system'  => 'id',
    ]);
    expect($normalized['tables']['uuid_docs']['fields']['id'])->toMatchArray([
        'name'      => 'ID',
        'type'      => 'char',
        'size'      => 36,
        'unsigned'  => false,
        'increment' => false,
        'required'  => true,
        '_system'   => 'id',
    ]);
});

it('createTable rejects when schema does not exist', function () {
    expect(fn () => $this->loader->createTable('NonExistent', 'demo_users', '演示'))
        ->toThrow(SchemaLoadException::class, 'schema not found');
});

it('createTable rejects when table key already exists', function () {
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'demo_users', '演示用户');
    expect(fn () => $this->loader->createTable('Demo', 'demo_users', '再次'))
        ->toThrow(SchemaLoadException::class, '已存在');
});

// ─── deleteTable ──────────────────────────────────────────────

it('deleteTable removes table node from yaml', function () {
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'demo_users', '演示用户');
    $this->loader->createTable('Demo', 'demo_orders', '订单');
    $this->loader->deleteTable('Demo', 'demo_users');
    $content = file_get_contents($this->tmpDir . '/Demo.yaml');
    expect($content)->not->toContain('demo_users:');
    expect($content)->toContain('demo_orders:');     // 其他表保留
});

it('deleteTable rejects when schema does not exist', function () {
    expect(fn () => $this->loader->deleteTable('NonExistent', 'demo_users'))
        ->toThrow(SchemaLoadException::class, 'schema not found');
});

it('deleteTable rejects when table does not exist', function () {
    $this->loader->createSchema('Demo', '演示');
    expect(fn () => $this->loader->deleteTable('Demo', 'no_such_table'))
        ->toThrow(SchemaLoadException::class, 'table not found');
});

// ─── saveModule round-trip ────────────────────────────────────

it('saveModule writes table attrs / model / controller / fields back to yaml', function () {
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'demo_users', '初始名');

    // client shape 跟 DesignerController::save validate 一致:{module, tables: {key => tableData}}
    // designer.js _buildFieldEntry 把 f.key(英文 snake_case)放在字段的 'name' 字段里,
    // 中文显示名走 'display_name'(rebuildFieldRows:line 500 用 cField['name'] 作 yaml key)
    $client = [
        'module' => [],
        'tables' => [
            'demo_users' => [
                'name'       => '更新后的名',
                'desc'       => '加了 desc',
                'prefix'     => 'user',
                'model'      => ['class' => 'User'],
                'controller' => ['class' => 'UserController', 'app' => ['admin'], 'resource' => []],
                'fields'     => [
                    ['name' => 'id', 'display_name' => null],
                    ['name'    => 'user_name', 'display_name' => '用户名',
                        'type' => 'varchar', 'size' => 64, 'required' => true],
                ],
                'rename_hints'  => [],
                'multi_indexes' => [],
                'enums'         => [],
            ],
        ],
    ];
    $this->loader->saveModule('Demo', $client);

    $content = file_get_contents($this->tmpDir . '/Demo.yaml');
    // yaml dump 的 quote 风格不确定(单引号 / 双引号 / 无)— 只验内容存在
    expect($content)->toContain('更新后的名');
    expect($content)->toContain('user_name');     // 字段英文 key 作 yaml key
    expect($content)->toContain('用户名');           // 字段中文 name 作 attr
    expect($content)->toContain('User');             // model class
});

it('saveModule rejects when schema does not exist', function () {
    expect(fn () => $this->loader->saveModule('NonExistent', ['module' => [], 'tables' => []]))
        ->toThrow(SchemaLoadException::class);
});

// ─── regression: plan-40 P1 Round 2 bugfix ──────────────────

it('saveModule preserves system fields id/created_at/updated_at even when client only sends readonly base', function () {
    // bugfix:2026-05-20 platform_visitor_logs 翻车 — 用户改字段、预览、改回原样、save,
    // yaml 的 id / created_at / updated_at 整行丢失。根因:rebuildFieldRows 对 system field
    // 直接 continue,不写进 $newFields,saveModule 整块覆盖 yaml.fields。修法:保留 yaml 原 entry。
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'demo_users', '演示用户', '描述', 'user');

    $client = [
        'module' => [],
        'tables' => [
            'demo_users' => [
                'name'   => '演示用户',
                'fields' => [
                    // client 端 _buildFieldEntry:readonly system fields 只发 base 三件套
                    ['name' => 'id', 'display_name' => null, 'index' => null],
                    ['name' => 'user_name', 'display_name' => '用户名', 'type' => 'varchar', 'size' => 64],
                    ['name' => 'created_at', 'display_name' => null, 'index' => null],
                    ['name' => 'updated_at', 'display_name' => null, 'index' => null],
                ],
                'rename_hints'  => [],
                'multi_indexes' => [],
                'enums'         => [],
            ],
        ],
    ];
    $this->loader->saveModule('Demo', $client);

    $content = file_get_contents($this->tmpDir . '/Demo.yaml');
    // 必须保留 system field 行
    expect($content)->toMatch('/\bid\s*:\s*\{\s*\}/');
    expect($content)->toMatch('/\bcreated_at\s*:\s*\{\s*\}/');
    expect($content)->toMatch('/\bupdated_at\s*:\s*\{\s*\}/');
    expect($content)->toContain('user_name');     // 非系统字段也在
});

it('saveModule applyTableController persists app/resource even when class is empty (2026-05-20 bug)', function () {
    // user 反馈:designer 选了「生成到:后台管理/接口」+「Resource 到:接口」toggle,
    // 但 controller class 暂时未填(等代码生成时再填),save → 刷新页面后 toggle 状态丢失。
    // 根因:旧逻辑 if class === '' 提前 return,app/resource 数据不写盘。
    // 修法:class 跟 app/resource 解耦,都为空才整段 unset。
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'demo_users', '演示用户');

    $client = [
        'module' => [],
        'tables' => ['demo_users' => [
            'fields'       => [['name' => 'id', 'display_name' => null, 'index' => null]],
            'rename_hints' => [], 'multi_indexes' => [],
            'controller'   => [
                'class'    => '',                  // 暂未填
                'app'      => ['admin', 'api'],      // user 已选
                'resource' => ['api'],          // user 已选
            ],
        ]],
    ];
    $this->loader->saveModule('Demo', $client);

    $content = file_get_contents($this->tmpDir . '/Demo.yaml');
    // app/resource 持久化(即使 class 空)
    expect($content)->toContain('app:');
    expect($content)->toMatch('/app:\s*\[admin, api\]|admin\s*\n[\s-]+api/');
    expect($content)->toContain('resource:');
    expect($content)->toContain('api');
    // class 字段不该写盘(因为空)
    expect($content)->not->toMatch('/controller:\s*\n\s+class:\s*\'?\'?/');
});

it('saveModule applyTableController unsets controller entirely when class + app + resource all empty', function () {
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'demo_users', '演示用户');
    // 先写一份 controller 让 yaml 有 controller 块
    $this->loader->saveModule('Demo', [
        'module' => [],
        'tables' => ['demo_users' => [
            'fields'       => [['name' => 'id', 'display_name' => null, 'index' => null]],
            'rename_hints' => [], 'multi_indexes' => [],
            'controller'   => ['class' => 'UserController', 'app' => ['admin'], 'resource' => []],
        ]],
    ]);
    expect(file_get_contents($this->tmpDir . '/Demo.yaml'))->toContain('UserController');

    // user 把 class + app 都清掉
    $this->loader->saveModule('Demo', [
        'module' => [],
        'tables' => ['demo_users' => [
            'fields'       => [['name' => 'id', 'display_name' => null, 'index' => null]],
            'rename_hints' => [], 'multi_indexes' => [],
            'controller'   => ['class' => '', 'app' => [], 'resource' => []],
        ]],
    ]);
    $content = file_get_contents($this->tmpDir . '/Demo.yaml');
    expect($content)->not->toContain('UserController');
    // controller 整段 unset(没残留 controller: {} 空块)
    expect($content)->not->toMatch('/controller:\s*\{\s*\}/');
});

it('saveModule sanitizes enum label_zh / label_en — strip HTML + quote chars to prevent downstream XSS', function () {
    // Round 2 P2:enum label 写到 yaml,下游 i18n / admin 显示如果不 escape 会 XSS。
    // SchemaLoader 收口时 strip 危险字符(< > " ' \ + 控制字符),cap 64 长度。
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'demo_users', '演示用户');

    $client = [
        'module' => [],
        'tables' => [
            'demo_users' => [
                'fields' => [
                    ['name' => 'id', 'display_name' => null, 'index' => null],
                    ['name' => 'status', 'display_name' => '状态', 'type' => 'tinyint'],
                ],
                'enums' => [
                    ['field' => 'status', 'items' => [
                        ['key' => 'a', 'value' => 1, 'label_en' => 'A<script>', 'label_zh' => '正常"]'],
                        ['key' => 'b', 'value' => 2, 'label_en' => 'B', 'label_zh' => str_repeat('长', 80)],
                    ]],
                ],
                'rename_hints' => [], 'multi_indexes' => [],
            ],
        ],
    ];
    $this->loader->saveModule('Demo', $client);

    $content = file_get_contents($this->tmpDir . '/Demo.yaml');
    expect($content)->not->toContain('<script>');
    expect($content)->not->toContain('"]');
    // 长度 cap 64 — 中文 80 字应被截到 64
    expect($content)->not->toContain(str_repeat('长', 80));
    // 但合法部分保留
    expect($content)->toContain('Ascript');     // strip <> 后是 Ascript
    expect($content)->toContain('正常');         // strip " 后保留
});

it('saveModule writes row attrs in canonical order (no git diff noise from key reorder)', function () {
    // 真机 Test 3/5 polish:删字段 + 加回 / rename round-trip 后,row attr key 顺序
    // 不能从 `{ name, type }` 变成 `{ type, name }`(yaml 语义等价但 git diff 噪声)。
    // canonical 顺序参考本仓 yaml 习惯:required → name → type → size → unsigned → default → comment
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'demo_users', '演示用户');

    // 用 client shape 完全控制 row 写法(乱序 attr 输入),验证 saveModule 排序
    $this->loader->saveModule('Demo', [
        'module' => [],
        'tables' => ['demo_users' => [
            'fields' => [
                ['name' => 'id', 'display_name' => null, 'index' => null],
                // 乱序 attr 输入:type 在前,display_name 在后,size 在中间
                ['name'       => 'user_age', 'type' => 'tinyint', 'size' => 3,
                    'default' => 0, 'display_name' => '年龄', 'unsigned' => false],
            ],
            'rename_hints' => [], 'multi_indexes' => [],
        ]],
    ]);

    $content = file_get_contents($this->tmpDir . '/Demo.yaml');
    // user_age row 必须按 canonical 顺序:name → type → size → unsigned → default
    // 直接 regex 验证子串顺序而不是相等(YamlFormatter inline 形态可能微变)
    // 用 unsigned: false(显式 signed)做断言,因 unsigned: true 跟 codegen 默认一致被 strip。
    expect($content)->toMatch('/user_age:\s*\{[^}]*name[^}]+type[^}]+size[^}]+unsigned[^}]+default/');
});

it('saveModule preserves unsigned: true → false toggle round-trip (signed switch persists)', function () {
    // 2026-05-23 P0 plan_priority bug round 5:user 指出 codegen 规则被漏。
    // FreshStorageGenerator:227 `$attr['unsigned'] ?? true` — int/bigint/tinyint/decimal/float
    // 类型 yaml 没写 unsigned 默认 = true。
    //   - client 发 true:写 `unsigned: true`(idempotent,跟系统字段 creator_id/updater_id 兼容)
    //   - client 发 false:写 `unsigned: false`(**关键** — user 显式 signed 的唯一表达,不可 strip)
    // 旧 strip-on-false 把 user signed 选择吞掉(下次 load 派生回 true),已修正。
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'demo_users', '演示用户');

    // Round 1:client 发 unsigned: true → yaml 保留 `unsigned: true`
    $this->loader->saveModule('Demo', [
        'module' => [],
        'tables' => ['demo_users' => [
            'fields' => [
                ['name' => 'id', 'display_name' => null, 'index' => null],
                ['name' => 'visitable_id', 'display_name' => '多态ID', 'type' => 'bigint', 'unsigned' => true],
            ],
            'rename_hints' => [], 'multi_indexes' => [],
        ]],
    ]);
    expect(file_get_contents($this->tmpDir . '/Demo.yaml'))->toContain('unsigned: true');

    // Round 2:client 改主意切 signed,发 unsigned: false → yaml 必须切换为 `unsigned: false`
    $this->loader->saveModule('Demo', [
        'module' => [],
        'tables' => ['demo_users' => [
            'fields' => [
                ['name' => 'id', 'display_name' => null, 'index' => null],
                ['name' => 'visitable_id', 'display_name' => '多态ID', 'type' => 'bigint', 'unsigned' => false],
            ],
            'rename_hints' => [], 'multi_indexes' => [],
        ]],
    ]);
    $content = file_get_contents($this->tmpDir . '/Demo.yaml');
    expect($content)->toContain('unsigned: false');         // 显式 signed,保留
    expect($content)->not->toContain('unsigned: true');     // 旧 true 已被覆盖
    expect($content)->toContain('visitable_id');
});

it('saveModule preserves unsigned: false on numeric (explicit signed, not noise)', function () {
    // 2026-05-23 P0 round 5:codegen 默认 numeric 类型 unsigned=true(FreshStorageGenerator:227),
    // 所以 `unsigned: false` 是 user 显式 signed 的 **唯一表达方式**,不是噪声不能 strip。
    // 旧测试基于错误前提("yaml 不写 = false") — 已反转语义。
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'demo_users', '演示用户');

    $client = [
        'module' => [],
        'tables' => [
            'demo_users' => [
                'fields' => [
                    ['name' => 'id', 'display_name' => null, 'index' => null],
                    ['name'    => 'visitable_id', 'display_name' => '多态ID',
                        'type' => 'bigint', 'unsigned' => false],     // user 显式 signed
                ],
                'rename_hints'  => [],
                'multi_indexes' => [],
                'enums'         => [],
            ],
        ],
    ];
    $this->loader->saveModule('Demo', $client);

    $content = file_get_contents($this->tmpDir . '/Demo.yaml');
    // unsigned: false 必须保留(没它就丢了 signed 语义)
    expect($content)->toContain('unsigned: false');
    expect($content)->toContain('visitable_id');
    expect($content)->toContain('多态ID');
});

it('saveModule normalizes decimal compact size 10,2 to split form on save (no min/max mix-up)', function () {
    // 2026-05-23 round 5 audit:rebuildFieldRows 的 size 'min,max' 保留逻辑(round 1 round 4 加的)
    // 是为 varchar/char 'min,max' 语义写的。decimal/float/double 紧凑写法 size: '10,2' 是
    // '{M},{D}'='{size},{precision}' 完全不同的语义,误套会把 D 当 min 保 → user 改 size 时
    // yaml 写错位置('{newSize},{D}' 变成 '{origM},{newSize}',跟 precision 独立 key 冲突)。
    // 修法:此分支只对 varchar/char 适用;decimal 类型 fall through 到 coerce,归一为
    // size: <int> + 独立 precision: <int>(split 形式,跟仓内主流 yaml 一致)。
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'demo_users', '演示用户');

    // 手动 seed yaml 一个 decimal 紧凑 '10,2' 字段(模拟 user 手写的 legacy yaml)
    $yamlPath = $this->tmpDir . '/Demo.yaml';
    $raw      = file_get_contents($yamlPath);
    $raw      = preg_replace(
        '/(fields:\s*\n\s*id: \{[^}]*\})/',
        "$1\n            money: { name: 金额, type: decimal, size: '10,2' }",
        $raw,
    );
    file_put_contents($yamlPath, $raw);

    // Round 1:client 发 size=15(改 M),precision=2(原值未改)
    $this->loader->saveModule('Demo', [
        'module' => [],
        'tables' => ['demo_users' => [
            'fields' => [
                ['name' => 'id', 'display_name' => null, 'index' => null],
                ['name'    => 'money', 'display_name' => '金额', 'type' => 'decimal',
                    'size' => 15, 'precision' => 2],
            ],
            'rename_hints' => [], 'multi_indexes' => [],
        ]],
    ]);
    $data  = Yaml::parseFile($yamlPath);
    $money = $data['tables']['demo_users']['fields']['money'];

    // size 归一为 int(不是 '10,15' 也不是 '15,2' 拼接串)
    expect($money['size'])->toBe(15);
    // precision 落到独立 key 而不是埋在 size 紧凑串里
    expect($money['precision'])->toBe(2);

    // Round 2:client 单独改 precision=4(size 不变 15)— 验证 split form 已稳态,不再有紧凑串混淆
    $this->loader->saveModule('Demo', [
        'module' => [],
        'tables' => ['demo_users' => [
            'fields' => [
                ['name' => 'id', 'display_name' => null, 'index' => null],
                ['name'    => 'money', 'display_name' => '金额', 'type' => 'decimal',
                    'size' => 15, 'precision' => 4],
            ],
            'rename_hints' => [], 'multi_indexes' => [],
        ]],
    ]);
    $data  = Yaml::parseFile($yamlPath);
    $money = $data['tables']['demo_users']['fields']['money'];
    expect($money['size'])->toBe(15);
    expect($money['precision'])->toBe(4);
});

it('saveModule strips unsigned on non-numeric type (R-14 illegal combo)', function () {
    // R-14 兜底:varchar/char 不支持 unsigned,DevTools 绕 GUI disabled 直接 POST 也要拦下。
    // 同时 strip true 和 false(false 也是非法噪声)
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'demo_users', '演示用户');

    $this->loader->saveModule('Demo', [
        'module' => [],
        'tables' => ['demo_users' => [
            'fields' => [
                ['name' => 'id', 'display_name' => null, 'index' => null],
                ['name'    => 'nickname', 'display_name' => '昵称', 'type' => 'varchar',
                    'size' => 32, 'unsigned' => false],     // varchar 上 unsigned 非法
            ],
            'rename_hints' => [], 'multi_indexes' => [],
        ]],
    ]);
    $content = file_get_contents($this->tmpDir . '/Demo.yaml');
    expect($content)->not->toContain('unsigned');     // varchar 上完全 strip
    expect($content)->toContain('nickname');
});

// ─── audit metadata stamp(schema 元数据,非行字段) ────────────

it('createTable stamps created_by + created_at when author passed; updated_* left blank', function () {
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'demo_users', '演示用户', '', '', 'charsen');

    $data  = Yaml::parseFile($this->tmpDir . '/Demo.yaml');
    $attrs = $data['tables']['demo_users']['attrs'];

    expect($attrs['created_by'])->toBe('charsen');
    expect($attrs['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
    // 新建表不 stamp updated_*(首次 saveModule 才写)
    expect($attrs)->not->toHaveKey('updated_by');
    expect($attrs)->not->toHaveKey('updated_at');
});

it('createTable without author keeps attrs clean (no audit pollution on CLI / test paths)', function () {
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'demo_users', '演示用户');     // 不传 author

    $data  = Yaml::parseFile($this->tmpDir . '/Demo.yaml');
    $attrs = $data['tables']['demo_users']['attrs'];

    expect($attrs)->not->toHaveKey('created_by');
    expect($attrs)->not->toHaveKey('created_at');
});

it('saveModule stamps updated_* when client mutates fields/attrs and author provided', function () {
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'demo_users', '演示', '', '', 'charsen');

    // 等 1 秒避免 created_at == updated_at 看不出差(date 精度到秒)
    sleep(1);

    $this->loader->saveModule('Demo', [
        'tables' => [
            'demo_users' => [
                'name'   => '演示新名',     // 真改 — 触发 updated_* stamp
                'fields' => [
                    ['name' => 'id', 'index' => null],
                ],
            ],
        ],
    ], 'charsen');

    $data  = Yaml::parseFile($this->tmpDir . '/Demo.yaml');
    $attrs = $data['tables']['demo_users']['attrs'];

    expect($attrs['created_by'])->toBe('charsen');
    expect($attrs['updated_by'])->toBe('charsen');
    expect($attrs['updated_at'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
    expect(strtotime($attrs['updated_at']))->toBeGreaterThan(strtotime($attrs['created_at']));
});

it('saveModule does NOT stamp updated_* when client payload yields no semantic change (idempotent save)', function () {
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'demo_users', '演示', '描述', '', 'charsen');

    // 读 baseline
    $before       = Yaml::parseFile($this->tmpDir . '/Demo.yaml');
    $beforeFields = $before['tables']['demo_users']['fields'];

    sleep(1);

    // round-trip:client 数据完全等同 yaml 当前内容 → snapshot 不变 → 不 stamp updated_*
    $this->loader->saveModule('Demo', [
        'tables' => [
            'demo_users' => [
                'name'   => '演示',
                'desc'   => '描述',
                'fields' => array_map(
                    function ($k, $v) {
                        $attrs       = is_array($v) ? $v : [];
                        $displayName = $attrs['name'] ?? null;
                        unset($attrs['name']);

                        return array_merge(
                            ['name' => $k, 'display_name' => $displayName],
                            $attrs,
                        );
                    },
                    array_keys($beforeFields),
                    array_values($beforeFields),
                ),
            ],
        ],
    ], 'charsen');

    $data  = Yaml::parseFile($this->tmpDir . '/Demo.yaml');
    $attrs = $data['tables']['demo_users']['attrs'];

    expect($attrs)->not->toHaveKey('updated_by');
    expect($attrs)->not->toHaveKey('updated_at');
});

it('saveModule backfills created_* for legacy yaml without stamp on first real edit', function () {
    // 模拟老 yaml:createTable 时不传 author → attrs 没 stamp
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'demo_users', '演示');

    $before = Yaml::parseFile($this->tmpDir . '/Demo.yaml');
    expect($before['tables']['demo_users']['attrs'])->not->toHaveKey('created_by');

    // 首次真改动 → 同时补 created_* + 写 updated_*
    $this->loader->saveModule('Demo', [
        'tables' => [
            'demo_users' => [
                'name'   => '演示改名',
                'fields' => [['name' => 'id', 'index' => null]],
            ],
        ],
    ], 'charsen');

    $data  = Yaml::parseFile($this->tmpDir . '/Demo.yaml');
    $attrs = $data['tables']['demo_users']['attrs'];

    expect($attrs['created_by'])->toBe('charsen');
    expect($attrs['updated_by'])->toBe('charsen');
});

it('loadTableFull exposes audit metadata fields to view', function () {
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'demo_users', '演示', '', '', 'charsen');

    $full = $this->loader->loadTableFull('Demo', 'demo_users');

    expect($full)->toHaveKey('created_by');
    expect($full)->toHaveKey('created_at');
    expect($full)->toHaveKey('updated_by');
    expect($full)->toHaveKey('updated_at');
    expect($full['created_by'])->toBe('charsen');
    expect($full['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}/');
    expect($full['updated_by'])->toBeNull();
    expect($full['updated_at'])->toBeNull();
});

// ─── deleteSchema / renameSchema (草稿态) ──────────────────────

it('isSchemaDraft returns true for newly created schema (no tables)', function () {
    $this->loader->createSchema('Demo', '演示');
    expect($this->loader->isSchemaDraft('Demo'))->toBeTrue();
});

it('deleteSchema removes yaml file (draft state)', function () {
    $this->loader->createSchema('Demo', '演示');
    $path = $this->tmpDir . '/Demo.yaml';
    expect(file_exists($path))->toBeTrue();

    $this->loader->deleteSchema('Demo');
    expect(file_exists($path))->toBeFalse();
});

it('deleteSchema rejects when yaml does not exist', function () {
    expect(fn () => $this->loader->deleteSchema('Nonexist'))
        ->toThrow(SchemaLoadException::class, 'not found');
});

it('renameSchema mv yaml + updates module.folder', function () {
    $this->loader->createSchema('OldName', '老模块');
    $this->loader->renameSchema('OldName', 'NewName');
    expect(file_exists($this->tmpDir . '/OldName.yaml'))->toBeFalse();
    expect(file_exists($this->tmpDir . '/NewName.yaml'))->toBeTrue();
    $content = file_get_contents($this->tmpDir . '/NewName.yaml');
    expect($content)->toContain('folder: NewName');
    expect($content)->toContain('name: 老模块'); // 显示名不动
});

it('renameSchema rejects non-PascalCase new name', function () {
    $this->loader->createSchema('OldName', '老模块');
    expect(fn () => $this->loader->renameSchema('OldName', 'lower_case'))
        ->toThrow(SchemaLoadException::class, 'PascalCase');
});

it('renameSchema rejects when new name already exists', function () {
    $this->loader->createSchema('A', 'A 模块');
    $this->loader->createSchema('B', 'B 模块');
    expect(fn () => $this->loader->renameSchema('A', 'B'))
        ->toThrow(SchemaLoadException::class, '已存在');
});

it('renameSchema no-op when old === new', function () {
    $this->loader->createSchema('Same', '同名');
    $this->loader->renameSchema('Same', 'Same');
    expect(file_exists($this->tmpDir . '/Same.yaml'))->toBeTrue();
});

it('renameSchema rejects when source schema does not exist', function () {
    expect(fn () => $this->loader->renameSchema('Nonexist', 'NewOne'))
        ->toThrow(SchemaLoadException::class, 'not found');
});

// ─── renameTable (草稿态表 key 改名) ──────────────────────────

it('renameTable renames the yaml tables node (old gone, new present)', function () {
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'old_table', '老表', '描述');
    $this->loader->renameTable('Demo', 'old_table', 'new_table');
    $content = file_get_contents($this->tmpDir . '/Demo.yaml');
    expect($content)->toContain('new_table:');
    expect($content)->not->toContain('old_table:');
});

it('renameTable keeps key order + moves node content intact (在原位换 key)', function () {
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'first_table', '第一');
    $this->loader->createTable('Demo', 'second_table', '第二');
    $before = Yaml::parseFile($this->tmpDir . '/Demo.yaml')['tables']['first_table'];
    $this->loader->renameTable('Demo', 'first_table', 'renamed_first');
    $raw = Yaml::parseFile($this->tmpDir . '/Demo.yaml');
    expect(array_keys($raw['tables']))->toBe(['renamed_first', 'second_table']);
    expect($raw['tables']['renamed_first'])->toBe($before);
});

it('renameTable rejects non-snake_case new key', function () {
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'good_table', '表');
    expect(fn () => $this->loader->renameTable('Demo', 'good_table', 'BadKey'))
        ->toThrow(SchemaLoadException::class, 'snake_case');
});

it('renameTable rejects when new key already exists', function () {
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'table_a', 'A');
    $this->loader->createTable('Demo', 'table_b', 'B');
    expect(fn () => $this->loader->renameTable('Demo', 'table_a', 'table_b'))
        ->toThrow(SchemaLoadException::class, '已存在');
});

it('renameTable rejects when source table does not exist', function () {
    $this->loader->createSchema('Demo', '演示');
    expect(fn () => $this->loader->renameTable('Demo', 'nonexist_table', 'new_table'))
        ->toThrow(SchemaLoadException::class, 'not found');
});

it('renameTable no-op when old === new', function () {
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'same_table', '同名');
    $this->loader->renameTable('Demo', 'same_table', 'same_table');
    expect(file_get_contents($this->tmpDir . '/Demo.yaml'))->toContain('same_table:');
});

// ─── renameTable × migration 闭环(2026-07-04 migration 锁撤除) ──────

it('renameTable 不再被已有 migration 拒绝(锁撤除,闭环由 controller 接力 rename migration)', function () {
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'locked_table', '有迁移的表');
    $migDir = database_path('migrations');
    @mkdir($migDir, 0755, true);
    $fake = $migDir . '/2026_01_01_000000_create_locked_table_table.php';
    file_put_contents($fake, "<?php\n");
    try {
        $this->loader->renameTable('Demo', 'locked_table', 'renamed_table');
        $raw = Yaml::parseFile($this->tmpDir . '/Demo.yaml');
        expect(array_keys($raw['tables']))->toContain('renamed_table');
        expect(array_keys($raw['tables']))->not->toContain('locked_table');
    } finally {
        @unlink($fake);
    }
});

it('writeRename 生成 Schema::rename migration,up/down 成对,文件名尾缀命中新 key 的 locked 匹配', function () {
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'old_key_table', '表');
    $rel   = app(MigrationWriter::class)->writeRename('Demo', 'old_key_table', 'new_key_table');
    $files = glob(database_path('migrations') . '/*_rename_old_key_table_to_new_key_table_table.php') ?: [];
    try {
        expect($files)->toHaveCount(1);
        expect($rel)->toContain('rename_old_key_table_to_new_key_table_table.php');
        $src = file_get_contents($files[0]);
        expect($src)->toContain("Schema::rename('old_key_table', 'new_key_table');");
        expect($src)->toContain("Schema::rename('new_key_table', 'old_key_table');");   // down 反向
        // 尾缀 `_new_key_table_table.php` → latestMigrationFor(new key) 命中,改名后 locked 状态延续
        expect(str_ends_with(basename($files[0]), '_new_key_table_table.php'))->toBeTrue();
    } finally {
        foreach ($files as $f) {
            @unlink($f);
        }
    }
});

it('tableKeyLineage 按 rename 文件名回溯血缘链(含多跳 + 防环),loadMigrationsFor 不断链', function () {
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'gamma_table', '表');
    $migDir = database_path('migrations');
    @mkdir($migDir, 0755, true);
    // 血缘:alpha → beta → gamma(两跳);create/update 都挂在历史 key 上
    $fakes = [
        '2026_01_01_000001_create_alpha_table_table.php',
        '2026_01_02_000001_update_alpha_table_table.php',
        '2026_01_03_000001_rename_alpha_table_to_beta_table_table.php',
        '2026_01_04_000001_update_beta_table_table.php',
        '2026_01_05_000001_rename_beta_table_to_gamma_table_table.php',
    ];
    foreach ($fakes as $f) {
        file_put_contents($migDir . '/' . $f, "<?php\n/*\n * @Author: Tester\n */\n");
    }
    try {
        expect($this->loader->tableKeyLineage('Demo', 'gamma_table'))
            ->toBe(['gamma_table', 'beta_table', 'alpha_table']);
        $files = array_column($this->loader->loadMigrationsFor('Demo', 'gamma_table'), 'summary', 'file');
        foreach ($fakes as $f) {
            expect($files)->toHaveKey($f);   // 改名前的 create/update 也在历史里(断链 bug 回归锁)
        }
        expect($files['2026_01_05_000001_rename_beta_table_to_gamma_table_table.php'])->toBe('rename table');
        expect($files['2026_01_01_000001_create_alpha_table_table.php'])->toBe('create table');
    } finally {
        foreach ($fakes as $f) {
            @unlink($migDir . '/' . $f);
        }
    }
});

it('改名后 captureTables 迁 baseline:旧 key 移出 snapshot、新 key 吸入(防 diff 误判删表+建表)', function () {
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'before_table', '表');
    $snap = app(SnapshotStore::class);
    $snap->capture('Demo');
    $this->loader->renameTable('Demo', 'before_table', 'after_table');
    $snap->captureTables('Demo', ['before_table', 'after_table']);   // controller 接力的同款调用
    $baseline = Yaml::parse((string) $snap->load('Demo'));
    expect(array_keys($baseline['tables']))->toContain('after_table');
    expect(array_keys($baseline['tables']))->not->toContain('before_table');
});

// ─── saveModule attr sentinel / null-preserve round-trip(b0e3146 / c7c0b2c 防回归) ──

it('saveModule __CLEAR__ sentinel unsets attr from yaml (绕 ConvertEmptyStringsToNull 中间件)', function () {
    // c7c0b2c bug:Laravel `ConvertEmptyStringsToNull` 中间件把 POST body 内 `default: ""` 转 null,
    // 跟"未改信号 null"撞,后端无法分辨。Client 用 `__CLEAR__` 字面值绕开。
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'demo_users', '演示用户');

    // Round 1:seed default 值
    $this->loader->saveModule('Demo', [
        'module' => [],
        'tables' => ['demo_users' => [
            'fields' => [
                ['name' => 'id', 'display_name' => null, 'index' => null],
                ['name'       => 'status', 'display_name' => '状态', 'type' => 'tinyint',
                    'default' => 1, 'comment' => '初始'],
            ],
            'rename_hints' => [], 'multi_indexes' => [],
        ]],
    ]);
    expect(file_get_contents($this->tmpDir . '/Demo.yaml'))->toContain('default: 1');

    // Round 2:client 用 __CLEAR__ 显式清 default + comment
    $this->loader->saveModule('Demo', [
        'module' => [],
        'tables' => ['demo_users' => [
            'fields' => [
                ['name' => 'id', 'display_name' => null, 'index' => null],
                ['name'       => 'status', 'display_name' => '状态', 'type' => 'tinyint',
                    'default' => '__CLEAR__', 'comment' => '__CLEAR__'],
            ],
            'rename_hints' => [], 'multi_indexes' => [],
        ]],
    ]);
    $data   = Yaml::parseFile($this->tmpDir . '/Demo.yaml');
    $status = $data['tables']['demo_users']['fields']['status'];

    expect($status)->not->toHaveKey('default');
    expect($status)->not->toHaveKey('comment');
    expect($status['name'])->toBe('状态');     // 其它属性原样保留
});

it('saveModule null value 当"未改"信号,保留 yaml 原值(b0e3146 plan_priority silent-drop bug 防回归)', function () {
    // b0e3146 bug:client `_buildFieldEntry` 用 `f[attr] ?? null` 永远发 null(无 dirty tracking),
    // 后端要把 `null` 当"未改"保留 yaml 原值,不能 unset 否则改 A 字段会牵动 B 字段
    // 已有的 default: null / unsigned: false / size: 'min,max' 被吞掉。
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'demo_users', '演示用户');

    // Round 1:seed 一个完整字段(default + comment + unsigned)
    $this->loader->saveModule('Demo', [
        'module' => [],
        'tables' => ['demo_users' => [
            'fields' => [
                ['name' => 'id', 'display_name' => null, 'index' => null],
                ['name'       => 'plan_priority', 'display_name' => '优先级', 'type' => 'tinyint',
                    'default' => 5, 'unsigned' => false, 'comment' => '排序权重'],
            ],
            'rename_hints' => [], 'multi_indexes' => [],
        ]],
    ]);

    // Round 2:client 只改 display_name,其它 attrs 发 null(模拟无 dirty tracking 的 payload)
    $this->loader->saveModule('Demo', [
        'module' => [],
        'tables' => ['demo_users' => [
            'fields' => [
                ['name' => 'id', 'display_name' => null, 'index' => null],
                ['name'       => 'plan_priority', 'display_name' => '优先级 v2', 'type' => 'tinyint',
                    'default' => null, 'unsigned' => null, 'comment' => null],
            ],
            'rename_hints' => [], 'multi_indexes' => [],
        ]],
    ]);
    $data  = Yaml::parseFile($this->tmpDir . '/Demo.yaml');
    $field = $data['tables']['demo_users']['fields']['plan_priority'];

    // 注意:client `display_name` → yaml `name`(参考 canonical 顺序段 line 313)
    expect($field['name'])->toBe('优先级 v2');             // user 显式改的
    expect($field['default'])->toBe(5);                    // null = 未改,原值保
    expect($field['unsigned'])->toBeFalse();               // null = 未改,显式 signed 保
    expect($field['comment'])->toBe('排序权重');           // null = 未改,原值保
});

it('saveModule default: 0 (numeric zero) 保留 not silently dropped (PHP falsy 陷阱防回归)', function () {
    // PHP empty() / !$val / falsy check 会把 0 当 unset 处理 — 但 user 显式 default 0 是
    // 合法值(布尔 false、计数器初值)。saveModule 必须只用严格 === null / === ''/__CLEAR__ 判断。
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'demo_users', '演示用户');

    $this->loader->saveModule('Demo', [
        'module' => [],
        'tables' => ['demo_users' => [
            'fields' => [
                ['name' => 'id', 'display_name' => null, 'index' => null],
                ['name'       => 'is_pinned', 'display_name' => '置顶', 'type' => 'tinyint',
                    'default' => 0, 'unsigned' => false],
            ],
            'rename_hints' => [], 'multi_indexes' => [],
        ]],
    ]);
    $data  = Yaml::parseFile($this->tmpDir . '/Demo.yaml');
    $field = $data['tables']['demo_users']['fields']['is_pinned'];

    expect($field)->toHaveKey('default');
    expect($field['default'])->toBe(0);                    // 严格 0,不 strip
});

it('saveModule 跨字段不串扰:改 A 字段不影响 B 字段已有 attrs', function () {
    // 整段 b0e3146 bug 的语义复合断言:multi-field payload 用 null 信号,
    // 修了 A 必须 完全 不动 B 的 default / unsigned / size 三个属性。
    $this->loader->createSchema('Demo', '演示');
    $this->loader->createTable('Demo', 'demo_users', '演示用户');

    // seed A + B
    $this->loader->saveModule('Demo', [
        'module' => [],
        'tables' => ['demo_users' => [
            'fields' => [
                ['name' => 'id', 'display_name' => null, 'index' => null],
                ['name' => 'name_a', 'display_name' => 'A 字段', 'type' => 'varchar', 'size' => 64],
                ['name'       => 'count_b', 'display_name' => 'B 字段', 'type' => 'int',
                    'default' => 0, 'unsigned' => false, 'comment' => 'B 注释'],
            ],
            'rename_hints' => [], 'multi_indexes' => [],
        ]],
    ]);

    // 只改 A.display_name,B 全 null(模拟 client 无 dirty tracking)
    $this->loader->saveModule('Demo', [
        'module' => [],
        'tables' => ['demo_users' => [
            'fields' => [
                ['name' => 'id', 'display_name' => null, 'index' => null],
                ['name' => 'name_a', 'display_name' => 'A 改了', 'type' => 'varchar', 'size' => 64],
                ['name'       => 'count_b', 'display_name' => 'B 字段', 'type' => 'int',
                    'default' => null, 'unsigned' => null, 'comment' => null],
            ],
            'rename_hints' => [], 'multi_indexes' => [],
        ]],
    ]);
    $data = Yaml::parseFile($this->tmpDir . '/Demo.yaml');
    $b    = $data['tables']['demo_users']['fields']['count_b'];

    expect($b['default'])->toBe(0);
    expect($b['unsigned'])->toBeFalse();
    expect($b['comment'])->toBe('B 注释');
});

// ─── applyRenameHints 撞名守护(2026-06-10 修)──────────────────────────────
// 原:字段改名守在 if 里、索引重写无条件执行 → 目标名已存在(撞名)时字段保留旧名,但索引被指到
// 新名(一个已存在的别的字段)→ 索引落在错字段上。

it('applyRenameHints:目标名已存在(撞名)→ 整条跳过,索引不被改到错字段', function () {
    $ref = new ReflectionMethod(SchemaLoader::class, 'applyRenameHints');
    $ref->setAccessible(true);

    $yamlFields = ['status' => ['type' => 'tinyint'], 'state' => ['type' => 'tinyint']];
    $yamlTable  = ['index' => ['idx_status' => ['type' => 'index', 'fields' => 'status']]];

    $ref->invokeArgs($this->loader, [&$yamlFields, &$yamlTable, ['status' => 'state']]);

    // state 已存在 → status 没被改名,索引仍指 status(bug 版本会被改成 'state')
    expect($yamlFields)->toHaveKey('status');
    expect($yamlTable['index']['idx_status']['fields'])->toBe('status');
});

it('applyRenameHints:正常改名(目标名不存在)→ 字段 + 索引一起改', function () {
    $ref = new ReflectionMethod(SchemaLoader::class, 'applyRenameHints');
    $ref->setAccessible(true);

    $yamlFields = ['status' => ['type' => 'tinyint']];
    $yamlTable  = ['index' => ['idx_status' => ['type' => 'index', 'fields' => 'status']]];

    $ref->invokeArgs($this->loader, [&$yamlFields, &$yamlTable, ['status' => 'state']]);

    expect($yamlFields)->toHaveKey('state')->not->toHaveKey('status');
    expect($yamlTable['index']['idx_status']['fields'])->toBe('state');
});
