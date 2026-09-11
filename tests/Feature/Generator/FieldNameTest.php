<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Mooeen\Scaffold\Generator\CreateModelGenerator;
use Mooeen\Scaffold\Support\FieldName;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Output\NullOutput;

/**
 * FieldName 收口回归锁（2026-09-11）。
 *
 * 背景：「隐藏字段」判定（`_` 前缀 or 含 password）原先在 Model / Resource / Controller 里
 * 逐字复制 5 份，「snake → Studly」复制 4 份。收口到 `Mooeen\Scaffold\Support\FieldName`。
 * 这是纯去重复重构 —— 产物必须**逐字节不变**，本文件锁的就是"不变"。
 *
 * 为什么这条测试在重构前是缺的：仓库此前**没有任何**用例断言生成出来的 `protected $hidden`
 * 长什么样，所以这条规则怎么改都不会被测试发现。现在补上。
 *
 * 注意：`findLinesMatching()` 已在 EscapeCoverageTest.php 定义，本文件用独立函数名避免
 * Pest 顶层 redeclare。
 */

/* ---------------------------------------------------------------------------
 * 一、判定规则本体（isHidden / studly）
 * ------------------------------------------------------------------------ */

it('isHidden：`_` 前缀或名字含 password 才算隐藏，其余一律放行', function () {
    // `_` 前缀 —— 内部字段
    expect(FieldName::isHidden('_internal'))->toBeTrue();
    expect(FieldName::isHidden('_'))->toBeTrue();
    expect(FieldName::isHidden('_sort'))->toBeTrue();

    // 含 password（中缀也算，这是 A 变体的口径，比 B 变体的 `*_password` 宽）
    expect(FieldName::isHidden('password'))->toBeTrue();
    expect(FieldName::isHidden('new_password'))->toBeTrue();
    expect(FieldName::isHidden('password_hash'))->toBeTrue();     // B 变体(faker)刻意不认这个
    expect(FieldName::isHidden('oldPassword'))->toBeFalse();      // str_contains 大小写敏感(原实现如此)

    // 普通业务字段
    expect(FieldName::isHidden('id'))->toBeFalse();
    expect(FieldName::isHidden('user_name'))->toBeFalse();
    expect(FieldName::isHidden('email'))->toBeFalse();
    expect(FieldName::isHidden('remark'))->toBeFalse();
});

it('studly：snake_case 结果与 Str::studly() 一致（保留原表达式不换实现的原因见下条）', function () {
    foreach (['user_name', 'id', 'real_name', 'a_b_c', 'mobile', '_internal'] as $snake) {
        expect(FieldName::studly($snake))->toBe(Str::studly($snake), "字段 {$snake} 的 Studly 结果应一致");
    }

    expect(FieldName::studly('user_name'))->toBe('UserName');
    expect(FieldName::studly('a_b_c'))->toBe('ABC');
    expect(FieldName::studly('id'))->toBe('Id');
});

it('studly：刻意不委托 Str::studly() —— 连字符行为不同，换实现会改产物', function () {
    // 这是收口时刻意保留原表达式的理由：Str::studly() 把 `-` 也当分隔符，
    // 原表达式只认 `_`。schema 校验虽然只放 snake_case，但生成器不该靠上游校验
    // 保证输出稳定 —— 一旦有人放宽字段名校验，换实现就会静默改掉 Enum 类名 / 访问器名。
    expect(FieldName::studly('user-name'))->toBe('User-name');
    expect(Str::studly('user-name'))->toBe('UserName');
    expect(FieldName::studly('user-name'))->not->toBe(Str::studly('user-name'));
});

/* ---------------------------------------------------------------------------
 * 二、产物锁定：CreateModelGenerator::getHidden()
 *     —— 重构前仓库 0 测试覆盖，这里补上
 * ------------------------------------------------------------------------ */

it('CreateModelGenerator::getHidden 产出：`_` 前缀 + 含 password 进 $hidden，业务字段不进', function () {
    $gen = new CreateModelGenerator(new NullOutput, app(Filesystem::class), app(Utility::class));

    $ref = new ReflectionMethod($gen, 'getHidden');
    $ref->setAccessible(true);

    $fields = [
        '_internal'     => [],
        'password'      => [],
        'new_password'  => [],
        'password_hash' => [],
        'user_name'     => [],
        'id'            => [],
        'email'         => [],
    ];

    $code = $ref->invoke($gen, $fields);

    // 顺序跟随 fields 顺序；user_name / id / email 必须不在里面
    expect($code)->toContain("protected \$hidden = ['_internal','password','new_password','password_hash'];");
    expect($code)->not->toContain("'user_name'");
    expect($code)->not->toContain("'email'");
    expect($code)->not->toContain("'id'");
});

it('CreateModelGenerator::getHidden 无隐藏字段时产出空数组（不是空语句）', function () {
    $gen = new CreateModelGenerator(new NullOutput, app(Filesystem::class), app(Utility::class));

    $ref = new ReflectionMethod($gen, 'getHidden');
    $ref->setAccessible(true);

    $code = $ref->invoke($gen, ['id' => [], 'email' => []]);

    expect($code)->toContain('protected $hidden = [];');
});

/* ---------------------------------------------------------------------------
 * 三、接线锚点：生成器确实走 FieldName，且规则不再被内联回去
 *     （防「trait/类在但没人用」与「重构后又被复制回去」两种假绿）
 * ------------------------------------------------------------------------ */

/** 在 src/Generator 下所有 php 文件里搜 needle，返回命中描述 */
function fieldNameScanGenerators(string $needle): array
{
    $hits = [];
    foreach (glob(__DIR__ . '/../../../src/Generator/*.php') ?: [] as $file) {
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $i => $line) {
            if (str_contains($line, $needle)) {
                $hits[] = basename($file) . ':' . ($i + 1) . ' ' . trim($line);
            }
        }
    }

    return $hits;
}

it('5 个隐藏判定点 + 4 个 Studly 点都走 FieldName（少一处即说明有人内联回去）', function () {
    expect(fieldNameScanGenerators('FieldName::isHidden('))->toHaveCount(5);
    expect(fieldNameScanGenerators('FieldName::studly('))->toHaveCount(4);
});

it('旧的裸表达式不得再出现在 src/Generator（防复制粘贴复发）', function () {
    // A 变体的两种写法（or / ||）
    expect(fieldNameScanGenerators("'_') or str_contains("))->toBeEmpty('隐藏判定被内联回生成器了');
    expect(fieldNameScanGenerators("'_') || str_contains("))->toBeEmpty('隐藏判定被内联回生成器了');
    // Studly 裸表达式（合法实现只剩 Support/FieldName.php 一处，不在 Generator 目录）
    expect(fieldNameScanGenerators("ucwords(str_replace('_', ' '"))->toBeEmpty('Studly 表达式被内联回生成器了');
});
