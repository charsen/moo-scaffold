<?php declare(strict_types=1);

use Mooeen\Scaffold\Http\Controllers\ApiController;
use Mooeen\Scaffold\Support\ApiParameterFormatter;

/**
 * `Support\ApiParameterFormatter`「参数形状归一」族（外迁前是 `ApiController` 的 Group C）的**行为钉尸**测试。
 *
 * **为什么先有测试、后有外迁**：这一族 15 个方法 / 476 行（含格式说明 docblock），此前**只被 1 条**用例覆盖
 * （`ApiControllerTest` 里的 `isRuleParameterSendable/isScalarArrayElement`）。按 `TUNING-PLAN.md` 红线 9
 * 「测试钉现状先行」，本文件**先**钉住现状（2026-09-20 外迁前即 17 passed），**再**让这一族搬到
 * `Support\ApiParameterFormatter`（`final` + 全静态）。
 *
 * **本文件是「同一批断言跨两个宿主」的**：外迁前 `apfSubject()` 返回 `ApiController::class`（方法私有 ⇒
 * 走反射），外迁后返回 `ApiParameterFormatter::class` —— **15 条行为断言一个字都没动**，跟着宿主变的只有
 * `apfSubject()` 与 `apfCallEntry()` 两个助手。这就是「行为不变」的机器证明：换掉宿主，断言原样跑绿。
 *
 * ⚠ 元数据（`$metadata`）与「最新 ID」解算器**由调用方提供** ⇒ 本文件一律用**手搭的 `apfShapeMetadata()`**
 * （刻意用不在 fixture 里的键，断言与宿主数据无关）；例外是两个入口 `formatRules` / `formatYamlParams`，
 * 它们要调用方那一份真 metadata，本文件用 `apfMetadata()` 取**控制器同一份** ——
 * `ApiController::getParameterMetadata()` **刻意留在控制器**、没随族外迁（它持有 memo 与 `Utility` 依赖），
 * 故这里仍指控制器（见文件末的「刻意留下」结构锚点）。
 *
 * 文件分两段：**行为**段（前 17 条，跨宿主不变）+ **结构**段（末 6 条，外迁后的形状契约 —— 只针对新宿主）。
 */

/** 宿主解析点。外迁后指向新宿主（原来是 `ApiController::class`）。 */
function apfSubject(): string
{
    return ApiParameterFormatter::class;
}

/**
 * 入口方法要显式收的 metadata：取**控制器那一份**（`getParameterMetadata()` 刻意留在控制器），
 * 与控制器内部取到的逐字相同。
 */
function apfMetadata(): array
{
    $m = new ReflectionMethod(ApiController::class, 'getParameterMetadata');
    $m->setAccessible(true);

    return $m->invoke(app(ApiController::class));
}

/** 「默认最新 ID」的解算器；给可控返回值，不碰 DB。 */
function apfLatestId(): callable
{
    return static fn (array $rules): int|string|null => in_array('exists:App\Models\Post,id', $rules, true) ? 42 : null;
}

/** 统一调用：外迁后 15 个方法全是 static ⇒ 一律 `invoke(null, ...)`。 */
function apfCall(string $method, mixed ...$args): mixed
{
    $r = new ReflectionMethod(apfSubject(), $method);
    $r->setAccessible(true);

    return $r->invoke(null, ...$args);
}

/** 带引用参数的调用（`invokeArgs` 需要引用数组）。 */
function apfCallRef(string $method, array $args): mixed
{
    $r = new ReflectionMethod(apfSubject(), $method);
    $r->setAccessible(true);

    return $r->invokeArgs(null, $args);
}

/**
 * 入口方法：外迁后要在原参后面补上「调用方提供」的东西。
 * `formatRules` 两样都要（metadata + 解算器）；`formatYamlParams` **只**要 metadata（它不解算最新 ID）。
 */
function apfCallEntry(string $method, array $args): mixed
{
    $tail = $method === 'formatRules' ? [apfMetadata(), apfLatestId()] : [apfMetadata()];

    return apfCall($method, ...array_merge($args, $tail));
}

