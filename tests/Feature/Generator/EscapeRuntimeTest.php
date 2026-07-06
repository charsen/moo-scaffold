<?php declare(strict_types=1);

use Illuminate\Console\View\Components\Factory;
use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Generator\CreateApiGenerator;
use Mooeen\Scaffold\Generator\CreateModelGenerator;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Output\NullOutput;

/**
 * plan-40 §二 escape 真机投毒 runtime — 4 P0 payload 喂 generator 看输出
 *
 * 跟 EscapeCoverageTest(源码 regex 锁定)互补:这里 instantiate generator,
 * 喂攻击 payload,assert 生成的 PHP / YAML 字符串里 payload **已被 escape**
 * (出现 `\'` / `''` 等 escape 形态),不再是 raw 字符串能逃逸字面量分隔符。
 *
 * 4 个 payload(40-addendum-escape-coverage-audit.md §六 P0 测试清单):
 *   F9a · format: "100');system('id');//"
 *   F9b · default: "x');evil();//"
 *   F6  · controller displayName / class 含单引号
 *   F7  · menu key / name 含单引号
 */

// Generator base ctor 需要 Command|Factory + Filesystem + Utility,
// 测试用 anonymous Factory(Console\View\Components\Factory)— escape helper / getFloatAttribute /
// getModelAttributes 都不调 $this->command,所以 mock 不需要实质行为
function makeCommandStub(): Factory
{
    return Mockery::mock(Factory::class);
}

function makeModelGen(): CreateModelGenerator
{
    return new CreateModelGenerator(makeCommandStub(), app(Filesystem::class), app(Utility::class));
}

function makeApiGen(): CreateApiGenerator
{
    return new CreateApiGenerator(makeCommandStub(), app(Filesystem::class), app(Utility::class));
}

/* ---------------------------------------------------------------------------
 * F9a · format 字段 RCE — 攻击 bcmul/bcdiv divisor
 * ------------------------------------------------------------------------ */

test('F9a runtime · format 字段含 escape 字符注入 bcmul/bcdiv 后,divisor 必须被 escapePhpString', function () {
    $gen = makeModelGen();
    $ref = new ReflectionMethod($gen, 'getFloatAttribute');
    $ref->setAccessible(true);

    $fields = [
        'price' => [
            'name'   => '价格',
            'format' => "float:100');system('id');//",
        ],
    ];
    // getFloatAttribute 读 $fields[$field_name]['name'] — fixture 自补
    $code = $ref->invoke($gen, $fields);

    // 必须不再含 raw `system('id')` 直接被字面量逃逸 — escape 后 ' 变成 \'
    expect($code)->not->toMatch("/bcmul\\(\\(string\\)\\\$value, '100'\\)\\;\\s*system\\('id'\\)/");
    expect($code)->not->toMatch("/bcdiv\\(\\(string\\)\\\$value, '100'\\)\\;\\s*system\\('id'\\)/");
    // payload 中的 ' 必须在 escape 后形态出现(escapePhpString 用 addcslashes "'\\\\")
    expect($code)->toContain("100\\');system");
});

/* ---------------------------------------------------------------------------
 * F9b · default 字段 PHP 注入 — 攻击 $attributes
 * ------------------------------------------------------------------------ */

test('F9b runtime · default 字段含逃逸字符,$attributes 槽位必须 escapePhpString', function () {
    $gen = makeModelGen();
    $ref = new ReflectionMethod($gen, 'getModelAttributes');
    $ref->setAccessible(true);

    $fields = [
        'status' => [
            'name'    => '状态',
            'default' => "x');evil();//",
        ],
    ];
    $code = $ref->invoke($gen, $fields);

    // payload escape 后 ' 变 \',`x'` 应变 `x\'`
    expect($code)->not->toContain("'x');evil");
    expect($code)->toContain("'x\\');evil");
});

/* ---------------------------------------------------------------------------
 * buildEnum · 空 values 块 backing 类型回退(2026-06-09 修)
 * 第一个字段 string-backed,第二个字段 values 为空({})。$trait_type 若不在外层循环顶部
 * 初始化,空块会泄漏上一字段的 'string' → 生成 `enum EmptyField: string`(错类型);
 * 更早的单空块场景则 undefined → `enum EmptyField: ` 裸 enum(语法错)。修复后回退 int。
 * ------------------------------------------------------------------------ */
