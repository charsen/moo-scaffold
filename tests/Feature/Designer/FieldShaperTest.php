<?php declare(strict_types=1);

/**
 * `loadTableFull` 族（`shape` + `computeSizeClass` / `computeDefaultClass` / `computeDefaultTitle`）
 * 的**行为钉尸**测试。
 *
 * **为什么先有测试、后有外迁**：这一族 4 个私有方法 / 151 行块，此前在 `tests/` 里**零直接覆盖**。
 * 只有两条 `loadTableFull` 用例断了「`fields[*]` 里**有** `key`/`size_class` 这些键」，
 * 「键的**取值**」一个都没钉。按 `TUNING-PLAN.md` 红线 9「测试钉现状先行」，
 * 本文件**先**钉住现状（外迁前即须全绿），**再**把这一族搬到 `Designer\FieldShaper`（`final` + 全静态）。
 *
 * **本文件是「同一批断言跨两个宿主」的**：外迁前 `fshSubject()` 返回 `SchemaLoader::class`
 * （4 个方法都是私有**实例**方法 ⇒ 反射 + 一个实例），外迁后返回 `FieldShaper::class`
 * （全静态 ⇒ `invoke(null, ...)`）—— **全部行为断言一个字都没动**，跟着宿主变的只有
 * `fshSubject()` 一行。这就是「行为不变」的机器证明，而不是「看着像」。
 *
 * 为什么值得单独一个文件（而不是塞进 `SchemaLoaderTest`）——判据是**三层**里最上面那层：
 *   ① 有没有「单入口可达的族」：有，`loadTableFull` 是本族唯一入口；
 *   ② 族内有没有**零状态子集**：有，4 个方法 0 处 `$this`、只用入参 + `ColumnTypeGroups::` 静态常量；
 *   ③ 搬完两边是否各自成立：新家 = 纯函数 shaper；老家 `SchemaLoader` 仍是 yaml load/merge/write I/O。
 * （`CreateApiGenerator` 那种「族级持 14 处状态」的读数属于**聚合读数**，它会掩盖族内的零状态子集 ——
 *  所以判据必须逐方法量，不能停在族级。）
 *
 * ⚠ 本文件只**钉现状**，一个 bug 都不修。§4 末条标了 `〔现状 · 疑似缺陷〕` —— 那是本次外迁
 * **顺手发现**的既有问题，故意留原样、另开项处理：把行为变更混进「零变化的搬家」会让整体失去鉴别力。
 *
 * 文件分两段：**行为**段（跨宿主不变）+ **结构**段（外迁后的形状契约，外迁前整体 skip）。
 */

use Mooeen\Scaffold\Designer\FieldShaper;
use Mooeen\Scaffold\Designer\SchemaLoader;
use Mooeen\Scaffold\Support\ColumnTypeGroups;
use Mooeen\Scaffold\Tests\Feature\Designer\Support\FixtureSchema;
use Mooeen\Scaffold\Utility;

/** 宿主解析点。**外迁时只改这一行**（外迁前是 `SchemaLoader::class`）。 */
function fshSubject(): string
{
    return FieldShaper::class;
}

/**
 * 外迁后的目标宿主。
 *
 * 刻意写成**字符串字面量**（而不是 `FieldShaper::class`）：外迁前这个类还不存在，
 * `class_exists()` 必须能安全求值为 false 才能让 §6 整体 skip、保证「改前先绿」。
 * 它与 `fshSubject()` 的返回值由 §6 末条 `expect(fshSubject())->toBe(fshNewHost())` 绑在一起 ——
 * 两处写不一致时那条会红。
 */
function fshNewHost(): string
{
    return 'Mooeen\\Scaffold\\Designer\\FieldShaper';
}

/**
 * 调族内方法。
 *
 * 外迁前 4 个都是**私有实例**方法（需要一个实例来接），外迁后全静态。
 * 实例用 `new SchemaLoader(new Utility)` 直造 —— ctor 只存 `Utility`，无 FS / DB 副作用
 * （同 `SchemaPayloadMergerTest` 的做法：刻意不走容器，免得与 `FixtureSchema` 的 config 变更耦合）。
 */
