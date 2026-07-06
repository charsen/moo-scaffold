<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Generator\FreshStorageGenerator;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Yaml\Yaml;

/**
 * Option 1 回归锁(2026-06-23):moo:fresh 应让 _fields.yaml 的 zh-CN 跟随 schema 字段中文名,
 * 同时保留 en 翻译润色。
 *
 * 历史行为是「已存在字段的 en/zh-CN 整体不替换」,导致在 schema 里改字段中文名后
 * _fields.yaml 永远停在旧名。改为:zh-CN 以 schema name 为真源、自动覆盖;en 是润色,保留。
 *
 * formatI18NFields / buildFields 都是 private(且路径只在 start() 赋值),用反射隔离测两半:
 *   formatI18NFields —— 派生层:zh-CN 取 schema name、en 取旧 _fields.yaml 值
 *   buildFields      —— 落盘层:已存在字段同步、删字段减量、append_fields 保留
 */
it('formatI18NFields · 已存在字段 zh-CN 跟随 schema name,en 润色保留;新字段 en 派生列名', function () {
    $gen = new FreshStorageGenerator(new NullOutput, app(Filesystem::class), app(Utility::class));

    $m = new ReflectionMethod($gen, 'formatI18NFields');
    $m->setAccessible(true);

    $allFields = [];
    $fields    = [
        'user_name' => ['name' => '登录名', 'type' => 'varchar'],   // 已存在字段:schema 里中文名被改成「登录名」
        'brand_new' => ['name' => '全新字段', 'type' => 'varchar'], // 新字段:_fields.yaml 里还没有
    ];
    // 旧 _fields.yaml(getLangFields 的返回形态):user_name 的 en 已被人工润色成 Username,zh-CN 还是旧名
    $langFields = [
        'user_name' => ['en' => 'Username', 'zh-CN' => '用户名'],
    ];

    $args = [&$allFields, 'demo_users', $fields, $langFields];
    $m->invokeArgs($gen, $args);

    // 已存在字段:zh-CN 取 schema 新中文名,en 保留人工润色
    expect($allFields['table_fields']['user_name']['zh-CN'])->toBe('登录名');
    expect($allFields['table_fields']['user_name']['en'])->toBe('Username');

    // 新字段:zh-CN 取 schema name,en 从列名 ucwords 派生
    expect($allFields['table_fields']['brand_new']['zh-CN'])->toBe('全新字段');
    expect($allFields['table_fields']['brand_new']['en'])->toBe('Brand New');
});

it('buildFields · 已存在字段同步 zh-CN(en 不丢)+ 删字段减量 + append_fields 保留', function () {
    $gen = new FreshStorageGenerator(new NullOutput, app(Filesystem::class), app(Utility::class));

    $tmp = sys_get_temp_dir() . '/freshsync_' . uniqid() . '/';
    @mkdir($tmp, 0777, true);

    foreach (['db_schema_path' => $tmp, 'db_relative_schema_path' => './fresh/'] as $prop => $val) {
        $p = new ReflectionProperty($gen, $prop);
        $p->setAccessible(true);
        $p->setValue($gen, $val);
    }

    // 现存 _fields.yaml:手工 append_fields + 两个 table_fields(其中 drop_me 即将从 schema 移除)
    $existing = "append_fields:\n"
        . "    custom_field: { en: 'Custom', 'zh-CN': '自定义' }\n"
        . "table_fields:\n"
        . "    user_name: { en: 'Username', 'zh-CN': '用户名' }\n"
        . "    drop_me: { en: 'Drop', 'zh-CN': '待删' }\n";
    file_put_contents($tmp . '_fields.yaml', $existing);

    // 本次 fresh 的计算结果(模拟 formatI18NFields 产物:en 已取旧值保留,zh-CN 已取 schema 新名)
    $allFields = [
        'table_fields' => [
            'user_name' => ['en' => 'Username', 'zh-CN' => '登录名', 'type' => 'varchar'], // 中文名改了,en 没改
            'added'     => ['en' => 'Added', 'zh-CN' => '新增', 'type' => 'varchar'],      // 新字段
        ],
    ];

    $m = new ReflectionMethod($gen, 'buildFields');
    $m->setAccessible(true);
    $m->invoke($gen, $allFields);

    $parsed = Yaml::parse(file_get_contents($tmp . '_fields.yaml'));

    // 已存在字段:zh-CN 同步成 schema 新名,en 润色保留
    expect($parsed['table_fields']['user_name']['zh-CN'])->toBe('登录名');
    expect($parsed['table_fields']['user_name']['en'])->toBe('Username');

    // schema 已删字段 → 从 _fields.yaml 减量
    expect($parsed['table_fields'])->not->toHaveKey('drop_me');

    // 新字段加入
    expect($parsed['table_fields']['added']['zh-CN'])->toBe('新增');

    // append_fields(手工字段)原样保留
    expect($parsed['append_fields']['custom_field']['en'])->toBe('Custom');
    expect($parsed['append_fields']['custom_field']['zh-CN'])->toBe('自定义');

    (new Filesystem)->deleteDirectory(rtrim($tmp, '/'));
});

