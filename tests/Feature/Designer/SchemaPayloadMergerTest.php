<?php declare(strict_types=1);

/**
 * saveModule 族 —— 「client save payload → yaml table 结构」归一的**钉现状**测试（第 5 项）。
 *
 * 为什么单独一个文件:
 *   这 12 个方法原先都是 SchemaLoader 的私有成员,但用「每个私有方法的 public 入口可达集」
 *   反向闭包一扫就看得出来——它们**只被 saveModule 一个 public 入口可达**,且除
 *   applyEnums → sanitizeEnumLabel(仍在族内)外不调任何族外方法、不读实例属性
 *   ⇒ 判定为「真垂直切片」,准备整体外迁到 SchemaPayloadMerger。
 *
 * 本文件的作用是让外迁变成「同一批断言跨两个宿主」:
 *   spmSubject() 外迁前返回 SchemaLoader::class、外迁后返回 SchemaPayloadMerger::class,
 *   **断言一字不动** —— 两侧都跑绿即「行为不变」的机器证明,而不是「看着像」。
 *   宿主耦合点只有 spmSubject() 一处,外迁只改那一行的返回值。
 *
 * 覆盖策略:
 *   - 逐条钉住代码里带日期/plan 号的既有修复不变量(2026-05-20 / 2026-05-23 round N /
 *     plan-40 §二 R-14 / plan-51 …),这些是「现状」里最贵、最容易被搬动时搬错的部分;
 *   - 每个方法至少一组「按 client 传 → 得到什么」+ 一组「不传 / 传空 → 什么不变」。
 *
 * ⚠ 刻意绕开容器的点:`applyTableController` 在 $origin === null 时会走
 *   app(AppTargetRegistry::class)->assertConfigured(...)。除 §5 最后两条专门钉这个分支的
 *   开/关之外,一律传非 null $origin(包 schema 语境:端契约归包自身,不校验)以脱离容器。
 */

use Mooeen\Scaffold\Designer\SchemaLoader;
use Mooeen\Scaffold\Utility;

/**
 * 被测宿主。**外迁时只改这一行** —— 其余断言不动。
 */
function spmSubject(): string
{
    return \Mooeen\Scaffold\Designer\SchemaPayloadMerger::class;
}

/**
 * 调族内方法。$args 里写 `&$var` 的引用会原样透传到 invokeArgs
 * (数组按值传参时引用元素仍指向原 zval;已用最小探针单独验证过,不是推断)。
 */
function spmCall(string $method, array $args = []): mixed
{
    $ref = new ReflectionMethod(spmSubject(), $method);
    $ref->setAccessible(true);

    if ($ref->isStatic()) {
        return $ref->invokeArgs(null, $args);
    }

    // 外迁前:宿主是实例方法,需要一个 SchemaLoader(ctor 只存 Utility,无 FS/DB 副作用)
    static $instance = null;
    $instance ??= new SchemaLoader(new Utility);

    return $ref->invokeArgs($instance, $args);
}

/* ═══════════════════════════════════════════════════════════════════════
 * §1 applyModuleBlock — module 块:仅当 client 传了非空内容才 merge
 * ═══════════════════════════════════════════════════════════════════════ */

it('§1 applyModuleBlock · client 没传 module / 传空数组 → raw 一字不动', function () {
    $raw = ['module' => ['name' => '旧', 'folder' => 'Old'], 'tables' => []];
    spmCall('applyModuleBlock', [&$raw, []]);
    expect($raw['module'])->toBe(['name' => '旧', 'folder' => 'Old']);

    spmCall('applyModuleBlock', [&$raw, ['module' => []]]);
    expect($raw['module'])->toBe(['name' => '旧', 'folder' => 'Old']);

    // ⚠ 关键分叉用例：raw 原本**没有** module 节点时，"空 module 也要 merge" 会凭空建出
    //   `module: []`(写盘多一个空节点)。只有这条能区分「判定空值」与「只判 is_array」——
    //   在 raw 已有 module 的输入上 array_merge($existing, []) 恒等于 $existing,是等价变异。
    $rawNoModule = ['tables' => []];
    spmCall('applyModuleBlock', [&$rawNoModule, ['module' => []]]);
    expect($rawNoModule)->toBe(['tables' => []]);
});

it('§1 applyModuleBlock · 非空 module 与 raw 原块 array_merge(新覆盖旧,旧独有键保留)', function () {
    $raw = ['module' => ['name' => '旧', 'folder' => 'Old', 'keep' => 1]];
    spmCall('applyModuleBlock', [&$raw, ['module' => ['name' => '新', 'extra' => 'x']]]);
    expect($raw['module'])->toBe(['name' => '新', 'folder' => 'Old', 'keep' => 1, 'extra' => 'x']);
});

it('§1 applyModuleBlock · raw 原本没有 module 节点 → 新建', function () {
    $raw = ['tables' => []];
    spmCall('applyModuleBlock', [&$raw, ['module' => ['name' => 'N']]]);
    expect($raw['module'])->toBe(['name' => 'N']);
});

it('§1 applyModuleBlock · client.module 不是数组 → 跳过(不炸)', function () {
    $raw = ['module' => ['name' => '旧']];
    spmCall('applyModuleBlock', [&$raw, ['module' => 'oops']]);
    expect($raw['module'])->toBe(['name' => '旧']);
});

/* ═══════════════════════════════════════════════════════════════════════
 * §2 changeSnapshot — 表语义快照(剔除 audit stamp,用于判定"真改动")
 * ═══════════════════════════════════════════════════════════════════════ */

it('§2 changeSnapshot · 扣掉 created_*/updated_*,其余业务块原样带出(含块与键序)', function () {
    $snap = spmCall('changeSnapshot', [[
        'attrs'      => ['name' => 'N', 'created_by' => 'a', 'created_at' => 't1', 'updated_by' => 'b', 'updated_at' => 't2'],
        'model'      => ['class' => 'M'],
        'controller' => ['class' => 'C'],
        'fields'     => ['id' => []],
        'index'      => ['i' => ['type' => 'primary']],
        'enums'      => ['s' => []],
    ]]);

    // 注意 attrs 里只剩 name —— 四个 stamp 键全被扣除;且 nil 块位置/键序固定
    expect($snap)->toBe([
        'attrs'      => ['name' => 'N'],
        'model'      => ['class' => 'M'],
        'controller' => ['class' => 'C'],
        'fields'     => ['id' => []],
        'index'      => ['i' => ['type' => 'primary']],
        'enums'      => ['s' => []],
    ]);
});

