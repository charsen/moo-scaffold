<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Generator\CreateModelGenerator;
use Mooeen\Scaffold\Support\ColumnTypeGroups;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Output\NullOutput;

/**
 * 类型清单收口回归锁（2026-09-11）。
 *
 * 背景：本仓已**复发两次**同型事故 —— 有人写了更窄的 inline 类型列表，整类列静默丢校验：
 *   - `CreateControllerGenerator` 2026-06-11：漏 smallint/mediumint/decimal/float/double
 *     → 生成的 Request 对数值列零类型校验
 *   - 同文件同日：漏 text 系列 → 生成的 Request 里文本字段缺 `'string'` 校验
 * 本次把 Generator 侧仍在内联的分组收口到 `Support\ColumnTypeGroups`（第一阶段只收口了 Designer
 * 侧的数值分组），并修掉一个现存的同型漏判（见下）。
 *
 * 三层锁：
 *   1. 每个常量的**成员逐字锁定** —— 有人"顺手"删一个成员就会红。
 *   2. `:343` 修复的**真实产物**验证 —— longtext/mediumtext 列现在确实拿到 LIKE scope。
 *   3. 反内联锚点 —— 类型字面不得再出现在 src/Generator。
 */

/* ---------------------------------------------------------------------------
 * 一、成员锁定：常量就是唯一口径，改成员必须是有意识的决定
 * ------------------------------------------------------------------------ */

it('ColumnTypeGroups 各分组成员与收口前字面逐字一致（数值族）', function () {
    expect(ColumnTypeGroups::INT)->toBe(['tinyint', 'smallint', 'mediumint', 'int', 'bigint']);
    expect(ColumnTypeGroups::INT_NO_BIGINT)->toBe(['tinyint', 'smallint', 'mediumint', 'int']);
    expect(ColumnTypeGroups::FLOAT)->toBe(['decimal', 'float', 'double']);
    expect(ColumnTypeGroups::NUMERIC)->toBe(['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double']);
    expect(ColumnTypeGroups::UNSIGNED_DEFAULT)->toBe(['tinyint', 'int', 'bigint', 'decimal', 'float']);

    // INT_NO_BIGINT 是 INT 的真子集，且差的正好是 bigint（TS 模型里 bigint 独立映射）
    expect(array_values(array_diff(ColumnTypeGroups::INT, ColumnTypeGroups::INT_NO_BIGINT)))->toBe(['bigint']);
});

it('ColumnTypeGroups 各分组成员与收口前字面逐字一致（字符串 / 时间 / 布尔族）', function () {
    expect(ColumnTypeGroups::BOOL)->toBe(['bool', 'boolean']);
    expect(ColumnTypeGroups::STRING)->toBe(['varchar', 'char', 'tinytext', 'text', 'mediumtext', 'longtext']);
    expect(ColumnTypeGroups::STRING_SIZE)->toBe(['varchar', 'char']);
    expect(ColumnTypeGroups::TEXT_LARGE)->toBe(['text', 'mediumtext', 'longtext']);
    expect(ColumnTypeGroups::DATE)->toBe(['date', 'datetime', 'timestamp']);
    expect(ColumnTypeGroups::DATETIME)->toBe(['datetime', 'timestamp']);

    // "故意窄"的三组关系必须成立，否则说明有人把它们并成了一个
    expect(ColumnTypeGroups::STRING_SIZE)->toBe(array_values(array_intersect(ColumnTypeGroups::STRING, ColumnTypeGroups::STRING_SIZE)));
    expect(ColumnTypeGroups::TEXT_LARGE)->not->toContain('tinytext');          // 大文本刻意不含 tinytext
    expect(ColumnTypeGroups::DATE)->not->toContain('time');                    // 日期族刻意不含 time
    expect(array_values(array_diff(ColumnTypeGroups::STRING, ColumnTypeGroups::TEXT_LARGE)))->toBe(['varchar', 'char', 'tinytext']);
});