it('formatI18NFields · zh-CN 为空(name 空串/缺省)→ 用 en 兜底,避免空内容', function () {
    $gen = new FreshStorageGenerator(new NullOutput, app(Filesystem::class), app(Utility::class));

    $fmt = new ReflectionMethod($gen, 'formatI18NFields');
    $fmt->setAccessible(true);

    $allFields = [];
    $fields    = [
        'remark'     => ['name' => '', 'type' => 'varchar'], // 空串 name + _fields.yaml 有润色 en
        'sort_order' => ['name' => '', 'type' => 'int'],     // 空串 name + 无 en → 列名派生
        'creator_id' => ['type' => 'bigint'],                // name 缺省(unset)
    ];
    // remark 在 _fields.yaml 里 en 已润色;zh-CN 即使有旧值也不参与(Option 1:zh-CN 只认 schema)
    $langFields = ['remark' => ['en' => 'Remark Note', 'zh-CN' => '备注']];

    $args = [&$allFields, 'demo', $fields, $langFields];
    $fmt->invokeArgs($gen, $args);

    // name 空 + 有润色 en → zh-CN 用该 en(不是旧 _fields.yaml 的 zh-CN '备注')
    expect($allFields['table_fields']['remark']['zh-CN'])->toBe('Remark Note');
    expect($allFields['table_fields']['remark']['en'])->toBe('Remark Note');

    // name 空 + 无 en → zh-CN 用列名派生的 en(ucwords)
    expect($allFields['table_fields']['sort_order']['zh-CN'])->toBe('Sort Order');
    expect($allFields['table_fields']['sort_order']['en'])->toBe('Sort Order');

    // name 缺省 → 同样 en 兜底
    expect($allFields['table_fields']['creator_id']['zh-CN'])->toBe('Creator Id');
});

it('formatDefaultFields · 默认字段支持局部覆盖,保留 schema 对 name/type 的显式定义', function () {
    $gen = new FreshStorageGenerator(new NullOutput, app(Filesystem::class), app(Utility::class));

    $fmt = new ReflectionMethod($gen, 'formatDefaultFields');
    $fmt->setAccessible(true);

    $fields = [
        'id'         => ['type' => 'char', 'size' => 36],
        'created_at' => ['type' => 'int'],
        'updated_at' => [],
        'deleted_at' => ['name' => '删除时间'],
        'custom'     => ['name' => '自定义字段', 'type' => 'varchar'],
    ];

    $args   = [&$fields];
    $result = $fmt->invokeArgs($gen, $args);

    expect($result['id'])->toMatchArray([
        'name' => 'ID',
        'type' => 'char',
        'size' => 36,
    ]);
    expect($result['created_at'])->toMatchArray([
        'name' => '创建于',
        'type' => 'int',
    ]);
    expect($result['updated_at'])->toMatchArray([
        'name' => '更新于',
        'type' => 'timestamp',
    ]);
    expect($result['deleted_at'])->toMatchArray([
        'required' => false,
        'name'     => '删除时间',
        'type'     => 'timestamp',
        'default'  => null,
    ]);
    expect($result['custom'])->toBe([
        'name' => '自定义字段',
        'type' => 'varchar',
    ]);
});