it('§2 changeSnapshot · 空表 → attrs 为 []、其余五块为 null', function () {
    expect(spmCall('changeSnapshot', [[]]))->toBe([
        'attrs'      => [],
        'model'      => null,
        'controller' => null,
        'fields'     => null,
        'index'      => null,
        'enums'      => null,
    ]);
});

it('§2 changeSnapshot · 只有 stamp 的 attrs 与空 attrs 快照等价(round-trip save 不刷 updated_*)', function () {
    $stamped = spmCall('changeSnapshot', [['attrs' => ['created_by' => 'a', 'updated_at' => 't']]]);
    $empty   = spmCall('changeSnapshot', [['attrs' => []]]);
    expect($stamped)->toBe($empty);
});

/* ═══════════════════════════════════════════════════════════════════════
 * §3 applyTableAttrs — 表 attrs:覆盖 name / desc / prefix(F29)
 * ═══════════════════════════════════════════════════════════════════════ */

it('§3 applyTableAttrs · name 存在就覆盖(null 也写,不走 unset 语义)', function () {
    expect(spmCall('applyTableAttrs', [[], ['name' => '张三']]))->toBe(['attrs' => ['name' => '张三']]);
    expect(spmCall('applyTableAttrs', [['attrs' => ['name' => '旧']], ['name' => null]]))
        ->toBe(['attrs' => ['name' => null]]);
});

it('§3 applyTableAttrs · desc / prefix 传 null 或空串 → unset(可从 yaml 抹掉)', function () {
    expect(spmCall('applyTableAttrs', [['attrs' => ['desc' => '旧', 'prefix' => 'p_']], ['desc' => '', 'prefix' => null]]))
        ->toBe(['attrs' => []]);
});

it('§3 applyTableAttrs · desc / prefix 有值 → 写盘', function () {
    expect(spmCall('applyTableAttrs', [['attrs' => []], ['desc' => '新', 'prefix' => 'x_']]))
        ->toBe(['attrs' => ['desc' => '新', 'prefix' => 'x_']]);
});

it('§3 applyTableAttrs · 不传 → yaml 原 attrs 一字不动', function () {
    $yamlTable = ['attrs' => ['name' => 'N', 'desc' => 'D', 'other' => 1]];
    expect(spmCall('applyTableAttrs', [$yamlTable, ['fields' => []]]))
        ->toBe(['attrs' => ['name' => 'N', 'desc' => 'D', 'other' => 1]]);
});

/* ═══════════════════════════════════════════════════════════════════════
 * §4 applyTableModel — plan 19 v11 / plan-37 后审 P1:只删 class 键,不整节点删
 * ═══════════════════════════════════════════════════════════════════════ */

it('§4 applyTableModel · 没传 model / model 非数组 → 原样返回', function () {
    expect(spmCall('applyTableModel', [['model' => ['class' => 'Keep']], []]))
        ->toBe(['model' => ['class' => 'Keep']]);
    expect(spmCall('applyTableModel', [['a' => 1], ['model' => 'nope']]))->toBe(['a' => 1]);
});

it('§4 applyTableModel · class 有值 → trim 后写入', function () {
    expect(spmCall('applyTableModel', [[], ['model' => ['class' => '  App\\Models\\User  ']]]))
        ->toBe(['model' => ['class' => 'App\\Models\\User']]);
});

it('§4 applyTableModel · class 清空 → 只删 class,同节点 app/resource/factory 等子配置保留(plan-37 P1)', function () {
    expect(spmCall('applyTableModel', [['model' => ['class' => 'X', 'app' => ['admin'], 'factory' => true]], ['model' => ['class' => '']]]))
        ->toBe(['model' => ['app' => ['admin'], 'factory' => true]]);
});

it('§4 applyTableModel · class 清空且节点只剩 class → 整个 model 节点 unset', function () {
    expect(spmCall('applyTableModel', [['model' => ['class' => 'X']], ['model' => ['class' => '']]]))->toBe([]);
    // yaml 原本就没 model 节点 + client class 空 → 不凭空留一个空 model 节点
    expect(spmCall('applyTableModel', [[], ['model' => ['class' => '']]]))->toBe([]);
});

/* ═══════════════════════════════════════════════════════════════════════
 * §5 applyTableController — plan 19 v11 / 2026-05-20 toggle 持久化 / 2026-05-21 后缀归一
 *     除最后两条外一律传非 null $origin(包 schema 语境)以脱离容器
 * ═══════════════════════════════════════════════════════════════════════ */

it('§5 applyTableController · 没传 controller / 非数组 → 原样返回', function () {
    expect(spmCall('applyTableController', [['controller' => ['class' => 'Keep']], [], 'pkg-demo']))
        ->toBe(['controller' => ['class' => 'Keep']]);
    expect(spmCall('applyTableController', [['controller' => ['class' => 'Keep']], ['controller' => 'C'], 'pkg-demo']))
        ->toBe(['controller' => ['class' => 'Keep']]);
});

it('§5 applyTableController · class 经 ControllerName::ensure 归一(缺后缀补,已有不重复)', function () {
    expect(spmCall('applyTableController', [[], ['controller' => ['class' => 'Memo']], 'pkg-demo']))
        ->toBe(['controller' => ['class' => 'MemoController']]);
    expect(spmCall('applyTableController', [[], ['controller' => ['class' => 'MemoController']], 'pkg-demo']))
        ->toBe(['controller' => ['class' => 'MemoController']]);
});

it('§5 applyTableController · class 空但 app/resource 有值 → toggle 意图照样落盘(2026-05-20 bug)', function () {
    expect(spmCall('applyTableController', [[], ['controller' => ['class' => '', 'app' => ['admin', 'mobi'], 'resource' => ['mobi']]], 'pkg-demo']))
        ->toBe(['controller' => ['app' => ['admin', 'mobi'], 'resource' => ['mobi']]]);
});

it('§5 applyTableController · app/resource 列表过滤空串 + array_values 重排', function () {
    expect(spmCall('applyTableController', [[], ['controller' => ['app' => [0 => 'admin', 2 => '', 5 => 'mobi']]], 'pkg-demo']))
        ->toBe(['controller' => ['app' => ['admin', 'mobi']]]);
});

it('§5 applyTableController · app 键存在但非数组 → 不进分支(yaml 原 app 保留)', function () {
    expect(spmCall('applyTableController', [['controller' => ['app' => ['admin']]], ['controller' => ['app' => 'admin']], 'pkg-demo']))
        ->toBe(['controller' => ['app' => ['admin']]]);
});

it('§5 applyTableController · resource 键存在但非数组 → 视为空数组 → unset resource', function () {
    expect(spmCall('applyTableController', [['controller' => ['resource' => ['mobi']]], ['controller' => ['resource' => 'mobi']], 'pkg-demo']))
        ->toBe([]);
});