/** 手搭元数据：每个键都刻意**不在** fixture 里。 */
function apfShapeMetadata(): array
{
    return [
        'enums' => [
            'status'     => [[1, '正常', 'normal'], [2, '停用', 'disabled']],
            'ods.status' => [[7, '七', 'seven'], [8, '八', 'eight']],
        ],
        'fields' => [
            'title'     => ['zh-CN' => '标题', 'type' => 'varchar'],
            'ods.title' => ['zh-CN' => '渠道标题', 'type' => 'text'],
            'amount'    => ['zh-CN' => '金额', 'type' => 'int'],
        ],
        'lang_fields' => [
            'nickname' => ['zh-CN' => '昵称'],
        ],
    ];
}

// ─── 纯映射：点号 → 方括号显示键 ─────────────────────────────────────────

it('formatParameterDisplayKey：点号转方括号，`*` 归一成 0，无点号原样返回', function () {
    expect(apfCall('formatParameterDisplayKey', 'page'))->toBe('page')
        ->and(apfCall('formatParameterDisplayKey', 'ids.0'))->toBe('ids[0]')
        ->and(apfCall('formatParameterDisplayKey', 'field.*'))->toBe('field[0]')
        ->and(apfCall('formatParameterDisplayKey', 'a.b.c'))->toBe('a[b][c]')
        ->and(apfCall('formatParameterDisplayKey', 'market.title'))->toBe('market[title]');
});

it('resolveParameterFieldKey：命中即原样，否则剥 `*`/数字段后取末段', function () {
    $md = apfShapeMetadata();

    expect(apfCall('resolveParameterFieldKey', 'title', $md))->toBe('title')
        // 点号键整体命中 fields ⇒ 原样，**不是**取末段
        ->and(apfCall('resolveParameterFieldKey', 'ods.title', $md))->toBe('ods.title')
        // 未命中 ⇒ 剥 `*` / 纯数字段后取末段
        ->and(apfCall('resolveParameterFieldKey', 'ods.*.title', $md))->toBe('title')
        ->and(apfCall('resolveParameterFieldKey', 'ods.0.title', $md))->toBe('title')
        // 全被剥光就回落原键
        ->and(apfCall('resolveParameterFieldKey', 'unknown.deep.key', $md))->toBe('key');
});

it('resolveParameterLabel：lang_fields → fields[原键] → … → 显示键', function () {
    $md = apfShapeMetadata();

    // lang_fields 命中优先（`nickname` 只在 lang_fields 里）
    expect(apfCall('resolveParameterLabel', 'nickname', $md))->toBe('昵称')
        // fields 直命中
        ->and(apfCall('resolveParameterLabel', 'amount', $md))->toBe('金额')
        // 点号键整体命中 fields
        ->and(apfCall('resolveParameterLabel', 'ods.title', $md))->toBe('渠道标题')
        // 都没命中 ⇒ 回落「显示键」，不是空串
        ->and(apfCall('resolveParameterLabel', 'nothing.here', $md))->toBe('nothing[here]');
});

// ─── 谓词 ────────────────────────────────────────────────────────────────

it('isRuleParameterRequired：`required` 为真，`sometimes`/`nullable`/`required_*` 为假，其余为真', function () {
    $m = static fn (array $rules): bool => apfCall('isRuleParameterRequired', $rules);

    expect($m(['required', 'string']))->toBeTrue()
        ->and($m(['sometimes', 'string']))->toBeFalse()
        ->and($m(['nullable', 'string']))->toBeFalse()
        // 条件必填（required_if / required_with…）**不算**必填
        ->and($m(['required_if:type,1', 'string']))->toBeFalse()
        ->and($m(['required_with:a,b', 'string']))->toBeFalse()
        // 没有任何标记 ⇒ 默认真
        ->and($m(['string', 'max:10']))->toBeTrue();
});

it('isScalarArrayElement：`x.*` 且无更深子键才算出标量元素', function () {
    $m = static fn (string $key, array $keys): bool => apfCall('isScalarArrayElement', $key, $keys);

    expect($m('medias.*', ['medias', 'medias.*']))->toBeTrue()
        // 有 medias.*.file ⇒ 对象元素
        ->and($m('medias.*', ['medias', 'medias.*', 'medias.*.file']))->toBeFalse()
        // 不以 `.*` 结尾 ⇒ 恒假
        ->and($m('medias', ['medias', 'medias.*']))->toBeFalse();
});