function fshCall(string $method, mixed ...$args): mixed
{
    $ref = new ReflectionMethod(fshSubject(), $method);
    $ref->setAccessible(true);

    if ($ref->isStatic()) {
        return $ref->invoke(null, ...$args);
    }

    static $host = null;
    $host ??= new SchemaLoader(new Utility);

    return $ref->invoke($host, ...$args);
}

/** 族入口（行为段一律走这里，不自报到具体方法名）。 */
function fshShape(string $name, array $attr, bool $tableLocked = true): array
{
    return fshCall('shape', $name, $attr, $tableLocked);
}

it('宿主解析点有效：fshSubject() 指向的类存在且持有入口方法', function () {
    expect(class_exists(fshSubject()))->toBeTrue()
        ->and((new ReflectionClass(fshSubject()))->hasMethod('shape'))->toBeTrue();
});

/* ═══════════════════════════════════════════════════════════════════════
 * §1 形状契约 —— 键集一个不多一个不少（UI 模板按属性访问，加键/删键都不是无痕操作）
 * ═══════════════════════════════════════════════════════════════════════ */

it('§1 shape 产出的键集合恰好等于这 37 个', function () {
    $keys = array_keys(fshShape('id', ['type' => 'bigint']));
    sort($keys);

    expect($keys)->toBe([
        '__rowId', '_nullable_dirty', '_orig_key', '_unsigned_dirty', 'can_remove',
        'comment', 'default', 'default_class', 'default_title', 'format', 'has_spell_warning',
        'index', 'key', 'min_size', 'name', 'name_readonly', 'nullable', 'precision',
        'precision_disabled', 'prefix_strip_btn_class', 'prefix_strip_disabled',
        'prefix_strippable', 'required', 'row_class', 'row_readonly', 'row_title',
        'size', 'size_class', 'size_title', 'spell_reason', 'spell_suggestion',
        'spell_warn_class', 'spell_warning', 'type', 'unique', 'unsigned', 'unsigned_disabled',
    ]);
});

/* ═══════════════════════════════════════════════════════════════════════
 * §2 只读 / 可删 / 行提示 —— 判据是 `_system` 标记与 `id` 名，不是字段名的字面
 * ═══════════════════════════════════════════════════════════════════════ */

it('§2 id 行：只读、**不可删**、ID 提示；且 index_disabled 不由本族产出', function () {
    $s = fshShape('id', ['type' => 'bigint', 'unsigned' => true, '_system' => 'id', 'required' => true]);

    expect($s['key'])->toBe('id')
        // __rowId / _orig_key 三兄弟都取字段名（__rowId = session 内 stable 标识，_orig_key = yaml 原键锚点）
        ->and($s['__rowId'])->toBe('id')
        ->and($s['_orig_key'])->toBe('id')
        ->and($s['row_readonly'])->toBeTrue()
        ->and($s['name_readonly'])->toBeTrue()
        ->and($s['row_class'])->toBe('is-readonly')
        ->and($s['can_remove'])->toBeFalse()
        ->and($s['row_title'])->toBe('ID 主键，固定不可改不可删')
        // 本族**不产** index_disabled —— 它由 loadTableFull 在 index 反向映射完成之后补（§5 钉住这一步）
        ->and(array_key_exists('index_disabled', $s))->toBeFalse();

    // ⚠ 反例（必须存在，否则上面那句没鉴别力）：**没有** `_system` 标记的 `id` 行，只读性只能来自
    //   `$name === 'id'` 这条兜底。真实链路上 `normalizeIdField` 总会补 `_system`，所以这条兜底
    //   在生产数据下是冗余的 —— 但外迁后 `shape` 变成**公开 API**，兜底就变成「调用方不必记住
    //   打标记」的真实契约，不能当成死代码删掉。（本反例是变异测试实测漏网后补的：当时把
    //   `|| ($name === 'id')` 整段删掉，15 条断言全绿。）
    $bare = fshShape('id', ['type' => 'bigint']);

    expect(array_key_exists('_system', ['type' => 'bigint']))->toBeFalse()
        ->and($bare['row_readonly'])->toBeTrue()
        ->and($bare['name_readonly'])->toBeTrue()
        ->and($bare['row_class'])->toBe('is-readonly')
        ->and($bare['can_remove'])->toBeFalse()
        ->and($bare['row_title'])->toBe('ID 主键，固定不可改不可删')
        // 与 §2 下一条的 deleted_at 对照：**只有** `id` 这个名字有兜底，其它系统名没有
        ->and(fshShape('another_id', ['type' => 'bigint'])['row_readonly'])->toBeFalse();
});