it('§5 applyTableController · class + app + resource 全空 → 整个 controller 节点 unset', function () {
    expect(spmCall('applyTableController', [
        ['controller' => ['class' => 'X', 'app' => ['admin']]],
        ['controller' => ['class' => '', 'app' => ['', '']]],
        'pkg-demo',
    ]))->toBe([]);
});

it('§5 applyTableController · $origin 非 null(包 schema)→ 不校验端,未知端照样写盘', function () {
    expect(spmCall('applyTableController', [[], ['controller' => ['app' => ['__spm_nope__']]], 'pkg-demo']))
        ->toBe(['controller' => ['app' => ['__spm_nope__']]]);
});

it('§5 applyTableController · $origin 为 null(host schema)→ 走 AppTargetRegistry 校验,未知端抛', function () {
    // context 字面量本身也是被钉的对象:写错成 'controller.resource' 这里就红
    expect(fn () => spmCall('applyTableController', [[], ['controller' => ['app' => ['__spm_nope__']]], null]))
        ->toThrow(InvalidArgumentException::class, 'controller.app');

    expect(fn () => spmCall('applyTableController', [[], ['controller' => ['resource' => ['__spm_nope__']]], null]))
        ->toThrow(InvalidArgumentException::class, 'controller.resource');
});

it('§5 applyTableController · $origin 为 null 但端已配置 → 不抛(class 后缀仍归一)', function () {
    // '' / 空数组不会命中 unknown 分支 ⇒ 与配置内容无关地证明「校验放行」这条路径通
    expect(spmCall('applyTableController', [[], ['controller' => ['class' => 'Memo', 'app' => []], 'resource' => []], null]))
        ->toBe(['controller' => ['class' => 'MemoController']]);
});

/* ═══════════════════════════════════════════════════════════════════════
 * §6 applyRenameHints — 两个引用参数(&$yamlFields / &$yamlTable)
 * ═══════════════════════════════════════════════════════════════════════ */

it('§6 applyRenameHints · 多字段复合索引随改名同步,无关字段不受影响', function () {
    $yamlFields = ['col_a' => [], 'col_c' => []];
    $yamlTable  = ['index' => [
        'idx_ab' => ['type' => 'index', 'fields' => ['col_a', 'col_b']],  // 多字段复合
        'uq_c'   => ['type' => 'unique', 'fields' => 'col_c'],            // 单字段(string)
    ]];

    spmCall('applyRenameHints', [&$yamlFields, &$yamlTable, ['col_a' => 'col_x']]);

    expect($yamlTable['index']['idx_ab']['fields'])->toBe(['col_x', 'col_b']);
    expect($yamlTable['index']['uq_c']['fields'])->toBe('col_c');
    expect($yamlFields)->toHaveKey('col_x')->and($yamlFields)->not->toHaveKey('col_a');
});

it('§6 applyRenameHints · 单字段 string 索引:idx name 与 oldKey 同名时一并改', function () {
    $yamlFields = ['status' => ['type' => 'tinyint']];
    $yamlTable  = ['index' => ['status' => ['type' => 'index', 'fields' => 'status']]];

    spmCall('applyRenameHints', [&$yamlFields, &$yamlTable, ['status' => 'state']]);

    expect($yamlFields)->toHaveKey('state')->not->toHaveKey('status');
    expect($yamlTable['index'])->toHaveKey('state')->not->toHaveKey('status');
    expect($yamlTable['index']['state']['fields'])->toBe('state');
});

it('§6 applyRenameHints · idx name 与 oldKey 不同名 → 只改 fields,名字保留', function () {
    $yamlFields = ['a' => []];
    $yamlTable  = ['index' => ['my_idx' => ['type' => 'index', 'fields' => 'a']]];

    spmCall('applyRenameHints', [&$yamlFields, &$yamlTable, ['a' => 'b']]);

    expect($yamlTable['index'])->toHaveKey('my_idx')->not->toHaveKey('b');
    expect($yamlTable['index']['my_idx']['fields'])->toBe('b');
});

it('§6 applyRenameHints · 单元素数组形式 fields:[a] → 折叠回 string', function () {
    $yamlFields = ['a' => []];
    $yamlTable  = ['index' => ['i' => ['type' => 'index', 'fields' => ['a']]]];

    spmCall('applyRenameHints', [&$yamlFields, &$yamlTable, ['a' => 'b']]);

    expect($yamlTable['index']['i']['fields'])->toBe('b');
});

it('§6 applyRenameHints · 目标名已存在(撞名)→ 整条跳过,索引不被改到错字段(2026-06-10 修)', function () {
    $yamlFields = ['status' => ['type' => 'tinyint'], 'state' => ['type' => 'tinyint']];
    $yamlTable  = ['index' => ['idx_status' => ['type' => 'index', 'fields' => 'status']]];

    spmCall('applyRenameHints', [&$yamlFields, &$yamlTable, ['status' => 'state']]);

    expect($yamlFields)->toHaveKey('status')->toHaveKey('state');
    expect($yamlTable['index']['idx_status']['fields'])->toBe('status');
});

it('§6 applyRenameHints · 源名不在 yaml 里 → 跳过(冒名 hint 不改任何东西)', function () {
    $yamlFields = ['other' => []];
    $yamlTable  = ['index' => ['idx' => ['type' => 'index', 'fields' => 'ghost']]];

    spmCall('applyRenameHints', [&$yamlFields, &$yamlTable, ['ghost' => 'new_ghost']]);

    expect($yamlFields)->toBe(['other' => []]);
    expect($yamlTable['index']['idx']['fields'])->toBe('ghost');
});

it('§6 applyRenameHints · newKey 非法(大写 / 首数字 / 超 64)→ 静默跳过(plan-38 P0-SEC-4)', function () {
    $yamlFields = ['bad_a' => [], 'bad_b' => [], 'bad_c' => []];
    $yamlTable  = [];

    spmCall('applyRenameHints', [&$yamlFields, &$yamlTable, [
        'bad_a' => 'BadUpper',
        'bad_b' => '9lead',
        'bad_c' => str_repeat('z', 65),
    ]]);

    expect($yamlFields)->toBe(['bad_a' => [], 'bad_b' => [], 'bad_c' => []]);
});

it('§6 applyRenameHints · newKey 首下划线放行(Laravel-NestedSet _lft / _rgt 惯例)', function () {
    $yamlFields = ['lft' => ['type' => 'int']];
    $yamlTable  = [];

    spmCall('applyRenameHints', [&$yamlFields, &$yamlTable, ['lft' => '_lft']]);

    expect($yamlFields)->toBe(['_lft' => ['type' => 'int']]);
});