it('isRuleParameterSendable：`.*` 自身不可发；数组只在「全是标量元素」时可单发', function () {
    $m = static fn (string $key, array $rules, array $keys): bool => apfCall('isRuleParameterSendable', $key, $rules, $keys);

    expect($m('medias.*', ['string'], ['medias', 'medias.*']))->toBeFalse()
        ->and($m('title', ['string'], ['title']))->toBeTrue()
        // 数组-对象 ⇒ 父不可发
        ->and($m('medias', ['array'], ['medias', 'medias.*', 'medias.*.file']))->toBeFalse()
        // 数组-标量 ⇒ 父可单发
        ->and($m('ids', ['array'], ['ids', 'ids.*']))->toBeTrue()
        // 数组-关联 ⇒ 父不可发
        ->and($m('map', ['array'], ['map', 'map.k']))->toBeFalse();
});

it('resolveRuleParameterType：array/integer/numeric/boolean/date 优先，其次回落元数据，都没有给 null', function () {
    $md = apfShapeMetadata();
    $m  = static fn (string $key, array $rules) => apfCall('resolveRuleParameterType', $key, $rules, $md);

    expect($m('x', ['array']))->toBe('array')
        ->and($m('x', ['integer']))->toBe('int')
        ->and($m('x', ['numeric']))->toBe('int')
        ->and($m('x', ['boolean']))->toBe('boolean')
        ->and($m('x', ['date']))->toBe('date')
        // 规则里没有类型 ⇒ 读元数据 fields[$key]['type']
        ->and($m('amount', ['string']))->toBe('int')
        // 元数据也没有 ⇒ null（不是空串）
        ->and($m('zzz_unknown', ['string']))->toBeNull();
});

// ─── 枚举 / 类型富化（带副作用，走引用参数） ─────────────────────────────

it('applyParameterFieldMeta：命中枚举 ⇒ radio + options(第 3 列) + value 取枚举值列其一', function () {
    $md = apfShapeMetadata();

    $p = ['value' => '', 'type' => null];
    apfCallRef('applyParameterFieldMeta', [&$p, 'status', $md]);

    expect($p['type'])->toBe('radio')
        // options = map(枚举值 => 第 3 列)；第 2 列是给人看的 label，**不进** options
        ->and($p['options'])->toBe([1 => 'normal', 2 => 'disabled'])
        // value 是 `Arr::random(枚举值列)` ⇒ 只能断「落在集合内」
        ->and($p['value'])->toBeIn([1, 2]);

    // 点号键整体命中枚举（与 resolveParameterFieldKey 的归一配合）
    $q = ['value' => '', 'type' => null];
    apfCallRef('applyParameterFieldMeta', [&$q, 'ods.status', $md]);
    expect($q['type'])->toBe('radio')
        ->and($q['options'])->toBe([7 => 'seven', 8 => 'eight'])
        ->and($q['value'])->toBeIn([7, 8]);
});

it('applyParameterFieldMeta：非枚举 ⇒ 只在 type 为空时用元数据补，已有 type 不被覆盖', function () {
    $md = apfShapeMetadata();

    $p = ['value' => '', 'type' => null];
    apfCallRef('applyParameterFieldMeta', [&$p, 'amount', $md]);
    expect($p['type'])->toBe('int')->and($p['value'])->toBe('');

    // 已有 type ⇒ 元数据不覆盖（`??` 语义，不是赋值）
    $q = ['value' => '', 'type' => 'preset'];
    apfCallRef('applyParameterFieldMeta', [&$q, 'amount', $md]);
    expect($q['type'])->toBe('preset');
});

// ─── 描述拼接 ────────────────────────────────────────────────────────────

it('appendParameterHint：空描述直接取提示，已含提示不重复，否则用「；」追加', function () {
    $m = static fn (string $desc): string => apfCall('appendParameterHint', $desc, '提示');

    expect($m(''))->toBe('提示')
        // 纯空白先 trim 成空 ⇒ 也走「直接取提示」这一支
        ->and($m('   '))->toBe('提示')
        ->and($m('已有提示'))->toBe('已有提示')
        ->and($m('原描述'))->toBe('原描述；提示');
});