it('§2 系统时间戳行：只读但**可整行删**；四种名字给四种提示', function () {
    $row = static fn (string $name): array => fshShape($name, ['_system' => true, 'type' => 'timestamp', 'required' => false]);

    expect($row('deleted_at')['row_title'])->toBe('软删除时间戳。行内只读，但可整行删除（行末 × 按钮）')
        ->and($row('created_at')['row_title'])->toBe('自动维护的创建时间。行内只读，但可整行删除（行末 × 按钮）')
        ->and($row('updated_at')['row_title'])->toBe('自动维护的更新时间。行内只读，但可整行删除（行末 × 按钮）')
        // 系统标记下「名字不认识」⇒ 落到通用提示（**不是**空串，也不是上面三条里的任何一条）
        ->and($row('touched_at')['row_title'])->toBe('系统字段，行内只读');

    // 四条都是「只读但可删」—— 与 §2 上一条的 id 行（不可删）互为对照
    foreach (['deleted_at', 'created_at', 'updated_at', 'touched_at'] as $name) {
        expect($row($name)['row_readonly'])->toBeTrue()
            ->and($row($name)['can_remove'])->toBeTrue()
            ->and($row($name)['row_class'])->toBe('is-readonly');
    }
});

it('§2 普通行：可写、可删、无提示 —— 名叫 deleted_at 但没有 `_system` 标记也算普通行', function () {
    $plain  = fshShape('deleted_at', ['type' => 'timestamp', 'required' => true]);
    $marked = fshShape('deleted_at', ['_system' => true, 'type' => 'timestamp', 'required' => false]);

    // 同一个字段名、有无 `_system` 标记 ⇒ 两个世界。⚠ 这两条互为对照，
    // 用来把「按字段名字面硬编码」的变异钉死（只看上面那条的话，硬编码 deleted_at 也会绿）。
    expect($plain['row_readonly'])->toBeFalse()
        ->and($plain['row_class'])->toBe('')
        ->and($plain['row_title'])->toBe('')
        ->and($plain['can_remove'])->toBeTrue()
        ->and($plain['name_readonly'])->toBeFalse()
        ->and($plain['nullable'])->toBeFalse()          // required=true ⇒ nullable=false
        ->and($marked['row_readonly'])->toBeTrue()
        ->and($marked['row_title'])->not->toBe('');
});

it('§2 $tableLocked 对字段形状**完全无影响**（表锁只锁表级操作，字段编辑一律允许）', function () {
    foreach ([
        ['title', ['type' => 'varchar', 'size' => 64]],
        ['id', ['type'            => 'bigint', '_system' => 'id']],
        ['created_at', ['_system' => true, 'type' => 'timestamp', 'required' => false]],
    ] as [$name, $attr]) {
        expect(fshShape($name, $attr, tableLocked: true))
            ->toBe(fshShape($name, $attr, tableLocked: false));
    }
});

it('§2 unsigned 兜底用 codegen 的**窄列表**，nullability 跟 required 反相', function () {
    // 无显式 unsigned + 类型在 UNSIGNED_DEFAULT ⇒ 兜底 true
    expect(fshShape('a', ['type' => 'int'])['unsigned'])->toBeTrue()
        ->and(fshShape('a', ['type' => 'bigint'])['unsigned'])->toBeTrue()
        ->and(fshShape('a', ['type' => 'tinyint'])['unsigned'])->toBeTrue()
        ->and(fshShape('a', ['type' => 'decimal'])['unsigned'])->toBeTrue()
        // ⚠ smallint / mediumint / double **故意不在**窄列表里 ⇒ 兜底 false（与 codegen 的实际行为对齐）
        ->and(fshShape('a', ['type' => 'smallint'])['unsigned'])->toBeFalse()
        ->and(fshShape('a', ['type' => 'mediumint'])['unsigned'])->toBeFalse()
        ->and(fshShape('a', ['type' => 'double'])['unsigned'])->toBeFalse()
        // varchar 永不兜底 true
        ->and(fshShape('a', ['type' => 'varchar'])['unsigned'])->toBeFalse()
        // 显式给了就听显式的 —— 哪怕显式 false 能压掉兜底的 true
        ->and(fshShape('a', ['type' => 'int', 'unsigned' => false])['unsigned'])->toBeFalse()
        ->and(fshShape('a', ['type' => 'varchar', 'unsigned' => true])['unsigned'])->toBeTrue();

    // required 缺省 true；nullable 恒为它的反相
    expect(fshShape('a', ['type' => 'int'])['required'])->toBeTrue()
        ->and(fshShape('a', ['type' => 'int'])['nullable'])->toBeFalse()
        ->and(fshShape('a', ['type' => 'int', 'required' => false])['required'])->toBeFalse()
        ->and(fshShape('a', ['type' => 'int', 'required' => false])['nullable'])->toBeTrue();
});