it('§6 applyRenameHints · 空 key / 同名 → 跳过', function () {
    $yamlFields = ['same' => []];
    $yamlTable  = [];

    spmCall('applyRenameHints', [&$yamlFields, &$yamlTable, ['same' => 'same']]);
    expect($yamlFields)->toHaveKey('same');

    spmCall('applyRenameHints', [&$yamlFields, &$yamlTable, ['' => 'x']]);
    expect($yamlFields)->toHaveKey('same')->not->toHaveKey('x');

    spmCall('applyRenameHints', [&$yamlFields, &$yamlTable, ['same' => '']]);
    expect($yamlFields)->toHaveKey('same');
});

it('§6 applyRenameHints · 无 index 块 → 字段改名仍生效', function () {
    $yamlFields = ['a' => ['type' => 'int']];
    $yamlTable  = [];

    spmCall('applyRenameHints', [&$yamlFields, &$yamlTable, ['a' => 'b']]);

    expect($yamlFields)->toBe(['b' => ['type' => 'int']]);
});

it('§6 applyRenameHints · 无关 entry(含非数组 entry)原样保留', function () {
    $yamlFields = ['a' => [], 'k' => []];
    $yamlTable  = ['index' => [
        'idx_other' => ['type' => 'index', 'fields' => ['k', 'z']],
        'plain'     => 'not-an-array',
    ]];

    spmCall('applyRenameHints', [&$yamlFields, &$yamlTable, ['a' => 'b']]);

    expect($yamlTable['index']['idx_other']['fields'])->toBe(['k', 'z']);
    expect($yamlTable['index']['plain'])->toBe('not-an-array');
});

/* ═══════════════════════════════════════════════════════════════════════
 * §7 rebuildFieldRows — 族里最大的一块(146 行),逐条钉 2026-05-22/23 系列修复
 * ═══════════════════════════════════════════════════════════════════════ */

it('§7 rebuildFieldRows · 字段集与顺序完全跟 client.fields,yaml 里多余字段被丢弃', function () {
    $result = spmCall('rebuildFieldRows', [
        ['a' => ['type' => 'int'], 'b' => ['type' => 'int']],
        [['name' => 'b'], ['name' => 'a']],
    ]);
    expect(array_keys($result))->toBe(['b', 'a']);
});

it('§7 rebuildFieldRows · client 项缺 name / name 为空 → 跳过', function () {
    expect(spmCall('rebuildFieldRows', [[], [['name' => ''], ['type' => 'varchar']]]))->toBe([]);
});

it('§7 rebuildFieldRows · 非法字段名(大写 / 首数字 / 超 64)丢弃,首下划线放行', function () {
    expect(spmCall('rebuildFieldRows', [[], [
        ['name' => 'BadUpper'],
        ['name' => '9lead'],
        ['name' => str_repeat('z', 65)],
    ]]))->toBe([]);

    expect(spmCall('rebuildFieldRows', [[], [['name' => '_lft']]]))->toBe(['_lft' => []]);
});

it('§7 rebuildFieldRows · system 字段:yaml 有则保留原 entry,yaml 缺则写空 entry(2026-05-22 修)', function () {
    $result = spmCall('rebuildFieldRows', [
        ['id' => ['type' => 'bigint']],
        [['name' => 'id'], ['name' => 'deleted_at'], ['name' => 'created_at']],
    ]);
    expect($result)->toBe([
        'id'         => ['type' => 'bigint'],
        'deleted_at' => [],
        'created_at' => [],
    ]);
});

it('§7 rebuildFieldRows · yaml 原 system entry 的额外 attrs 不被 client 覆盖(plan-40 P1 Round 2)', function () {
    $result = spmCall('rebuildFieldRows', [
        ['id' => [], 'deleted_at' => ['desc' => 'soft-delete', '_some_legacy' => 'keep']],
        [['name' => 'id'], ['name' => 'deleted_at', 'display_name' => null, 'index' => null]],
    ]);
    expect($result['deleted_at'])->toBe(['desc' => 'soft-delete', '_some_legacy' => 'keep']);
});

it('§7 rebuildFieldRows · attr 传 null = "未改" → yaml 原值保留(2026-05-23 P0 修)', function () {
    $result = spmCall('rebuildFieldRows', [
        ['a' => ['type' => 'int', 'default' => 5, 'unsigned' => true]],
        [['name' => 'a', 'default' => null, 'unsigned' => null]],
    ]);
    expect($result['a'])->toBe(['type' => 'int', 'default' => 5, 'unsigned' => true]);
});

it('§7 rebuildFieldRows · attr 传 "" 或 __CLEAR__ = "显式清空" → unset(yaml 有该 attr 时)', function () {
    $result = spmCall('rebuildFieldRows', [
        ['a' => ['type' => 'int', 'default' => 5, 'comment' => 'c']],
        [['name' => 'a', 'default' => '__CLEAR__', 'comment' => '']],
    ]);
    expect($result['a'])->toBe(['type' => 'int']);
});

it('§7 rebuildFieldRows · yaml 原本没有该 attr + client 传空 → 跳过(不写空值进 yaml)', function () {
    $result = spmCall('rebuildFieldRows', [
        ['a' => ['type' => 'int']],
        [['name' => 'a', 'comment' => '']],
    ]);
    expect($result['a'])->toBe(['type' => 'int']);
});

it('§7 rebuildFieldRows · size 紧凑串 "min,max":varchar 保留 yaml 原 min 与 client max 合成(2026-05-23 round 1)', function () {
    $result = spmCall('rebuildFieldRows', [
        ['a' => ['type' => 'varchar', 'size' => '6,192']],
        [['name' => 'a', 'type' => 'varchar', 'size' => '200']],
    ]);
    expect($result['a']['size'])->toBe('6,200');
});

it('§7 rebuildFieldRows · size 整体发 "min,max" 串 → 原样保留,不 coerce、不再拼 origMin(round 4)', function () {
    $result = spmCall('rebuildFieldRows', [
        ['a' => ['type' => 'varchar', 'size' => 64]],
        [['name' => 'a', 'type' => 'varchar', 'size' => '6,200']],
    ]);
    expect($result['a']['size'])->toBe('6,200');
});

it('§7 rebuildFieldRows · decimal 的 size 紧凑串是 {M},{D} 语义 → 不进 min,max 分支,归一为 int(round 5 audit)', function () {
    $result = spmCall('rebuildFieldRows', [
        ['a' => ['type' => 'decimal', 'size' => '10,2']],
        [['name' => 'a', 'type' => 'decimal', 'size' => '10']],
    ]);
    expect($result['a']['size'])->toBe(10);
});