/* ---------------------------------------------------------------------------
 * 二、`:343` 修复的真实产物验证
 *     修前 `buildFilter` 的 LIKE-scope 族是 ['varchar','char','text','tinytext']
 *     → mediumtext / longtext 列拿不到搜索 scope（姊妹点 CreateControllerGenerator
 *       的字符串族是完整 6 成员，两处口径不一致）
 * ------------------------------------------------------------------------ */

it('buildFilter：longtext / mediumtext 列现在也生成 LIKE scope（修 :343 漏判）', function () {
    config()->set('scaffold.author', 'tester');

    $gen = new CreateModelGenerator(new NullOutput, app(Filesystem::class), app(Utility::class));
    $dir = sys_get_temp_dir() . '/modelGen_filter_' . uniqid();

    try {
        $gen->buildFilter($dir, 'App\\Models', 'DemoFilter', [
            'enums'  => [],
            'fields' => [
                'id'        => ['type' => 'bigint'],
                'title'     => ['type' => 'varchar'],
                'tiny'      => ['type' => 'tinytext'],
                'note'      => ['type' => 'text'],
                'summary'   => ['type' => 'mediumtext'],
                'content'   => ['type' => 'longtext'],
                'meta'      => ['type' => 'json'],
                '_internal' => ['type' => 'varchar'],
                'password'  => ['type' => 'varchar'],
            ],
        ], true);

        $file = $dir . '/Filters/DemoFilter.php';
        expect(is_file($file))->toBeTrue('buildFilter 应产出 filter 文件');
        $out = (string) file_get_contents($file);

        // 修前这里只会有 title / tiny / note —— mediumtext、longtext 被漏掉。
        // 注意别写成 expect($out)->toContain($needle, '提示')：Pest 的 toContain 是**可变参数**，
        // 第二个字符串会被当成"还必须包含的另一个串"，断言就变成永远失败。
        foreach (['title', 'tiny', 'note', 'summary', 'content'] as $f) {
            expect(str_contains($out, "public function {$f}(\$str)"))
                ->toBeTrue("字段 {$f} 应有 LIKE scope");
        }
        expect($out)->toContain("'LIKE'");

        // 隐藏字段仍被排除；json 走 whereJsonContains 而非 LIKE
        expect($out)->not->toContain('_internal');
        expect($out)->not->toContain('password');
        expect($out)->toContain('whereJsonContains');
    } finally {
        app(Filesystem::class)->deleteDirectory($dir);
    }
});

/* ---------------------------------------------------------------------------
 * 三、反内联锚点：类型字面不得再出现在 src/Generator
 * ------------------------------------------------------------------------ */

/** 在 src/Generator 下找 `in_array(..., [ ... '某类型' ... ])` 形态的内联类型字面 */
function fieldTypesScanInlineLiterals(): array
{
    // 只用"几乎不可能是字段名"的类型 token，避免把 `in_array($f, ['id','deleted_at'])`
    // 这类合法的字段名列表误报
    $typeTokens = implode('|', [
        'tinyint', 'smallint', 'mediumint', 'bigint', 'varchar', 'tinytext',
        'mediumtext', 'longtext', 'decimal', 'double', 'boolean',
    ]);
    $re = "/in_array\([^,]+,\s*\[[^\]]*'({$typeTokens})'/";

    $hits = [];
    foreach (glob(__DIR__ . '/../../../src/Generator/*.php') ?: [] as $file) {
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $i => $line) {
            if (preg_match($re, $line, $m)) {
                $hits[] = basename($file) . ':' . ($i + 1) . " (命中 {$m[1]}) " . trim($line);
            }
        }
    }

    return $hits;
}

it('src/Generator 下不再有内联的类型字面（防"漏一个成员"事故复发）', function () {
    expect(fieldTypesScanInlineLiterals())->toBeEmpty(
        '类型清单必须走 ColumnTypeGroups —— 找到内联字面:' . PHP_EOL . implode(PHP_EOL, fieldTypesScanInlineLiterals())
    );
});