it('reportNameConflicts · 同名列不同表中文名不一致 → warn 列出每张表的叫法(可定位去改)', function () {
    $buffer = new BufferedOutput;
    $gen    = new FreshStorageGenerator($buffer, app(Filesystem::class), app(Utility::class));

    $fmt = new ReflectionMethod($gen, 'formatI18NFields');
    $fmt->setAccessible(true);

    // 同一列 status,两张表给了不同中文名(订单状态 / 上架状态)
    $allFields = [];
    $argsA     = [&$allFields, 'a_orders', ['status' => ['name' => '订单状态', 'type' => 'tinyint']], []];
    $fmt->invokeArgs($gen, $argsA);
    $argsB = [&$allFields, 'b_products', ['status' => ['name' => '上架状态', 'type' => 'tinyint']], []];
    $fmt->invokeArgs($gen, $argsB);

    $report = new ReflectionMethod($gen, 'reportNameConflicts');
    $report->setAccessible(true);
    $report->invoke($gen);

    // 每种叫法一行,后面跟用它的表名 —— 能直接定位去哪张表改
    $out = $buffer->fetch();
    expect($out)->toContain('1 个同名字段');
    expect($out)->toContain('status (2 种)');
    expect($out)->toContain('订单状态: a_orders');
    expect($out)->toContain('上架状态: b_products');
});

it('reportNameConflicts · 同叫法归并 → 同一中文名一行列出所有用它的表,主流叫法在前', function () {
    $buffer = new BufferedOutput;
    $gen    = new FreshStorageGenerator($buffer, app(Filesystem::class), app(Utility::class));

    $fmt = new ReflectionMethod($gen, 'formatI18NFields');
    $fmt->setAccessible(true);

    // status 在 3 张表:t1/t2 都叫「状态」,t3 叫「订单状态」→ 2 种叫法,「状态」归并出 t1,t2
    $allFields = [];
    foreach ([['t1', '状态'], ['t2', '状态'], ['t3', '订单状态']] as [$table, $zh]) {
        $args = [&$allFields, $table, ['status' => ['name' => $zh, 'type' => 'tinyint']], []];
        $fmt->invokeArgs($gen, $args);
    }

    $report = new ReflectionMethod($gen, 'reportNameConflicts');
    $report->setAccessible(true);
    $report->invoke($gen);

    $out = $buffer->fetch();
    expect($out)->toContain('status (2 种)');
    // 同叫法归并、列全部表;主流叫法(2 表)排在前
    expect($out)->toContain('状态: t1, t2');
    expect($out)->toContain('订单状态: t3');
});

it('reportNameConflicts · 同名列中文名一致 → 不算冲突、无 warn 输出', function () {
    $buffer = new BufferedOutput;
    $gen    = new FreshStorageGenerator($buffer, app(Filesystem::class), app(Utility::class));

    $fmt = new ReflectionMethod($gen, 'formatI18NFields');
    $fmt->setAccessible(true);

    $allFields = [];
    $argsA     = [&$allFields, 'a_orders', ['kind' => ['name' => '类型', 'type' => 'tinyint']], []];
    $fmt->invokeArgs($gen, $argsA);
    $argsB = [&$allFields, 'b_products', ['kind' => ['name' => '类型', 'type' => 'tinyint']], []];
    $fmt->invokeArgs($gen, $argsB);

    $report = new ReflectionMethod($gen, 'reportNameConflicts');
    $report->setAccessible(true);
    $report->invoke($gen);

    expect(trim($buffer->fetch()))->toBe('');
});