it('§7 rebuildFieldRows · non-numeric 类型上的 unsigned 一律 strip(无论 true/false,plan-40 §三 R-14)', function () {
    expect(spmCall('rebuildFieldRows', [
        ['a' => ['type' => 'varchar', 'unsigned' => true]],
        [['name' => 'a', 'type' => 'varchar']],
    ]))->toBe(['a' => ['type' => 'varchar']]);

    expect(spmCall('rebuildFieldRows', [
        ['a' => ['type' => 'varchar', 'unsigned' => false]],
        [['name' => 'a', 'type' => 'varchar']],
    ]))->toBe(['a' => ['type' => 'varchar']]);
});

it('§7 rebuildFieldRows · numeric 类型的 unsigned 保留(true = 与历史 yaml 兼容的 idempotent 写盘)', function () {
    $result = spmCall('rebuildFieldRows', [
        ['a' => ['type' => 'int', 'unsigned' => true]],
        [['name' => 'a', 'type' => 'int']],
    ]);
    expect($result['a']['unsigned'])->toBeTrue();
});

it('§7 rebuildFieldRows · index=unique-app → row.unique=true;其它 index 值 → strip 掉 row.unique(plan-51)', function () {
    expect(spmCall('rebuildFieldRows', [
        ['a' => ['type' => 'int']],
        [['name' => 'a', 'index' => 'unique-app']],
    ]))->toBe(['a' => ['type' => 'int', 'unique' => true]]);

    expect(spmCall('rebuildFieldRows', [
        ['a' => ['type' => 'int', 'unique' => true]],
        [['name' => 'a', 'index' => 'index']],
    ]))->toBe(['a' => ['type' => 'int']]);
});

it('§7 rebuildFieldRows · display_name → row.name;空值 → unset name;不传 → 不动(2026-05-23 round 3)', function () {
    expect(spmCall('rebuildFieldRows', [
        ['a' => ['type' => 'int', 'name' => '旧']],
        [['name' => 'a', 'display_name' => '新']],
    ]))->toBe(['a' => ['type' => 'int', 'name' => '新']]);

    expect(spmCall('rebuildFieldRows', [
        ['a' => ['type' => 'int', 'name' => '旧']],
        [['name' => 'a', 'display_name' => '']],
    ]))->toBe(['a' => ['type' => 'int']]);

    expect(spmCall('rebuildFieldRows', [
        ['a' => ['type' => 'int', 'name' => '旧']],
        [['name' => 'a']],
    ]))->toBe(['a' => ['type' => 'int', 'name' => '旧']]);
});

it('§7 rebuildFieldRows · 新字段走 canonical attr 顺序,已存在字段保 yaml 原序(真机 Test 10 修)', function () {
    // 新字段:{name, comment, type, required, size} 乱序进 → canonical 出
    expect(spmCall('rebuildFieldRows', [[], [
        ['name' => 'a', 'comment' => 'c', 'type' => 'int', 'required' => true, 'size' => '8'],
    ]]))->toBe(['a' => ['required' => true, 'type' => 'int', 'size' => 8, 'comment' => 'c']]);

    // 已存在字段:yaml 原序 comment→type 保留,不被重排
    expect(spmCall('rebuildFieldRows', [
        ['a' => ['comment' => 'c', 'type' => 'int']],
        [['name' => 'a', 'type' => 'int']],
    ]))->toBe(['a' => ['comment' => 'c', 'type' => 'int']]);
});

/* ═══════════════════════════════════════════════════════════════════════
 * §8 sortRowAttrs — canonical 顺序 + unknown 追加末尾
 * ═══════════════════════════════════════════════════════════════════════ */

it('§8 sortRowAttrs · 按 canonical 顺序重排,未识别 attr 按原序追加末尾', function () {
    expect(spmCall('sortRowAttrs', [[
        'type'     => 'int',
        'required' => true,
        'comment'  => 'c',
        'name'     => 'N',
        'zzz'      => 1,
        'aaa'      => 2,
    ]]))->toBe([
        'required' => true,
        'name'     => 'N',
        'type'     => 'int',
        'comment'  => 'c',
        'zzz'      => 1,
        'aaa'      => 2,
    ]);
});

it('§8 sortRowAttrs · 空 row / 全 unknown → 原样', function () {
    expect(spmCall('sortRowAttrs', [[]]))->toBe([]);
    expect(spmCall('sortRowAttrs', [['b' => 1, 'a' => 2]]))->toBe(['b' => 1, 'a' => 2]);
});

/* ═══════════════════════════════════════════════════════════════════════
 * §9 rebuildTableIndex — plan 19 v4/v7 B3,F30 多字段索引,plan-51 值翻译
 * ═══════════════════════════════════════════════════════════════════════ */

it('§9 rebuildTableIndex · client 值翻译:unique-db / legacy unique → canonical unique;unique-app 不进 index', function () {
    expect(spmCall('rebuildTableIndex', [[], [['name' => 'mobile', 'index' => 'unique-db']], []]))
        ->toBe(['mobile' => ['type' => 'unique', 'fields' => 'mobile']]);

    expect(spmCall('rebuildTableIndex', [[], [['name' => 'old', 'index' => 'unique']], []]))
        ->toBe(['old' => ['type' => 'unique', 'fields' => 'old']]);

    // yaml 原有单字段索引,client 改成 unique-app → 彻底从 index 块消失(交给 row.unique sugar)
    expect(spmCall('rebuildTableIndex', [
        ['org' => ['type' => 'unique', 'fields' => 'org']],
        [['name' => 'org', 'index' => 'unique-app']],
        [],
    ]))->toBe([]);
});

it('§9 rebuildTableIndex · 无法识别的 client index 值 → 不进 index 块', function () {
    expect(spmCall('rebuildTableIndex', [[], [['name' => 'x', 'index' => 'nope']], []]))->toBe([]);
    expect(spmCall('rebuildTableIndex', [[], [['name' => 'x']], []]))->toBe([]);
});

it('§9 rebuildTableIndex · single 部分保 yaml 原序(不是 client.fields 序),只改 type(plan 19 v7 B3)', function () {
    $out = spmCall('rebuildTableIndex', [
        ['idx_b' => ['type' => 'index', 'fields' => 'b'], 'idx_a' => ['type' => 'index', 'fields' => 'a']],
        [['name' => 'a', 'index' => 'index'], ['name' => 'b', 'index' => 'index']],
        [],
    ]);
    expect(array_keys($out))->toBe(['idx_b', 'idx_a']);

    // 只改 type,entry 其余键与 idx name 保留
    expect(spmCall('rebuildTableIndex', [
        ['idx_b' => ['type' => 'index', 'fields' => 'b', 'comment' => 'keep']],
        [['name' => 'b', 'index' => 'unique-db']],
        [],
    ]))->toBe(['idx_b' => ['type' => 'unique', 'fields' => 'b', 'comment' => 'keep']]);
});

