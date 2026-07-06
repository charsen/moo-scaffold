<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Generator\CreateModelGenerator;
use Mooeen\Scaffold\Generator\UpdateMultilingualGenerator;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Output\NullOutput;

/**
 * 生成器「产物有效性」冒烟(2026-06-11)。
 *
 * 跟 EscapeCoverageTest(源码扫描,锁「代码长这样」)/ GeneratorFixesTest(锁具体修复)互补:
 * 这里**真跑生成器、用对抗性输入,断言产物是合法 PHP / YAML**——不比对字节(那是 CodegenCommandsTest
 * 明确放弃的脆做法:stub 改一行 spec 全要跟着改),只验**有效性**:能不能被 PHP 解析 / YAML parse。
 *
 * 抓的是这轮复盘暴露的**主类**:生成器把含特殊字符(反斜杠 / 撇号 / 引号 / `$` / 注释闭合符)的
 * 字段名 / 枚举标签 / 默认值拼进单引号 PHP 串或 YAML 时,转义不全 → 产物语法崩 / 解析失败
 * (④ i18n 反斜杠破坏 lang 文件、③ name 裸写撕裂 yaml 都是此类)。任何未来同类回归当场红。
 *
 * 边界:`token_get_all(TOKEN_PARSE)` 抓的是**语法 / 解析**错;② string-enum factory 的
 * `randomElement([A,B])` 裸字是**语义**错(语法合法、运行时才 Undefined constant)——那条由
 * GeneratorFixesTest ② 的 reflection 锁,不在本文件覆盖范围。
 *
 * 驱动选了**无副作用**的两个 PHP 发射点:buildEnum(只写 Enums/ 目录)+ i18n start()
 * (写 lang 文件)。buildFactory 因带 updateSeeder 副作用、且 ② 已另有锁,这里不驱动。
 */

/** 对抗性文本:专挑会撕裂单引号 PHP 串 / YAML 标量的字符。 */
function genout_adversarialLabels(): array
{
    return [
        'C:\\temp\\',     // 结尾反斜杠 —— ④ 的元凶(`'...\'` 让 \' 吃掉闭引号)
        "O'Brien",        // 撇号
        "a'b\\c",         // 撇号 + 反斜杠混合
        'price $5',       // $ 变量符
        'say "hi"',       // 双引号
        'x*/y',           // 注释闭合
    ];
}

/** 用 PHP 解析器验证一段完整 PHP 源是否合法;合法返回 null,否则返回错误信息。 */
function genout_phpSyntaxError(string $phpSource): ?string
{
    try {
        token_get_all($phpSource, TOKEN_PARSE);

        return null;
    } catch (\ParseError $e) {
        return $e->getMessage();
    }
}

// ─── A · buildEnum 产物对 string/int backed + 对抗性标签都是合法 PHP ──────────
it('buildEnum:string/int backed 枚举 + 含特殊字符的标签 → 产物是合法 PHP', function () {
    $fs      = app(Filesystem::class);
    $sandbox = sys_get_temp_dir() . '/genout_enum_' . uniqid();
    $fs->ensureDirectoryExists($sandbox);

    try {
        $gen = new CreateModelGenerator(new NullOutput, $fs, app(Utility::class));

        $adv = genout_adversarialLabels();
        // 关键:buildEnum 把 item[0]「值」写进 `case NAME = <value>;`(string 值经 escapePhpString),
        // 标签(item[1/2])只生成 `__('model.x')` 翻译 key、不进枚举文件。所以对抗性必须放在**值**上
        // 才真正考验枚举文件的转义。status:string-backed(对抗性值);level:int-backed(整型值)。
        $enums = [
            'status' => [
                'active'   => [$adv[2], 'active', '启用'],   // 值含撇号+反斜杠
                'inactive' => [$adv[0], 'inactive', '停用'], // 值结尾反斜杠
            ],
            'level' => [
                'low'  => [1, 'low', '低'],
                'high' => [9, 'high', '高'],
            ],
        ];
        // buildEnum 读 $fields[$field_name]['name'] 当注释
        $fields = ['status' => ['name' => '状态'], 'level' => ['name' => '等级']];

        $gen->buildEnum('Demo', $sandbox, 'App\\Models\\Demo', $enums, $fields);

        $enumFiles = glob($sandbox . '/Enums/*.php') ?: [];
        expect($enumFiles)->toHaveCount(2);
        foreach ($enumFiles as $f) {
            $err = genout_phpSyntaxError($fs->get($f));
            expect($err)->toBeNull('Enum 产物非法 PHP (' . basename($f) . '): ' . $err);
        }
    } finally {
        $fs->deleteDirectory($sandbox);
    }
});

// ─── B · i18n 语言文件对含特殊字符的枚举标签是合法 PHP(④ 类)──────────────────
it('i18n start():含反斜杠/撇号/引号的枚举标签 → 生成的 lang 文件是合法 PHP', function () {
    $fs       = app(Filesystem::class);
    $origStor = app()->storagePath();
    app()->useStoragePath(sys_get_temp_dir() . '/genout_i18n_' . uniqid());
    $fs->ensureDirectoryExists(storage_path('scaffold'));

    config()->set('scaffold.languages', ['en', 'zh-CN']);
    config()->set('scaffold.author', 'tester');
    $schemaDir = base_path('genout-i18n-schema/');

    try {
        $adv = genout_adversarialLabels();
        // enums.php(var_export 安全编码,对抗值无损进缓存)→ i18n model.php 走这里
        $enums = ['demo' => ['status' => [
            'active'   => ['A', $adv[0], $adv[1]],   // 反斜杠结尾 + 撇号
            'inactive' => ['B', $adv[2], $adv[4]],
        ]]];
        $fs->put(storage_path('scaffold/enums.php'), '<?php return ' . var_export($enums, true) . ';');

        // db / validation 走 _fields.yaml;给一个含反斜杠/撇号的字段(YAML 单引号:\ 字面、'' 转义撇号)
        $fs->ensureDirectoryExists($schemaDir);
        $fs->put(
            $schemaDir . '_fields.yaml',
            "append_fields: {}\n"
            . "table_fields:\n"
            . "    bad_field: { 'en': 'tail\\', 'zh-CN': 'O''Brien' }\n",
        );
        config()->set('scaffold.database.schema', $schemaDir);

        expect((new UpdateMultilingualGenerator(new NullOutput, $fs, app(Utility::class)))->start())->toBeTrue();

        $checked = 0;
        foreach (['en', 'zh-CN'] as $lang) {
            foreach (['model', 'db', 'validation'] as $file) {
                $p = lang_path("{$lang}/{$file}.php");
                if ($fs->isFile($p)) {
                    $err = genout_phpSyntaxError($fs->get($p));
                    expect($err)->toBeNull("lang {$lang}/{$file}.php 非法 PHP: " . $err);
                    $checked++;
                }
            }
        }
        expect($checked)->toBeGreaterThan(0);   // 确实生成并校验了文件,不是空跑假绿
    } finally {
        foreach (['en', 'zh-CN'] as $lang) {
            foreach (['model', 'db', 'validation'] as $file) {
                $fs->delete(lang_path("{$lang}/{$file}.php"));
            }
        }
        $fs->deleteDirectory($schemaDir);
        $fs->deleteDirectory(storage_path());
        app()->useStoragePath($origStor);
    }
});