it('buildRuleParameterDescription：逐条规则翻成人话，数组与标量的 max/min 措辞不同', function () {
    $m = static fn (string $key, array $rules, array $keys, bool $sendable): string => apfCall('buildRuleParameterDescription', $key, $rules, $keys, $sendable);

    expect($m('medias.*', ['array'], ['medias', 'medias.*'], true))->toBe('数组元素对象')
        ->and($m('medias', ['array'], ['medias', 'medias.*'], true))->toBe('数组')
        ->and($m('a', ['required_with:b,c'], ['a'], true))->toBe('传 b、c 时必填')
        ->and($m('a', ['required_without:d'], ['a'], true))->toBe('缺少 d 时必填')
        // max/min 按「是不是数组」分家 —— 这两条互为对照，断成同一个值就没鉴别力了
        ->and($m('a', ['max:10'], ['a'], true))->toBe('最大长度 10')
        ->and($m('a', ['array', 'max:3'], ['a'], true))->toBe('数组；最多 3 项')
        ->and($m('a', ['min:2'], ['a'], true))->toBe('最小长度 2')
        ->and($m('a', ['array', 'min:2'], ['a'], true))->toBe('数组；至少 2 项')
        ->and($m('a', ['exists:App\\Models\\User,id'], ['a'], true))->toBe('需为有效 ID')
        // 不可单发 + 有子键 ⇒ 追加引导句
        ->and($m('medias', ['array'], ['medias', 'medias.*'], false))->toBe('数组；结构说明，调试时请填写子字段')
        // 不可单发但**没有**子键 ⇒ 不追加
        ->and($m('medias', ['array'], ['medias'], false))->toBe('数组')
        // 重复规则去重
        ->and($m('a', ['max:10', 'max:10'], ['a'], true))->toBe('最大长度 10');
});

// ─── Faker 占位值 ────────────────────────────────────────────────────────

it('formatToFaker：按字段名/类型填占位值；已填值、sendable=false、_method 一律跳过', function () {
    $faker = Faker\Factory::create('zh_CN');
    $row   = static fn (mixed $value, ?string $type, bool $sendable = true): array => ['value' => $value, 'type' => $type, 'sendable' => $sendable];

    $out = apfCall('formatToFaker', $faker, [
        'market_cart_ids' => $row('', null),
        'media_file'      => $row('', null),
        'id_card_number'  => $row('', null),
        'int_col'         => $row('', 'int'),
        'bool_col'        => $row('', 'boolean'),
        'kept'            => $row('X', 'varchar'),
        'not_sendable'    => $row('', 'varchar', false),
        '_method'         => $row('', null),
        'title'           => $row('', 'varchar'),
        'body'            => $row('', 'text'),
    ]);

    expect($out['media_file']['value'])->toBe('temp/demo/example.jpg')
        // `id_card_number` **故意留空**（填了反而误导）
        ->and($out['id_card_number']['value'])->toBe('')
        // 类型命中的确定性值
        ->and($out['int_col']['value'])->toBe(1)
        // boolean 除值外还补一句取值说明
        ->and($out['bool_col']['value'])->toBeIn([0, 1])
        ->and($out['bool_col']['desc'])->toBe('{1: true, 0: false}')
        // 三个跳过分支都保持原样
        ->and($out['kept']['value'])->toBe('X')
        ->and($out['not_sendable']['value'])->toBe('')
        ->and($out['_method']['value'])->toBe('')
        // 随机型只断形状，不断具体值
        ->and($out['market_cart_ids']['value'])->toMatch('/^\d,\d$/')
        ->and($out['title']['value'])->toBeString()->not->toBe('')
        ->and($out['body']['value'])->toBeString()->not->toBe('');
});

// ─── 合并 ────────────────────────────────────────────────────────────────