it('§9 rebuildTableIndex · 数组形式单字段索引 fields:[a] 也归入 single', function () {
    expect(spmCall('rebuildTableIndex', [
        ['idx_a' => ['type' => 'index', 'fields' => ['a']]],
        [['name' => 'a', 'index' => 'unique-db']],
        [],
    ]))->toBe(['idx_a' => ['type' => 'unique', 'fields' => 'a']]);
});

it('§9 rebuildTableIndex · client 新增 single 按 client.fields 顺序追加在 yaml 原序之后', function () {
    $out = spmCall('rebuildTableIndex', [
        ['idx_a' => ['type' => 'index', 'fields' => 'a']],
        [['name' => 'a', 'index' => 'index'], ['name' => 'c', 'index' => 'index'], ['name' => 'd', 'index' => 'primary']],
        [],
    ]);
    expect(array_keys($out))->toBe(['idx_a', 'c', 'd']);
});

it('§9 rebuildTableIndex · client 去掉 index → 原 entry 被移除', function () {
    expect(spmCall('rebuildTableIndex', [
        ['idx_a' => ['type' => 'index', 'fields' => 'a']],
        [['name' => 'a']],
        [],
    ]))->toBe([]);
});

it('§9 rebuildTableIndex · F30:multi_indexes 传了 → 单源驱动,忽略 yaml 原 multi', function () {
    $out = spmCall('rebuildTableIndex', [
        ['idx_old' => ['type' => 'index', 'fields' => ['x', 'y']]],
        [['name' => 'a', 'index' => 'index']],
        ['multi_indexes' => [['name' => 'idx_ab', 'type' => 'index', 'fields' => ['a', 'b']]]],
    ]);
    expect($out)->toBe([
        'a'      => ['type' => 'index', 'fields' => 'a'],
        'idx_ab' => ['type' => 'index', 'fields' => ['a', 'b']],
    ]);
});

it('§9 rebuildTableIndex · multi_indexes 显式空数组 → 走单源分支,yaml 原 multi 全丢', function () {
    expect(spmCall('rebuildTableIndex', [
        ['idx_ab' => ['type' => 'index', 'fields' => ['a', 'b']]],
        [],
        ['multi_indexes' => []],
    ]))->toBe([]);
});

it('§9 rebuildTableIndex · multi_indexes 键不存在 → fallback yaml 原 multi', function () {
    expect(spmCall('rebuildTableIndex', [[], [], []]))->toBe([]);
    expect(spmCall('rebuildTableIndex', [
        ['idx_ab' => ['type' => 'index', 'fields' => ['a', 'b']]],
        [],
        [],
    ]))->toBe(['idx_ab' => ['type' => 'index', 'fields' => ['a', 'b']]]);
});

it('§9 rebuildTableIndex · multi_indexes 非法项(空名 / 非法 type / 字段数 <2)→ 跳过', function () {
    expect(spmCall('rebuildTableIndex', [[], [], ['multi_indexes' => [
        ['name' => '', 'type' => 'index', 'fields' => ['a', 'b']],
        ['name' => 'n1', 'type' => 'bogus', 'fields' => ['a', 'b']],
        ['name' => 'n2', 'type' => 'index', 'fields' => ['a']],
    ]]]))->toBe([]);
});

/* ═══════════════════════════════════════════════════════════════════════
 * §10 applyEnums — F36 client.enums 单源 + plan-40 §二 P1/P2 sanitize
 * ═══════════════════════════════════════════════════════════════════════ */

it('§10 applyEnums · $cEnums 为 null(老 client)→ 整个 enums 块一字不动', function () {
    expect(spmCall('applyEnums', [['enums' => ['s' => ['k' => ['v', 'En', 'Zh']]]], null]))
        ->toBe(['enums' => ['s' => ['k' => ['v', 'En', 'Zh']]]]);
});

it('§10 applyEnums · 传了但产不出任何 entry → unset enums(F36 完全替换语义)', function () {
    expect(spmCall('applyEnums', [['enums' => ['s' => ['k' => [1, 'a', 'b']]]], []]))->toBe([]);
    expect(spmCall('applyEnums', [['enums' => ['s' => []]], [['field' => '', 'items' => [['key' => 'k']]]]]))->toBe([]);
    expect(spmCall('applyEnums', [['enums' => ['s' => []]], [['field' => 's', 'items' => []]]]))->toBe([]);
});

it('§10 applyEnums · 正常一组:yaml 形态 { key: [value, label_en, label_zh] }', function () {
    expect(spmCall('applyEnums', [[], [['field' => 'status', 'items' => [
        ['key' => 'on', 'value' => 1, 'label_en' => 'On', 'label_zh' => '开'],
        ['key' => 'off', 'value' => 0, 'label_en' => 'Off', 'label_zh' => '关'],
    ]]]]))->toBe(['enums' => ['status' => [
        'on'  => [1, 'On', '开'],
        'off' => [0, 'Off', '关'],
    ]]]);
});

it('§10 applyEnums · 空 key + 有内容 → __pending_<n> 递增占位(2026-05-21 翻译辅助)', function () {
    $out = spmCall('applyEnums', [[], [['field' => 's', 'items' => [
        ['key' => '', 'value' => '', 'label_en' => 'A', 'label_zh' => ''],
        ['key' => '', 'value' => 'x', 'label_en' => '', 'label_zh' => ''],
    ]]]]);
    expect(array_keys($out['enums']['s']))->toBe(['__pending_0', '__pending_1']);
});

it('§10 applyEnums · 空 key + 三项全空 → 跳过(不浪费占位)', function () {
    expect(spmCall('applyEnums', [['enums' => ['s' => []]], [['field' => 's', 'items' => [
        ['key' => '', 'value' => '', 'label_en' => '', 'label_zh' => ''],
    ]]]]))->toBe([]);
});

it('§10 applyEnums · label_en / label_zh 走 sanitize(尖括号 / quote / 反斜杠被剥)', function () {
    $out = spmCall('applyEnums', [[], [['field' => 's', 'items' => [
        ['key' => 'k', 'value' => 'v', 'label_en' => '<b>Hi</b>', 'label_zh' => "it's\\"],
    ]]]]);
    // 注意 `/` 不在剥离集合里 → `<b>Hi</b>` 只掉尖括号,得 `bHi/b`
    expect($out['enums']['s']['k'])->toBe(['v', 'bHi/b', 'its']);
});