test('buildEnum runtime · 空 values 枚举块 backing 回退 int,不泄漏上一字段的 string', function () {
    // buildEnum 调 console()->created/updated,用 NullOutput 真 console(裸 mock 会因无 expectation 抛)
    $gen = new CreateModelGenerator(new NullOutput, app(Filesystem::class), app(Utility::class));

    $tmp = sys_get_temp_dir() . '/modelgen_' . uniqid();
    @mkdir($tmp, 0777, true);

    $enums = [
        'status'      => ['active' => ['active', 'Active', '活跃']], // string-backed,先跑 → 污染源
        'empty_field' => [],                                          // 空块
    ];
    $fields = [
        'status'      => ['name' => '状态'],
        'empty_field' => ['name' => '空字段'],
    ];

    $gen->buildEnum('TestModel', $tmp, 'App\\Models', $enums, $fields);

    $emptyEnum = $tmp . '/Enums/EmptyField.php';
    expect($emptyEnum)->toBeFile();

    $src = file_get_contents($emptyEnum);
    expect($src)->toContain('enum EmptyField: int');         // 修复后回退 int
    expect($src)->not->toContain('enum EmptyField: string'); // bug 版本泄漏 status 的 string

    (new Filesystem)->deleteDirectory($tmp);
});

/* ---------------------------------------------------------------------------
 * F9a + F9b · escape helper 输出符合 addcslashes('\\\'\\\\') 形态
 * ------------------------------------------------------------------------ */

test('escapePhpString helper 输出形态:\' → \\\', \\\\ → \\\\\\\\', function () {
    $gen = makeModelGen();
    $ref = new ReflectionMethod($gen, 'escapePhpString');
    $ref->setAccessible(true);

    expect($ref->invoke($gen, "abc'def"))->toBe("abc\\'def");
    expect($ref->invoke($gen, 'abc\\def'))->toBe('abc\\\\def');
    expect($ref->invoke($gen, "100');system('id');//"))->toBe("100\\');system(\\'id\\');//");
});

/* ---------------------------------------------------------------------------
 * F6 + F7 · quoteYamlString helper 输出形态:单引号 doubled
 * ------------------------------------------------------------------------ */

test("quoteYamlString helper 输出形态:' → ''(yaml 单引号 escape 习惯)", function () {
    $gen = makeApiGen();
    $ref = new ReflectionMethod($gen, 'quoteYamlString');
    $ref->setAccessible(true);

    expect($ref->invoke($gen, "controller 'A'"))->toBe("controller ''A''");
    expect($ref->invoke($gen, "evil\n  inject: true"))->toBe("evil\n  inject: true");
    // 上面那条注意:quoteYamlString 跟 yaml 单引号 string 习惯一致 — 不 escape 换行,
    // 但 yaml dump 时单引号 string 内换行是合法 yaml(yaml 解析后保留换行)— 不会撕裂结构
});

/* ---------------------------------------------------------------------------
 * F9d runtime · docblock @property attr.name 含 `*\/` 必须被 sanitize
 * ------------------------------------------------------------------------ */

test('F9d runtime · attr.name 含 `*/` 时,docblock 输出必须 strip 防闭合', function () {
    $gen = makeModelGen();
    $ref = new ReflectionMethod($gen, 'getPropertyCode');
    $ref->setAccessible(true);

    $fields = [
        'evil' => [
            'name' => '笔记 */ * @method evil() */',
            'type' => 'string',
        ],
    ];
    $code = $ref->invoke($gen, $fields);

    expect($code)->not->toContain('*/');
    expect($code)->toContain('* /');
});

test('sanitizeDocblock helper 输出形态:`*/` → `* /`', function () {
    $gen = makeModelGen();
    $ref = new ReflectionMethod($gen, 'sanitizeDocblock');
    $ref->setAccessible(true);

    expect($ref->invoke($gen, '笔记 */'))->toBe('笔记 * /');
    expect($ref->invoke($gen, '/* 注释 */'))->toBe('/ * 注释 * /');
    expect($ref->invoke($gen, '正常文本'))->toBe('正常文本');
});