it('mergeDebugParams：空覆盖原样返回；覆盖后仅补目标缺失/为空的元信息', function () {
    $base = [
        'a' => ['value' => 'bv', 'desc' => 'bd', 'name' => 'bn', 'type' => 'int', 'require' => true, 'sendable' => true],
        'b' => ['value' => '', 'desc' => '', 'name' => '', 'type' => null],
    ];
    $override = [
        'a' => ['value' => 'ov', 'desc' => '', 'name' => '', 'sendable' => false],
        'b' => ['value' => '', 'desc' => 'od', 'name' => 'on', 'type' => 'text'],
        'c' => ['value' => 'cv'],
    ];

    // 空覆盖 ⇒ 返回**同一个数组**（不是「等值的新数组」）
    expect(apfCall('mergeDebugParams', $base, []))->toBe($base);

    $merged = apfCall('mergeDebugParams', $base, $override);

    expect($merged['a']['value'])->toBe('ov')
        // desc/name：覆盖方是空串 ⇒ 从基准继承
        ->and($merged['a']['desc'])->toBe('bd')
        ->and($merged['a']['name'])->toBe('bn')
        // 覆盖方没有的键 ⇒ 保留基准值
        ->and($merged['a']['type'])->toBe('int')
        ->and($merged['a']['require'])->toBe(true)
        // sendable 在覆盖里明确给 false ⇒ **不继承**（继承只管「缺失」）
        ->and($merged['a']['sendable'])->toBe(false)
        // b：这次覆盖方有值、基准为空 ⇒ 用覆盖方的
        ->and($merged['b']['desc'])->toBe('od')
        ->and($merged['b']['name'])->toBe('on')
        ->and($merged['b']['type'])->toBe('text')
        // 基准里没有的键原样带进来
        ->and($merged['c'])->toBe(['value' => 'cv']);
});

// ─── 入口 1：YAML 手写参数 ───────────────────────────────────────────────

it('formatYamlParams：`_method` 走兼容分支，四种数组形态各自解析，非数组项跳过', function () {
    $out = apfCallEntry('formatYamlParams', [[
        '_method'   => ['put'],
        'opt'       => [false],
        'req'       => [],
        'named'     => ['名字', '默认值'],
        'full'      => [false, '说明名', 'v', '描述'],
        'not_array' => 'scalar',
    ]]);

    // `_method`：value 大写、name 刻意空、desc 固定「兼容处理」，且**不带** display_key/send_key/sendable
    expect($out['_method'])->toBe(['require' => true, 'name' => '', 'value' => 'PUT', 'desc' => '兼容处理'])
        // `[false]` ⇒ 非必填
        ->and($out['opt']['require'])->toBeFalse()
        ->and($out['opt']['name'])->toBe('opt')
        ->and($out['opt']['value'])->toBe('')
        // `[]` ⇒ 必填（`$attr[0] ?? true`），名字回落显示键
        ->and($out['req']['require'])->toBeTrue()
        ->and($out['req']['name'])->toBe('req')
        // `[Name, value]` ⇒ 第一参是字符串就拿来当名字
        ->and($out['named'])->toMatchArray(['require' => true, 'name' => '名字', 'value' => '默认值'])
        // `[false, Name, value, desc]` ⇒ 四参齐全
        ->and($out['full'])->toMatchArray(['require' => false, 'name' => '说明名', 'value' => 'v', 'desc' => '描述'])
        // 每项都带 display_key / send_key / sendable
        ->and($out['opt']['display_key'])->toBe('opt')
        ->and($out['opt']['send_key'])->toBe('opt')
        ->and($out['opt']['sendable'])->toBeTrue()
        // 非数组项被跳过（不报错、也不留空条目）
        ->and(array_key_exists('not_array', $out))->toBeFalse();
});

// ─── 入口 2：FormRequest 规则 ────────────────────────────────────────────

it('formatRules：四个「约定字段」有硬编码覆盖，数组-标量的 `.*` 被父吸收', function () {
    $out = apfCallEntry('formatRules', ['destroy', [
        'page'           => ['integer'],
        'page_limit'     => ['integer'],
        'ids'            => ['required', 'array'],
        'force'          => ['boolean'],
        'zzz_title'      => ['required', 'string', 'max:10'],
        'zzz_cart_ids'   => ['required', 'array', 'min:1'],
        'zzz_cart_ids.*' => ['numeric'],
    ]]);

    // `page` / `page_limit` 的 value 被硬编码成示例值
    expect($out['page']['value'])->toBe(1)
        ->and($out['page_limit']['value'])->toBe(10)
        // `ids` 给了示例值 + 填写说明
        ->and($out['ids']['value'])->toBe('2,3')
        ->and($out['ids']['desc'])->toBe('使用半角逗号（,）分隔为数组')
        // `force` 在 destroy 下改写成「强制删除」、非必填、value=1、带取值说明
        ->and($out['force'])->toMatchArray([
            'require' => false, 'name' => '强制删除', 'value' => 1, 'desc' => '{0: false, 1: true}',
        ])
        // 普通字段：require 由规则决定；约束进 rules，**不进** desc
        ->and($out['zzz_title']['require'])->toBeTrue()
        ->and($out['zzz_title']['rules'])->toBe('最大长度 10')
        ->and($out['zzz_title']['desc'])->toBe('')
        // 数组-标量：父可单发 + 填提示，而 `.*` **不出现在结果里**（被父吸收）
        ->and($out['zzz_cart_ids']['sendable'])->toBeTrue()
        ->and($out['zzz_cart_ids']['desc'])->toBe('数组，逗号分隔或 JSON')
        ->and(array_key_exists('zzz_cart_ids.*', $out))->toBeFalse()
        // display_key 恒有
        ->and($out['zzz_title']['display_key'])->toBe('zzz_title')
        // send_key = display_key（能发时）
        ->and($out['zzz_title']['send_key'])->toBe('zzz_title');
});

