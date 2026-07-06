<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Generator\CreateControllerGenerator;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Output\NullOutput;

/**
 * 生成器 5 修回归锁(2026-06-11 全面复盘):
 *   ① CreateControllerGenerator    数值类型校验只认 int/tinyint/bigint,漏 smallint/mediumint/decimal/float/double
 *   ② CreateModelGenerator         string-backed 枚举 factory 发裸字 → Undefined constant
 *   ③ CreateApiGenerator           action name: 裸写进 YAML(唯一漏 quoteYamlString 的槽)
 *   ④ UpdateMultilingualGenerator   i18n 值 &apos; 替换(漏反斜杠 + 撇号损坏)→ 见 UpdateMultilingualGeneratorTest
 *   ⑤ UpdateAuthorizationGenerator  ACL 标签 &apos; 替换(VarExporter 之前多此一举 + 损坏)
 *
 * ① 走行为测试(reflection rebuildFieldsRules);②③⑤ 走源码扫描(EscapeCoverageTest 同款,毫秒级、不需 DB/fixture)。
 */
function genfix_src(string $relPath): string
{
    $abs = __DIR__ . '/../../../src/' . ltrim($relPath, '/');
    expect(is_file($abs))->toBeTrue("source not found: {$relPath}");

    return (string) file_get_contents($abs);
}

// ─── ① 数值校验覆盖全类型 ─────────────────────────────────────────────────
it('① rebuildFieldsRules:decimal/float/double → numeric,smallint/mediumint → integer,unsigned → min:0', function () {
    // rebuildFieldsRules 顶部调 getModelIds()(getRequire model_ids.php)。该缓存是跨测试共享的
    // (UniqueSemanticsTest::callRebuildFieldsRules 也依赖它存在),只在缺失时补一份空的、**不删**
    // (删了会破坏依赖它存在的其它测试);本测试字段非外键,空 model_ids 足够。
    $fs      = app(Filesystem::class);
    $storage = storage_path('scaffold/');
    $fs->ensureDirectoryExists($storage);
    if (! $fs->isFile($storage . 'model_ids.php')) {
        $fs->put($storage . 'model_ids.php', '<?php return [];');
    }

    $gen = new CreateControllerGenerator(new NullOutput, $fs, app(Utility::class));
    $ref = new ReflectionMethod($gen, 'rebuildFieldsRules');
    $ref->setAccessible(true);

    $fields = [
        'amount' => ['type' => 'decimal',   'required' => true,  'allow_null' => false, 'unsigned' => false],
        'ratio'  => ['type' => 'float',     'required' => false, 'allow_null' => true,  'unsigned' => false],
        'dbl'    => ['type' => 'double',    'required' => false, 'allow_null' => false, 'unsigned' => false],
        'qty'    => ['type' => 'smallint',  'required' => true,  'allow_null' => false, 'unsigned' => true],
        'mid'    => ['type' => 'mediumint', 'required' => false, 'allow_null' => false, 'unsigned' => false],
        'price'  => ['type' => 'decimal',   'required' => false, 'allow_null' => false, 'unsigned' => true],
    ];
    $rules = $ref->invoke($gen, $fields, []);

    // bug 版本:这五种类型一条数值规则都没有
    expect($rules['amount'])->toContain('numeric');
    expect($rules['ratio'])->toContain('numeric');
    expect($rules['dbl'])->toContain('numeric');
    expect($rules['qty'])->toContain('integer');
    expect($rules['mid'])->toContain('integer');
    // unsigned decimal/smallint → min:0(浮点也适用)
    expect($rules['qty'])->toContain('min:0');
    expect($rules['price'])->toContain('min:0');
});