it('§2 unsigned_disabled / precision_disabled 两个门控与 codegen 分组逐类型对齐', function () {
    $at = static fn (string $type): array => fshShape('a', ['type' => $type]);

    foreach (ColumnTypeGroups::NUMERIC as $type) {
        expect($at($type)['unsigned_disabled'])->toBeFalse();
    }
    foreach (ColumnTypeGroups::FLOAT as $type) {
        expect($at($type)['precision_disabled'])->toBeFalse();
    }
    foreach (ColumnTypeGroups::STRING as $type) {
        expect($at($type)['unsigned_disabled'])->toBeTrue()
            ->and($at($type)['precision_disabled'])->toBeTrue();
    }

    // bigint 在 NUMERIC 但**不在** FLOAT —— 这一条把两个分组的界线钉死（合并二者即刻红）
    expect($at('bigint')['unsigned_disabled'])->toBeFalse()
        ->and($at('bigint')['precision_disabled'])->toBeTrue()
        // timestamp 两边都不沾
        ->and($at('timestamp')['unsigned_disabled'])->toBeTrue()
        ->and($at('timestamp')['precision_disabled'])->toBeTrue();
});

/* ═══════════════════════════════════════════════════════════════════════
 * §3 size：`min_size` 合成显示串 + 「必须填 size」的三种类型
 * ═══════════════════════════════════════════════════════════════════════ */

it('§3 size 显示串：有 min_size 合成 `{min},{max}`，其余原样（`null` 与键缺失等价）', function () {
    // yaml 紧凑写法 'min,max' 经 parseSize 拆成 size + min_size；GUI input 只读 size ⇒ 这里合成回去
    expect(fshShape('a', ['type' => 'varchar', 'size' => 192, 'min_size' => 6])['size'])->toBe('6,192')
        ->and(fshShape('a', ['type' => 'varchar', 'size' => 192, 'min_size' => 6])['min_size'])->toBe(6)
        ->and(fshShape('a', ['type' => 'varchar', 'size' => 192])['size'])->toBe(192)
        // `isset` 判据 ⇒ 显式 null 与键缺失走同一条路
        ->and(fshShape('a', ['type' => 'varchar', 'size' => 192, 'min_size' => null])['size'])->toBe(192)
        // ⚠ min_size = 0 时 `isset` 仍为真 ⇒ 也合成（`0,192`）——这是现状，别顺手改成「非空才合成」
        ->and(fshShape('a', ['type' => 'varchar', 'size' => 192, 'min_size' => 0])['size'])->toBe('0,192')
        // size 本身缺失 ⇒ null（不是 0、不是空串）
        ->and(fshShape('a', ['type' => 'varchar'])['size'])->toBeNull()
        ->and(fshShape('a', ['type' => 'varchar'])['min_size'])->toBeNull();
});