it('§10 applyEnums · value 走 sanitize,但纯数字串(含负号)原样保留(plan-40 §二 P1)', function () {
    $out = spmCall('applyEnums', [[], [['field' => 's', 'items' => [
        ['key' => 'num', 'value' => '-12', 'label_en' => '', 'label_zh' => ''],
        ['key' => 'txt', 'value' => 'a<b>', 'label_en' => '', 'label_zh' => ''],
    ]]]]);
    expect($out['enums']['s']['num'][0])->toBe('-12');
    expect($out['enums']['s']['txt'][0])->toBe('ab');
});

it('§10 applyEnums · 多组 field:产不出 entry 的组不进 newEnums', function () {
    $out = spmCall('applyEnums', [[], [
        ['field' => 'a', 'items' => [['key' => 'k', 'value' => 1, 'label_en' => '', 'label_zh' => '']]],
        ['field' => 'b', 'items' => []],
    ]]);
    expect(array_keys($out['enums']))->toBe(['a']);
});

/* ═══════════════════════════════════════════════════════════════════════
 * §11 sanitizeEnumLabel — Round 2 P2 防下游 XSS 的兜底
 * ═══════════════════════════════════════════════════════════════════════ */

it('§11 sanitizeEnumLabel · 空串原样返回(不做 trim 之外的加工)', function () {
    expect(spmCall('sanitizeEnumLabel', ['']))->toBe('');
});

it('§11 sanitizeEnumLabel · 剥掉 < > " \' \\ 与 ASCII 控制字符', function () {
    expect(spmCall('sanitizeEnumLabel', ['<script>alert("x")</script>']))->toBe('scriptalert(x)/script');
    expect(spmCall('sanitizeEnumLabel', ["a'b\\c"]))->toBe('abc');
    expect(spmCall('sanitizeEnumLabel', ["\x00a\x1Fb\x7Fc"]))->toBe('abc');
});

it('§11 sanitizeEnumLabel · 前后空白 trim,中文/标点/数字保留', function () {
    expect(spmCall('sanitizeEnumLabel', ['  sp  ']))->toBe('sp');
    expect(spmCall('sanitizeEnumLabel', ['中文，标点。42']))->toBe('中文，标点。42');
});

it('§11 sanitizeEnumLabel · cap 64(按字符数,不是字节数)', function () {
    expect(mb_strlen(spmCall('sanitizeEnumLabel', [str_repeat('字', 70)]), 'UTF-8'))->toBe(64);
    expect(spmCall('sanitizeEnumLabel', [str_repeat('字', 64)]))->toBe(str_repeat('字', 64));
});

/* ═══════════════════════════════════════════════════════════════════════
 * §12 coerceFieldValue — DOM input.value 永远是 string,按 effectiveType 反 cast
 * ═══════════════════════════════════════════════════════════════════════ */

it('§12 coerceFieldValue · precision 永远 int(digit 串 → int,其余原样)', function () {
    expect(spmCall('coerceFieldValue', ['precision', '2', 'decimal']))->toBe(2);
    expect(spmCall('coerceFieldValue', ['precision', 2, 'decimal']))->toBe(2);
    expect(spmCall('coerceFieldValue', ['precision', 'x', 'decimal']))->toBe('x');
    expect(spmCall('coerceFieldValue', ['precision', -3, 'decimal']))->toBe(-3);
});

it('§12 coerceFieldValue · 非 size / default 的 attr 一律原样透传', function () {
    expect(spmCall('coerceFieldValue', ['comment', '42', 'int']))->toBe('42');
    expect(spmCall('coerceFieldValue', ['required', true, 'int']))->toBeTrue();
    expect(spmCall('coerceFieldValue', ['type', 'varchar', 'varchar']))->toBe('varchar');
});

it('§12 coerceFieldValue · 非 string 值不动(已 cast 的 int/bool/float 不被二次转换)', function () {
    expect(spmCall('coerceFieldValue', ['size', 64, 'varchar']))->toBe(64);
    expect(spmCall('coerceFieldValue', ['default', [], 'int']))->toBe([]);
});

it('§12 coerceFieldValue · size:digit 串 → int;decimal 的 "m,n" 保 string;其余含逗号串原样', function () {
    expect(spmCall('coerceFieldValue', ['size', '64', 'varchar']))->toBe(64);
    expect(spmCall('coerceFieldValue', ['size', '10,2', 'decimal']))->toBe('10,2');
    expect(spmCall('coerceFieldValue', ['size', '10,2', 'varchar']))->toBe('10,2');
    expect(spmCall('coerceFieldValue', ['size', 'abc', 'varchar']))->toBe('abc');
});

it('§12 coerceFieldValue · default 按 type 联动:整数族 → int', function () {
    expect(spmCall('coerceFieldValue', ['default', '7', 'int']))->toBe(7);
    expect(spmCall('coerceFieldValue', ['default', '-7', 'bigint']))->toBe(-7);
    expect(spmCall('coerceFieldValue', ['default', '7.5', 'int']))->toBe('7.5');   // 不匹配 ^-?\d+$
    expect(spmCall('coerceFieldValue', ['default', 'abc', 'int']))->toBe('abc');
});

it('§12 coerceFieldValue · default 按 type 联动:浮点族 → float', function () {
    expect(spmCall('coerceFieldValue', ['default', '1.5', 'decimal']))->toBe(1.5);
    expect(spmCall('coerceFieldValue', ['default', '3', 'float']))->toBe(3.0);
    expect(spmCall('coerceFieldValue', ['default', 'x', 'double']))->toBe('x');
});

it('§12 coerceFieldValue · default 按 type 联动:布尔族 → true/false(大小写不敏感),其余原样', function () {
    expect(spmCall('coerceFieldValue', ['default', 'true', 'boolean']))->toBeTrue();
    expect(spmCall('coerceFieldValue', ['default', '1', 'bool']))->toBeTrue();
    expect(spmCall('coerceFieldValue', ['default', '0', 'boolean']))->toBeFalse();
    expect(spmCall('coerceFieldValue', ['default', 'FALSE', 'bool']))->toBeFalse();
    expect(spmCall('coerceFieldValue', ['default', 'yes', 'boolean']))->toBe('yes');
});

it('§12 coerceFieldValue · unknown type + string default → 原样(string 兜底)', function () {
    expect(spmCall('coerceFieldValue', ['default', 'hello', 'varchar']))->toBe('hello');
    expect(spmCall('coerceFieldValue', ['default', '7', null]))->toBe('7');
});

/* ═══════════════════════════════════════════════════════════════════════
 * §13 结构锚点 —— 钉住"外迁后该长什么样"
 *     为什么要有:上面 §1~§12 全是行为断言,宿主换成 SchemaLoader 也照样绿
 *     (那正是外迁前的状态)。这一节把「已经搬走了」这件事本身变成可证伪的断言,
 *     否则有人把方法搬回旧宿主、或悄悄加回一个实例属性,行为测试不会响。
 * ═══════════════════════════════════════════════════════════════════════ */