// ─── rebuildFieldsRules:text 系列也加 string 规则(2026-06-21 修:原只认 char/varchar,text 漏 string)──
it('rebuildFieldsRules:text/tinytext/mediumtext/longtext → string;varchar 仍 string+max(回归)', function () {
    $fs      = app(Filesystem::class);
    $storage = storage_path('scaffold/');
    $fs->ensureDirectoryExists($storage);
    if (! $fs->isFile($storage . 'model_ids.php')) {
        $fs->put($storage . 'model_ids.php', '<?php return [];');
    }

    $gen = new CreateControllerGenerator(new NullOutput, $fs, app(Utility::class));
    $ref = new ReflectionMethod($gen, 'rebuildFieldsRules');
    $ref->setAccessible(true);

    $fields = [
        'body'    => ['type' => 'text',       'required' => true,  'allow_null' => false, 'unsigned' => false],
        'tag'     => ['type' => 'tinytext',   'required' => false, 'allow_null' => true,  'unsigned' => false],
        'summary' => ['type' => 'mediumtext', 'required' => false, 'allow_null' => false, 'unsigned' => false],
        'note'    => ['type' => 'longtext',   'required' => false, 'allow_null' => false, 'unsigned' => false],
        'title'   => ['type' => 'varchar',    'required' => true,  'allow_null' => false, 'unsigned' => false, 'size' => 64],
    ];
    $rules = $ref->invoke($gen, $fields, []);

    // bug:text 系列原先缺 'string'
    expect($rules['body'])->toContain('string');
    expect($rules['tag'])->toContain('string');
    expect($rules['summary'])->toContain('string');
    expect($rules['note'])->toContain('string');
    // text 无 size → 不带 max(max 只给 char/varchar)
    expect(collect($rules['body'])->filter(fn ($r) => str_starts_with((string) $r, 'max:')))->toBeEmpty();
    // varchar 仍 string + max(回归,没误伤)
    expect($rules['title'])->toContain('string');
    expect($rules['title'])->toContain('max:64');
});

// ─── rebuildFieldsRules:操作人字段(creator_id/updater_id)不进 Request 规则 ────────
// 它们由 model 端 HasOperator trait 自动填充,非用户输入。formLayout 派生自 $rules → 表单也不含。
it('rebuildFieldsRules:creator_id / updater_id 被排除(由 HasOperator trait 填充,非用户输入)', function () {
    $fs      = app(Filesystem::class);
    $storage = storage_path('scaffold/');
    $fs->ensureDirectoryExists($storage);
    if (! $fs->isFile($storage . 'model_ids.php')) {
        $fs->put($storage . 'model_ids.php', '<?php return [];');
    }

    $gen = new CreateControllerGenerator(new NullOutput, $fs, app(Utility::class));
    $ref = new ReflectionMethod($gen, 'rebuildFieldsRules');
    $ref->setAccessible(true);

    $fields = [
        'title'      => ['type' => 'varchar', 'required' => true,  'allow_null' => false, 'unsigned' => false, 'size' => 64],
        'creator_id' => ['type' => 'bigint',  'required' => false, 'allow_null' => false, 'unsigned' => true],
        'updater_id' => ['type' => 'bigint',  'required' => false, 'allow_null' => false, 'unsigned' => true],
    ];
    $rules = $ref->invoke($gen, $fields, []);

    // 普通字段仍在
    expect($rules)->toHaveKey('title');
    // 操作人字段不生成任何规则键
    expect($rules)->not->toHaveKey('creator_id');
    expect($rules)->not->toHaveKey('updater_id');
});

// ─── getShowFields:show() 的 $columns 一字段一行(对齐 getListColumns 的 list_columns) ───
it('getShowFields:一字段一行 + 3tab 缩进,跳过 _ 前缀 / password', function () {
    $gen = new CreateControllerGenerator(new NullOutput, app(Filesystem::class), app(Utility::class));
    $ref = new ReflectionMethod($gen, 'getShowFields');
    $ref->setAccessible(true);

    $out = $ref->invoke($gen, [
        'id'             => [],
        'name'           => [],
        '_hidden'        => [],   // _ 前缀 → 跳过
        'login_password' => [],   // password → 跳过
        'status'         => [],
    ]);

    expect($out)->toContain("'id'");
    expect($out)->toContain("'name'");
    expect($out)->toContain("'status'");
    expect($out)->not->toContain('_hidden');
    expect($out)->not->toContain('login_password');
    // 一字段一行:字段间用「逗号 + 换行 + 12 空格」分隔(= stub 多行 $columns 的缩进)
    expect($out)->toContain(",\n            '");
    // 首行已 trim(由 stub 的 {{show_fields}} 占位缩进顶上)
    expect($out)->toStartWith("'id'");
});