it('§3 size_class / size_title 成对：只有 varchar/char/decimal 需要 size，缺了才红', function () {
    $pairs = [
        // [type, size, 期望 size_class, 期望 size_title]
        ['varchar', null, 'is-invalid', '此类型需要 size，请填（如 varchar 64）'],
        ['varchar', '', 'is-invalid', '此类型需要 size，请填（如 varchar 64）'],
        // 下面四种「空」都要算空 —— 少认一个就会在 GUI 上漏红
        ['varchar', 0, 'is-invalid', '此类型需要 size，请填（如 varchar 64）'],
        ['varchar', '0', 'is-invalid', '此类型需要 size，请填（如 varchar 64）'],
        ['char', null, 'is-invalid', '此类型需要 size，请填（如 varchar 64）'],
        ['decimal', null, 'is-invalid', '此类型需要 size，请填（如 varchar 64）'],
        // 给了 size ⇒ 绿，且 title 必须一起变空（两个方向都钉住，防「只改 class 忘改 title」）
        ['varchar', 64, '', ''],
        ['char', 36, '', ''],
        ['decimal', 8, '', ''],
        // 不在名单里的类型 ⇒ 永远绿。**int 无 size 也不红**，与 varchar 互为对照
        ['int', null, '', ''],
        ['bigint', null, '', ''],
        ['text', null, '', ''],
        ['timestamp', null, '', ''],
    ];

    foreach ($pairs as [$type, $size, $class, $title]) {
        $s = fshShape('a', ['type' => $type, 'size' => $size]);

        expect($s['size_class'])->toBe($class)
            ->and($s['size_title'])->toBe($title);
    }
});

/* ═══════════════════════════════════════════════════════════════════════
 * §4 default：空值永远放过；数值族必须数字；bool 支〔现状见末条〕
 * ═══════════════════════════════════════════════════════════════════════ */

it('§4 空 default 永远绿（null / 空串，任何类型都不红、无提示）', function () {
    foreach (['int', 'decimal', 'float', 'bool', 'boolean', 'varchar', 'text', 'timestamp'] as $type) {
        expect(fshCall('computeDefaultClass', $type, null))->toBe('')
            ->and(fshCall('computeDefaultClass', $type, ''))->toBe('')
            ->and(fshCall('computeDefaultTitle', $type, null))->toBe('')
            ->and(fshCall('computeDefaultTitle', $type, ''))->toBe('');
    }
});

it('§4 数值族 8 个类型逐个钉：数字放过、非数字红，提示里用**具体类型名**', function () {
    foreach (ColumnTypeGroups::NUMERIC as $type) {
        expect(fshCall('computeDefaultClass', $type, '12'))->toBe('')
            ->and(fshCall('computeDefaultClass', $type, 12))->toBe('')
            // ⚠ 0 是数字：`$default === ''` 是**严格**比较，没把 int 0 / '0' 误伤成空值
            ->and(fshCall('computeDefaultClass', $type, 0))->toBe('')
            ->and(fshCall('computeDefaultClass', $type, '0'))->toBe('')
            ->and(fshCall('computeDefaultClass', $type, '3.5'))->toBe('')
            ->and(fshCall('computeDefaultClass', $type, '-7'))->toBe('')
            ->and(fshCall('computeDefaultClass', $type, 'abc'))->toBe('is-invalid')
            ->and(fshCall('computeDefaultClass', $type, '12abc'))->toBe('is-invalid')
            // title 用**具体类型名**（不是 'numeric' 这类分组名）⇒ 写死成某一种类型即刻红
            ->and(fshCall('computeDefaultTitle', $type, 'abc'))->toBe($type . ' 默认值须数字')
            ->and(fshCall('computeDefaultTitle', $type, '12'))->toBe('');
    }
});

it('§4 非数值非 bool 的类型对 default 一律宽松（不校验、不提示）', function () {
    foreach (['varchar', 'char', 'text', 'longtext', 'timestamp', 'datetime', 'date'] as $type) {
        expect(fshCall('computeDefaultClass', $type, '随便写'))->toBe('')
            ->and(fshCall('computeDefaultClass', $type, '2026-01-01 00:00:00'))->toBe('')
            ->and(fshCall('computeDefaultClass', $type, true))->toBe('')
            ->and(fshCall('computeDefaultTitle', $type, '随便写'))->toBe('');
    }
});