it('formatRules：非 destroy 动作的 `force` 不叫「强制删除」', function () {
    $out = apfCallEntry('formatRules', ['update', ['force' => ['boolean']]]);

    expect($out['force']['name'])->toBe('强制')
        ->and($out['force']['require'])->toBeFalse()
        ->and($out['force']['value'])->toBe(1);
});

it('formatRules：数组-对象的父不可单发，填值退化成 `{}`，send_key 清空', function () {
    $out = apfCallEntry('formatRules', ['store', [
        'zzz_medias'        => ['required', 'array'],
        'zzz_medias.*'      => ['required', 'array'],
        'zzz_medias.*.file' => ['required', 'string'],
    ]]);

    expect($out['zzz_medias']['sendable'])->toBeFalse()
        // 不可单发的普通键拿 `[]`，不可单发的 `x.*` 拿 `{}` —— 两条互为对照
        ->and($out['zzz_medias']['value'])->toBe('[]')
        ->and($out['zzz_medias']['send_key'])->toBe('')
        ->and($out['zzz_medias.*']['value'])->toBe('{}')
        ->and($out['zzz_medias.*']['sendable'])->toBeFalse()
        // 叶子可单发
        ->and($out['zzz_medias.*.file']['sendable'])->toBeTrue()
        ->and($out['zzz_medias.*.file']['display_key'])->toBe('zzz_medias[0][file]');
});

it('formatRules：把「最新 ID」解算器接线到 `exists:…,id` 上（外迁后才注入得了，故本条只针对新宿主）', function () {
    $out = apfCallEntry('formatRules', ['update', [
        'zzz_post_id' => ['required', 'integer', 'exists:App\Models\Post,id'],
        'zzz_other'   => ['required', 'integer'],
    ]]);

    // 解算器命中 ⇒ value 被覆写成解算结果（字符串化），并追加「默认最新 ID」
    expect($out['zzz_post_id']['value'])->toBe('42')
        ->and($out['zzz_post_id']['desc'])->toBe('默认最新 ID')
        // 没命中 exists 的字段不受影响 —— 断言「解算器只对被调用到的键生效」
        ->and($out['zzz_other']['value'])->toBe('')
        ->and($out['zzz_other']['desc'])->toBe('');
});

// ─── 结构锚点（外迁后的形状契约，只针对新宿主） ──────────────────────────

/** 该族 15 个方法名（外迁后应全部只存在于新宿主上）。 */
function apfMemberNames(): array
{
    return [
        'appendParameterHint', 'applyParameterFieldMeta', 'buildRuleParameterDescription',
        'formatParameterDisplayKey', 'formatRules', 'formatToFaker', 'formatYamlParams',
        'inheritMissingDebugParamMeta', 'isRuleParameterRequired', 'isRuleParameterSendable',
        'isScalarArrayElement', 'mergeDebugParams', 'resolveParameterFieldKey',
        'resolveParameterLabel', 'resolveRuleParameterType',
    ];
}

/** 宿主自身声明的方法名（升序）。 */
function apfOwnMethodNames(string $class): array
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

/**
 * 剥掉注释与字符串字面量后的源码。
 *
 * 用途：区分「注释里**提到** Utility」与「代码里**用了** Utility」—— 本文件的类注释特意写了
 * 「别把 `Utility` 注进来」，不剥注释的话下面那条零依赖锚点会误报。
 */