// ─── ② string-backed 枚举 factory 不发裸字 ──────────────────────────────────
it('② CreateModelGenerator factory 枚举值加引号 + escapePhpString(不发裸字 randomElement([A,B]))', function () {
    $src = genfix_src('Generator/CreateModelGenerator.php');
    // 修后:值按类型 int 裸写 / string 加引号 escape
    expect($src)->toContain("fn (\$v) => is_int(\$v) ? (string) \$v : \"'\" . \$this->escapePhpString((string) \$v) . \"'\"");
    // 旧裸拼形态必须消失
    expect($src)->not->toContain("implode(', ', array_keys(\$temp)) . '])'");
});

// ─── ③ API action name 走 quoteYamlString ──────────────────────────────────
it('③ CreateApiGenerator action name: 走 quoteYamlString + 外引号(不再裸写)', function () {
    $src = genfix_src('Generator/CreateApiGenerator.php');
    expect($src)->toContain("\"name: '\" . \$this->quoteYamlString(\$name) . \"'\"");
    // 旧裸拼形态消失
    expect($src)->not->toContain("'name: ' . \$name");
});

// ─── ⑤ ACL 标签不再 &apos; ─────────────────────────────────────────────────
it('⑤ UpdateAuthorizationGenerator 不再 str_replace 撇号成 &apos;(交给 VarExporter 正确转义)', function () {
    $src = genfix_src('Generator/UpdateAuthorizationGenerator.php');
    expect($src)->not->toContain("str_replace(\"'\", '&apos;'");
});

// ─── ④ i18n &apos; 也一并断言已清除(详细行为测试在 UpdateMultilingualGeneratorTest)──
it('④ UpdateMultilingualGenerator escapeLangValue 不再用 &apos;,改 escapePhpString', function () {
    $src = genfix_src('Generator/UpdateMultilingualGenerator.php');
    expect($src)->not->toContain("str_replace(\"'\", '&apos;'");
    expect($src)->toContain('return $this->escapePhpString($this->stringifyLangValue($value));');
});

// ─── ⑥ smallint/mediumint 整型漂移收口(2026-06-11 sweep,与 ① 同类)─────────────
// ① 之外,CreateModelGenerator 的 filter/factory/@property + CreateTSModelGenerator 的 TS 类型判断
// 同样漏 smallint/mediumint → 这些字段拿不到整型处理(无 filter scope / 错 factory / @property string / TS string)。
it('⑥ 整型判断列表都含 smallint/mediumint(filter/factory/@property/TS);UNSIGNED_DEFAULT 故意窄表不动', function () {
    $model = genfix_src('Generator/CreateModelGenerator.php');
    $ts    = genfix_src('Generator/CreateTSModelGenerator.php');

    // CreateModelGenerator 三处整型判断(filter whereIn / factory random_int / @property int)规整成全集
    expect(substr_count($model, "['tinyint', 'smallint', 'mediumint', 'int', 'bigint']"))->toBe(3);
    // decimal 补进 float-filter(与 float/double 同等待遇)
    expect($model)->toContain("['decimal', 'float', 'double']");
    // TS number 分支含 smallint/mediumint(bigint 仍走独立 'bigint | string' 分支,不并入)
    expect($ts)->toContain("['tinyint', 'smallint', 'mediumint', 'int']");

    // 锚:FreshStorageGenerator::getSize 的列表 = FieldTypes::UNSIGNED_DEFAULT,**故意**排除
    // smallint/mediumint/double(unsigned 默认窄表),不属本次 sweep,防未来"顺手补全"误改语义。
    expect(genfix_src('Generator/FreshStorageGenerator.php'))
        ->toContain("['int', 'bigint', 'tinyint', 'decimal', 'float']");
});