it('§4 bool 支只认字面 `bool` —— canonical `boolean` 走不到〔现状 · 疑似缺陷，本批只钉不改〕', function () {
    // (a) 直调私有方法、字面传 'bool' 时：这一支是**活的**
    expect(fshCall('computeDefaultClass', 'bool', 'yes'))->toBe('is-invalid')
        ->and(fshCall('computeDefaultClass', 'bool', '0'))->toBe('')
        ->and(fshCall('computeDefaultClass', 'bool', '1'))->toBe('')
        ->and(fshCall('computeDefaultClass', 'bool', 'true'))->toBe('')
        ->and(fshCall('computeDefaultClass', 'bool', 'false'))->toBe('')
        ->and(fshCall('computeDefaultTitle', 'bool', 'yes'))->toBe('bool 默认值须 0/1/true/false')
        ->and(fshCall('computeDefaultTitle', 'bool', '0'))->toBe('');

    // (b) ⚠ 但真实链路上 type 是 **canonical** 的：SchemaLoader 先 `ColumnTypeGroups::canonicalize`
    //     （L1090）再写回 `'type' => $type`（L1122），而 canonicalize 把 `bool`/`boolean` 一律归一成
    //     `'boolean'` —— 本支只认 `'bool'` ⇒ **bool 字段的 default 校验事实上从未生效**。
    //     这是本次外迁顺手发现的既有问题（与 ColumnTypeGroups 注释里记过的「更窄的 inline 列表
    //     让整类列静默丢掉校验」同型）。**本文件只钉死它、不修它**：修它 = 行为变更，
    //     必须单独过堂（会顺手改掉一个既有缺陷，混进「零变化的搬家」里没法 A/B）。
    expect(ColumnTypeGroups::canonicalize('bool'))->toBe('boolean')
        ->and(fshCall('computeDefaultClass', 'boolean', 'yes'))->toBe('')
        ->and(fshCall('computeDefaultTitle', 'boolean', 'yes'))->toBe('')
        // 与 (a) 互为对照：同一个非法值、两个类型名、两个结果 —— 这条能把「顺手把 bool 改成 boolean」
        // 的修复动作立刻照出来（那正是修这个缺陷该做的事，届时本条要跟着改成新预期）
        ->and(fshCall('computeDefaultClass', 'bool', 'yes'))->toBe('is-invalid')
        ->and(fshCall('computeDefaultClass', 'boolean', 'yes'))->not->toBe('is-invalid');
});

/* ═══════════════════════════════════════════════════════════════════════
 * §5 接线：真实链路 `loadTableFull` 的字段形状 = 本族产出 + 恰好一个 `index_disabled`
 * ═══════════════════════════════════════════════════════════════════════ */