function apfCodeOnly(string $class): string
{
    $src = file_get_contents((new ReflectionClass($class))->getFileName());
    $out = '';

    foreach (token_get_all($src) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING], true)) {
                continue;
            }
            $out .= $token[1];

            continue;
        }

        $out .= $token;
    }

    return $out;
}

it('宿主形状：final + 无构造函数 + 15 个方法恰好齐全且全为 static + 公开面恰 4 个', function () {
    $rc = new ReflectionClass(ApiParameterFormatter::class);

    expect($rc->isFinal())->toBeTrue()
        ->and($rc->getConstructor())->toBeNull()
        // 方法集合恰好等于这一族 —— 少一个（漏搬）或多一个（顺手加料）都红
        ->and(apfOwnMethodNames(ApiParameterFormatter::class))->toBe(apfMemberNames());

    $notStatic = array_values(array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        array_filter(
            $rc->getMethods(),
            static fn (ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === ApiParameterFormatter::class
                && ! $m->isStatic(),
        ),
    ));

    expect($notStatic)->toBe([]);

    $public = array_values(array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        array_filter(
            $rc->getMethods(),
            static fn (ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === ApiParameterFormatter::class
                && $m->isPublic(),
        ),
    ));
    sort($public);

    expect($public)->toBe(['formatRules', 'formatToFaker', 'formatYamlParams', 'mergeDebugParams']);
});

it('零状态锚点：新宿主没有任何属性 —— 尤其不能把 metadata / latestId 缓存成 static（那会跨请求残留）', function () {
    expect((new ReflectionClass(ApiParameterFormatter::class))->getProperties())->toHaveCount(0);
});

it('零依赖锚点：新宿主是纯计算 —— 不碰 $this / 容器 / 文件系统 / Utility / StorageRegistry', function () {
    $code = apfCodeOnly(ApiParameterFormatter::class);

    foreach (['$this', 'app(', 'StorageRegistry', 'Utility', 'Filesystem', 'new '] as $needle) {
        expect($code)->not->toContain($needle);
    }

    // 唯一允许的外部符号是 `Arr`（无状态 façade）—— 多出任何一条 import（比如把 Utility 注进来）即红
    preg_match_all('/^use\s+([^;]+);/m', file_get_contents((new ReflectionClass(ApiParameterFormatter::class))->getFileName()), $m);

    expect($m[1])->toBe(['Illuminate\Support\Arr']);
});

it('「已不存在」锚点：这 15 个方法不得再出现在 ApiController 上', function () {
    $rc = new ReflectionClass(ApiController::class);

    $stillThere = array_values(array_filter(
        apfMemberNames(),
        static fn (string $name): bool => $rc->hasMethod($name),
    ));

    expect($stillThere)->toBe([]);
});

it('「刻意留下」锚点：metadata memo 与「最新 ID」解算仍在 ApiController，且都是实例态（非 static）', function () {
    $rc = new ReflectionClass(ApiController::class);

    expect($rc->hasMethod('getParameterMetadata'))->toBeTrue()
        ->and($rc->hasMethod('resolveLatestModelIdFromRules'))->toBeTrue()
        ->and($rc->hasMethod('resolveExistsModelClass'))->toBeTrue()
        // memo 必须是**实例**属性：做成 static 就会跨请求残留（StorageRegistryTest 挡的是同一类问题）
        ->and($rc->getProperty('parameterMetadata')->isStatic())->toBeFalse()
        ->and($rc->getProperty('latestModelIds')->isStatic())->toBeFalse();
});

it('接线锚点：ApiController 的 4 个入口都调新宿主（不再留有本地实现）', function () {
    $code = apfCodeOnly(ApiController::class);

    foreach (['formatRules', 'formatYamlParams', 'mergeDebugParams', 'formatToFaker'] as $entry) {
        expect($code)->toContain("ApiParameterFormatter::{$entry}(");
    }

    // 归一的入口只该从这里走 —— 出现 `$this->formatRules(` 之类本地调用即回归
    expect($code)->not->toContain('$this->formatRules(')
        ->and($code)->not->toContain('$this->formatYamlParams(')
        ->and($code)->not->toContain('$this->mergeDebugParams(')
        ->and($code)->not->toContain('$this->formatToFaker(');
});