it('接线锚点：各生成器确实在消费 ColumnTypeGroups（防常量建了没人用）', function () {
    $need = [
        'CreateModelGenerator.php'      => 8,
        'CreateControllerGenerator.php' => 6,
        'CreateTSModelGenerator.php'    => 2,
        'CreateResourceGenerator.php'   => 1,
        'FreshStorageGenerator.php'     => 2,
    ];

    foreach ($need as $file => $min) {
        $src = (string) file_get_contents(__DIR__ . '/../../../src/Generator/' . $file);
        $n   = substr_count($src, 'ColumnTypeGroups::');
        expect($n)->toBeGreaterThanOrEqual($min, "{$file} 应至少消费 {$min} 处 ColumnTypeGroups，实际 {$n}");
    }
});

/* ---------------------------------------------------------------------------
 * 四、类型归一单一来源：ColumnTypeGroups::canonicalize()（2026-09-18）
 *
 * 背景：这个「Laravel-isms + 大小写变体 → scaffold canonical」的映射原先在 Designer 侧有**三份** ——
 * `SchemaLoader`（完整版）+ `SchemaDiffService` / `MigrationWriter`（各一份**残缺副本**，只处理
 * bool/boolean）。残缺副本在「输入已被 normalize」时碰巧无害 ⇒ 它**不会以任何失败暴露自己**，
 * 所以只能靠锚点挡住它被复制回来。Designer 侧的「不该有私有副本」锚点见
 * `tests/Feature/Designer/SchemaLoaderTest.php`；这里锁归一表本身。
 * ------------------------------------------------------------------------ */

it('canonicalize 是归一表的唯一口径：Laravel-isms + 大小写变体', function () {
    expect(ColumnTypeGroups::canonicalize('longText'))->toBe('longtext')
        ->and(ColumnTypeGroups::canonicalize('mediumText'))->toBe('mediumtext')
        ->and(ColumnTypeGroups::canonicalize('tinyText'))->toBe('tinytext')
        ->and(ColumnTypeGroups::canonicalize('bigInteger'))->toBe('bigint')
        ->and(ColumnTypeGroups::canonicalize('smallInteger'))->toBe('smallint')
        ->and(ColumnTypeGroups::canonicalize('mediumInteger'))->toBe('mediumint')
        ->and(ColumnTypeGroups::canonicalize('tinyInteger'))->toBe('tinyint')
        ->and(ColumnTypeGroups::canonicalize('integer'))->toBe('int')
        ->and(ColumnTypeGroups::canonicalize('unsignedInteger'))->toBe('int')
        ->and(ColumnTypeGroups::canonicalize('unsignedBigInteger'))->toBe('bigint')
        ->and(ColumnTypeGroups::canonicalize('unsignedSmallInteger'))->toBe('smallint')
        ->and(ColumnTypeGroups::canonicalize('unsignedMediumInteger'))->toBe('mediumint')
        ->and(ColumnTypeGroups::canonicalize('unsignedTinyInteger'))->toBe('tinyint')
        ->and(ColumnTypeGroups::canonicalize('string'))->toBe('varchar')
        ->and(ColumnTypeGroups::canonicalize('bool'))->toBe('boolean')
        ->and(ColumnTypeGroups::canonicalize('boolean'))->toBe('boolean')
        ->and(ColumnTypeGroups::canonicalize('dateTime'))->toBe('datetime');     // strtolower 自动
});

it('canonicalize 保住 char 的一等地位，且已是 canonical 的形态原样通过', function () {
    // char 是 UUID 主键（char(36)）的类型,归一成 varchar 会把定长列改写成变长列。
    expect(ColumnTypeGroups::canonicalize('char'))->toBe('char')
        ->and(ColumnTypeGroups::canonicalize('CHAR'))->toBe('char')
        ->and(ColumnTypeGroups::canonicalize('bigint'))->toBe('bigint')
        ->and(ColumnTypeGroups::canonicalize('varchar'))->toBe('varchar')
        // `time` 刻意保持原样：designer 可建、codegen 不特殊处理（见 DATE 常量注释）
        ->and(ColumnTypeGroups::canonicalize('time'))->toBe('time')
        ->and(ColumnTypeGroups::canonicalize('json'))->toBe('json');
});