it('§5 真 fixture 走 loadTableFull：键集 = 本族产出 + 恰好 `index_disabled`，取值逐字段钉住', function () {
    $orig = FixtureSchema::activate(app());

    try {
        $full = app(SchemaLoader::class)->loadTableFull(FixtureSchema::SCHEMA, FixtureSchema::TABLE);
    } finally {
        FixtureSchema::deactivate(app(), $orig);
    }

    $byKey = array_column($full['fields'], null, 'key');
    expect($byKey)->toHaveKeys(['id', 'user_name', 'user_age', 'created_at', 'updated_at']);

    // ── 分工契约：loadTableFull 只在本族产物上**加一个** `index_disabled`
    //   （`index` 键本族已给默认值 'none'，loadTableFull 只改它的值，不新增键）
    $direct = array_keys(fshShape('user_name', ['type' => 'varchar', 'size' => 64]));
    expect(array_values(array_diff(array_keys($byKey['user_name']), $direct)))->toBe(['index_disabled']);

    // ── 逐字段取值（两段各自的产出都覆盖到）
    expect($byKey['id']['key'])->toBe('id')
        // index 块 `id: {type: primary}` 反向映射 ⇒ 'primary' ⇒ index_disabled 被强制 true
        ->and($byKey['id']['index'])->toBe('primary')
        ->and($byKey['id']['index_disabled'])->toBeTrue()
        ->and($byKey['id']['can_remove'])->toBeFalse()
        ->and($byKey['id']['row_title'])->toBe('ID 主键，固定不可改不可删')
        ->and($byKey['id']['unsigned'])->toBeTrue()
        ->and($byKey['id']['size_class'])->toBe('')                 // bigint 不需 size
        // varchar 64：index 块反向映射成 'index'（**不是** 'primary'）⇒ index_disabled 保持 false
        ->and($byKey['user_name']['index'])->toBe('index')
        ->and($byKey['user_name']['index_disabled'])->toBeFalse()
        ->and($byKey['user_name']['size'])->toBe(64)
        ->and($byKey['user_name']['size_class'])->toBe('')
        ->and($byKey['user_name']['size_title'])->toBe('')
        ->and($byKey['user_name']['row_title'])->toBe('')
        ->and($byKey['user_name']['row_readonly'])->toBeFalse()
        // int + default 0：数字 ⇒ 绿；unsigned 走 codegen 窄列表兜底 ⇒ true
        ->and($byKey['user_age']['default'])->toBe(0)
        ->and($byKey['user_age']['default_class'])->toBe('')
        ->and($byKey['user_age']['default_title'])->toBe('')
        ->and($byKey['user_age']['unsigned'])->toBeTrue()
        ->and($byKey['user_age']['size_class'])->toBe('')           // int 不需 size
        // 系统时间戳：只读、可删、有提示；不在 index 块 ⇒ index='none'。
        // ⚠ 但 index_disabled 是 `row_readonly || index === 'primary'` ⇒ 系统行**仍是 true** ——
        //   这里「只读」就够了，不需要 index 是 primary（与 user_name 的 false 互为对照）
        ->and($byKey['created_at']['index'])->toBe('none')
        ->and($byKey['created_at']['index_disabled'])->toBeTrue()
        ->and($byKey['created_at']['can_remove'])->toBeTrue()
        ->and($byKey['created_at']['row_readonly'])->toBeTrue()
        ->and($byKey['created_at']['nullable'])->toBeTrue()         // required=false ⇒ nullable
        ->and($byKey['created_at']['row_title'])->toBe('自动维护的创建时间。行内只读，但可整行删除（行末 × 按钮）')
        ->and($byKey['updated_at']['row_title'])->toBe('自动维护的更新时间。行内只读，但可整行删除（行末 × 按钮）');
});

/* ═══════════════════════════════════════════════════════════════════════
 * §6 结构锚点（外迁后的形状契约，只针对新宿主）
 *
 * ⚠ 外迁前整体 skip —— 这就是「红线 9：先补测试、跑绿、再动手改」的落地方式：
 *   本文件在外迁**前**必须全绿（§6 全部 skipped 不算红），外迁**后** §6 必须全转绿。
 *   若外迁只做了一半（类建了但没接上），§6 会红而不是被跳过 —— skip 的条件是「类存不存在」，
 *   不是「我改完了没有」。
 * ═══════════════════════════════════════════════════════════════════════ */

/** 新宿主源码路径。 */
function fshSourcePath(): string
{
    return dirname(__DIR__, 3) . '/src/Designer/FieldShaper.php';
}

/**
 * 去掉注释（可选再去掉字符串字面量）后的源码 —— 结构断言必须先剥注释，
 * 否则类 docblock 里对 `$this` / 旧宿主名的**文字提及**会让「零依赖」断言假红。
 */
function fshCodeOnly(string $php, bool $keepStrings = true): string
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

/** 迁走的 4 个方法名（唯一真源，§6 各条共用）。 */
function fshMemberNames(): array
{
    return ['computeDefaultClass', 'computeDefaultTitle', 'computeSizeClass', 'shape'];
}

/** 某类**自己声明**的方法名（升序）。 */
function fshOwnMethodNames(string $class): array
{
    $rc    = new ReflectionClass($class);
    $names = array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        array_filter(
            $rc->getMethods(),
            static fn (ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === $class,
        ),
    );
    sort($names);

    return $names;
}

it('§6 宿主形状：final + 无构造函数 + 4 个方法恰好齐全且全为 static + 公开面恰 1 个', function () {
    $rc = new ReflectionClass(fshNewHost());

    expect($rc->isFinal())->toBeTrue()
        ->and($rc->getConstructor())->toBeNull()
        // 方法集合恰好等于这一族 —— 少一个（漏搬）或多一个（顺手加料）都红
        ->and(fshOwnMethodNames(fshNewHost()))->toBe(fshMemberNames());

    $notStatic = array_values(array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        array_filter(
            $rc->getMethods(),
            static fn (ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === fshNewHost() && ! $m->isStatic(),
        ),
    ));
    expect($notStatic)->toBe([]);

    $public = array_values(array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        array_filter(
            $rc->getMethods(),
            static fn (ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === fshNewHost() && $m->isPublic(),
        ),
    ));
    sort($public);
    // 只有入口是 public；3 个 compute* 保持 private（搬出去不等于顺手扩大可见面）
    expect($public)->toBe(['shape']);
})->skip(! class_exists(fshNewHost()), '外迁前新宿主尚不存在');