/** 外迁后的新宿主源码路径(锚点专用,与 spmSubject() 解耦)。 */
function spmSourcePath(): string
{
    return dirname(__DIR__, 3) . '/src/Designer/SchemaPayloadMerger.php';
}

/**
 * 去掉注释(可选再去掉字符串字面量)后的源码 —— 结构断言必须先剥注释,
 * 否则类 docblock 里对类名/`$this` 的**文字提及**会让「零依赖」断言假红。
 */
function spmCodeOnly(string $php, bool $keepStrings = true): string
{
    $out = '';
    foreach (token_get_all($php) as $t) {
        if (! is_array($t)) {
            $out .= $t;

            continue;
        }
        if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
            continue;
        }
        if (! $keepStrings && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        $out .= $t[1];
    }

    return $out;
}

/** 迁走的 12 个方法名(唯一真源,§13 各条共用)。 */
function spmMovedMethods(): array
{
    return [
        'applyModuleBlock', 'changeSnapshot', 'applyTableAttrs', 'applyTableModel',
        'applyTableController', 'applyRenameHints', 'rebuildFieldRows', 'sortRowAttrs',
        'rebuildTableIndex', 'applyEnums', 'sanitizeEnumLabel', 'coerceFieldValue',
    ];
}

it('§13 新宿主是 final + 无构造器 + 零属性(与 Paths / ControllerName / ApiParameterFormatter 同形)', function () {
    $ref = new ReflectionClass(\Mooeen\Scaffold\Designer\SchemaPayloadMerger::class);

    expect($ref->isFinal())->toBeTrue();
    expect($ref->getConstructor())->toBeNull();
    expect($ref->getProperties())->toBe([]);
    expect($ref->getParentClass())->toBeFalse();
});

it('§13 方法集合恰好等于迁走的 12 个,且**全部**是 static', function () {
    $ref   = new ReflectionClass(\Mooeen\Scaffold\Designer\SchemaPayloadMerger::class);
    $names = array_map(static fn ($m) => $m->getName(), $ref->getMethods());
    sort($names);

    $want = spmMovedMethods();
    sort($want);
    expect($names)->toBe($want);

    $instanceMethods = array_values(array_map(
        static fn ($m) => $m->getName(),
        array_filter($ref->getMethods(), static fn ($m) => ! $m->isStatic()),
    ));
    expect($instanceMethods)->toBe([]);
});

it('§13 公开面恰 10 个,两个纯内部助手(sortRowAttrs / coerceFieldValue)保持 private', function () {
    $ref    = new ReflectionClass(\Mooeen\Scaffold\Designer\SchemaPayloadMerger::class);
    $public = array_map(static fn ($m) => $m->getName(), $ref->getMethods(ReflectionMethod::IS_PUBLIC));
    sort($public);

    $want = spmMovedMethods();
    sort($want);
    expect($public)->toBe(array_values(array_diff($want, ['coerceFieldValue', 'sortRowAttrs'])));

    expect($ref->getMethod('sortRowAttrs')->isPrivate())->toBeTrue();
    expect($ref->getMethod('coerceFieldValue')->isPrivate())->toBeTrue();
});

it('§13 旧宿主不再持有族内任何一个方法(搬回即红)', function () {
    $old  = new ReflectionClass(SchemaLoader::class);
    $back = array_values(array_filter(spmMovedMethods(), static fn (string $n) => $old->hasMethod($n)));
    expect($back)->toBe([]);
});

it('§13 旧宿主仍持有 saveModule(public 非 static)与五个实例态属性 —— 状态没被搬走', function () {
    $old = new ReflectionClass(SchemaLoader::class);

    $saveModule = $old->getMethod('saveModule');
    expect($saveModule->isPublic())->toBeTrue();
    expect($saveModule->isStatic())->toBeFalse();

    // 跨请求敏感的 memo 必须留在调用方:做成 static 会跨请求残留
    $props = array_map(static fn ($p) => $p->getName(), $old->getProperties());
    expect($props)->toContain('cache')
        ->toContain('listModulesCache')
        ->toContain('originMap')
        ->toContain('migrationBatchCache')
        ->toContain('migrationFilesCache');
});

it('§13 剥掉注释与字符串字面量后:零 $this、不引用旧宿主、不引用任何 Designer\ 私有面', function () {
    $code = spmCodeOnly(file_get_contents(spmSourcePath()), keepStrings: false);

    expect($code)->not->toContain('$this');
    expect($code)->not->toContain('SchemaLoader');
    expect($code)->not->toContain('new self');
});

it('§13 use 清单恰好是三个纯静态依赖(多一个都要解释)', function () {
    $code = spmCodeOnly(file_get_contents(spmSourcePath()));
    $head = substr($code, 0, strpos($code, 'final class'));
    preg_match_all('/^use\s+([^\s;]+);/m', $head, $m);

    expect($m[1])->toBe([
        'Mooeen\Scaffold\Support\AppTargetRegistry',
        'Mooeen\Scaffold\Support\ColumnTypeGroups',
        'Mooeen\Scaffold\Support\ControllerName',
    ]);
});

it('§13 族内互调只走 self::,且三处调用次数被钉死(重复实现 / 忘了改接收者都会红)', function () {
    $code = spmCodeOnly(file_get_contents(spmSourcePath()));

    expect(substr_count($code, 'self::coerceFieldValue('))->toBe(1);
    expect(substr_count($code, 'self::sortRowAttrs('))->toBe(1);
    expect(substr_count($code, 'self::sanitizeEnumLabel('))->toBe(3);
});

it('§13 唯一非纯点被限制在 applyTableController 的两处容器校验,context 字面量各一', function () {
    $code = spmCodeOnly(file_get_contents(spmSourcePath()));

    expect(substr_count($code, 'app(AppTargetRegistry::class)->assertConfigured('))->toBe(2);
    expect(substr_count($code, "assertConfigured(\$appList, 'controller.app')"))->toBe(1);
    expect(substr_count($code, "assertConfigured(\$resource, 'controller.resource')"))->toBe(1);
});

it('§13 spmSubject() 已翻到新宿主 ⇒ §1~§12 的断言现在跑在新类上(断言一字未动)', function () {
    expect(spmSubject())->toBe('Mooeen\\Scaffold\\Designer\\SchemaPayloadMerger');
    expect((new ReflectionMethod(spmSubject(), 'applyEnums'))->isStatic())->toBeTrue();
});