it('§6 零状态锚点：新宿主没有任何属性（连 static 缓存都没有）', function () {
    expect((new ReflectionClass(fshNewHost()))->getProperties())->toHaveCount(0);
})->skip(! class_exists(fshNewHost()), '外迁前新宿主尚不存在');

it('§6 零依赖锚点：纯计算 —— 不碰 $this / 容器 / 文件系统 / Utility，use 清单恰好一个', function () {
    $code = fshCodeOnly((string) file_get_contents(fshSourcePath()));

    foreach (['$this', 'app(', 'Utility', 'Filesystem', 'StorageRegistry', 'DB::', 'new '] as $needle) {
        expect($code)->not->toContain($needle);
    }

    // 唯一允许的外部符号是 `ColumnTypeGroups`（纯常量表）—— 多出任何一条 import 即红
    $head = substr($code, 0, (int) strpos($code, 'final class'));
    preg_match_all('/^use\s+([^\s;]+);/m', $head, $m);

    expect($m[1])->toBe(['Mooeen\Scaffold\Support\ColumnTypeGroups']);
})->skip(! class_exists(fshNewHost()), '外迁前新宿主尚不存在');

it('§6 族内互调只走 self::，三处调用次数被钉死（重复实现 / 忘改接收者都会红）', function () {
    $code = fshCodeOnly((string) file_get_contents(fshSourcePath()));

    // computeSizeClass 在 shape 里被调 2 次（size_class + size_title 各一）
    expect(substr_count($code, 'self::computeSizeClass('))->toBe(2);
    // computeDefaultClass 被调 2 次（shape 1 次 + computeDefaultTitle 内部 1 次）
    expect(substr_count($code, 'self::computeDefaultClass('))->toBe(2);
    expect(substr_count($code, 'self::computeDefaultTitle('))->toBe(1);
})->skip(! class_exists(fshNewHost()), '外迁前新宿主尚不存在');

it('§6 「已不存在」锚点：这 4 个方法不得再出现在 SchemaLoader 上', function () {
    $rc = new ReflectionClass(SchemaLoader::class);

    $stillThere = array_values(array_filter(
        fshMemberNames(),
        static fn (string $name): bool => $rc->hasMethod($name),
    ));

    expect($stillThere)->toBe([]);
})->skip(! class_exists(fshNewHost()), '外迁前新宿主尚不存在');

it('§6 接线锚点：SchemaLoader::loadTableFull 调新宿主，且不留本地转发', function () {
    $code   = fshCodeOnly((string) file_get_contents(dirname(__DIR__, 3) . '/src/Designer/SchemaLoader.php'));
    $loader = new ReflectionClass(SchemaLoader::class);

    // 入口必须显式指向新宿主
    expect($code)->toContain('FieldShaper::shape(')
        // 不留 `$this->shape(` 本地调用 / 不留同名转发方法（`hasMethod` 那条已挡转发，这里挡调用点）
        ->and($code)->not->toContain('$this->shape(');

    // 属性面上也不许再出现本族的痕迹
    $props = array_map(static fn (ReflectionProperty $p): string => $p->getName(), $loader->getProperties());
    foreach (fshMemberNames() as $name) {
        expect($props)->not->toContain($name);
    }
})->skip(! class_exists(fshNewHost()), '外迁前新宿主尚不存在');

it('§6 fshSubject() 已翻到新宿主 ⇒ §1~§5 的断言现在跑在新类上（断言一字未动）', function () {
    expect(fshSubject())->toBe(fshNewHost())
        ->and((new ReflectionMethod(fshSubject(), 'shape'))->isStatic())->toBeTrue();
})->skip(! class_exists(fshNewHost()), '外迁前新宿主尚不存在');
