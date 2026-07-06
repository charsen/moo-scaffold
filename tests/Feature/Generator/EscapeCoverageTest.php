<?php declare(strict_types=1);

/**
 * plan-40 §二 escape 覆盖度 — 4 P0 漏修证据锁定 test
 *
 * 跟 40-addendum-escape-coverage-audit.md 的 F6 / F7 / F9a / F9b 配对。
 * 当前**红**,因为 generator 源码这 4 个槽位都没调 escape helper。
 * 修法落地后变**绿**(`'{$x}'` → `'" . $this->escapePhpString($x) . "'"` 之类)。
 *
 * 测试套路:源码 regex 扫描 generator 文件,断言关键拼接行**应该**包含
 * escapePhpString / quoteYamlString 调用。比真机投毒 + 跑 moo:free 跑得快
 * (毫秒级),且不需要 Testbench DB / fixture。
 *
 * 不写 it(),全用 test() — 每个 P0 独立一条,失败时输出明确 hint。
 */

/* ---------------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------------ */

/**
 * @return list<string>
 */
function findLinesMatching(string $relPath, string $needle): array
{
    $abs = __DIR__ . '/../../../src/' . ltrim($relPath, '/');
    expect(is_file($abs))->toBeTrue("source file not found: {$relPath}");

    $hits  = [];
    $lines = file($abs, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $i => $line) {
        if (str_contains($line, $needle)) {
            $hits[] = sprintf('line %d: %s', $i + 1, trim($line));
        }
    }

    return $hits;
}

function lineContainsEscapeCall(string $line, string $varExpr, string $helper): bool
{
    return str_contains($line, $helper . '(' . $varExpr) || str_contains($line, '$this->' . $helper);
}

/* ---------------------------------------------------------------------------
 * F6 · CreateApiGenerator controller name/class YAML 拼接 — P0 漏修
 *
 * 期望:'name: ' / 'class: ' 拼接 controllerDisplayName / controllerData['class']
 *       时走 quoteYamlString,否则换行 / 单引号会撕裂 yaml。
 * 当前:CreateApiGenerator.php:326-332 裸拼,未走 escape。
 * ------------------------------------------------------------------------ */

test('F6 · CreateApiGenerator controller name/class 拼到 yaml 应走 quoteYamlString', function () {
    $hits = findLinesMatching('Generator/CreateApiGenerator.php', "'name: '.\$controllerDisplayName");
    expect($hits)->toBeEmpty(
        '应该走 quoteYamlString — 找到裸拼:' . PHP_EOL . implode(PHP_EOL, $hits)
    );
});

test('F6 · CreateApiGenerator class: 行应走 quoteYamlString', function () {
    $hits = findLinesMatching('Generator/CreateApiGenerator.php', "'class: '.trim((string)");
    expect($hits)->toBeEmpty(
        '应该走 quoteYamlString — 找到裸拼:' . PHP_EOL . implode(PHP_EOL, $hits)
    );
});

/* ---------------------------------------------------------------------------
 * F7 · CreateApiGenerator _menus_transform.yaml 拼接 — P0 漏修
 *
 * 期望:foreach existing key/name 拼 yaml 时走 quoteYamlString。
 * 当前:line 945-947 用 "'{$key}':" / "name: '{$name}'" 裸插。
 * ------------------------------------------------------------------------ */

test('F7 · _menus_transform yaml key 拼接应走 quoteYamlString', function () {
    $hits = findLinesMatching('Generator/CreateApiGenerator.php', '"\'{$key}\':"');
    expect($hits)->toBeEmpty(
        '应该 \'.quoteYamlString($key).\' — 找到裸插:' . PHP_EOL . implode(PHP_EOL, $hits)
    );
});

test('F7 · _menus_transform yaml name 拼接应走 quoteYamlString', function () {
    $hits = findLinesMatching('Generator/CreateApiGenerator.php', "\"name: '{\$name}'\"");
    expect($hits)->toBeEmpty(
        '应该走 quoteYamlString($name) — 找到裸插:' . PHP_EOL . implode(PHP_EOL, $hits)
    );
});

/* ---------------------------------------------------------------------------
 * F9a · CreateModelGenerator bcmul/bcdiv 浮点转整 — P0 RCE
 *
 * 期望:`bcmul((string)$value, '{$divisor}', 0)` 的 $divisor 来自
 *       explode(':', $attr['format'])[1],format 不校验内容
 *       → 必须走 escapePhpString。
 *       payload: format: 100');system('id');//
 * 当前:line 648 / 654 裸插。
 * ------------------------------------------------------------------------ */

test('F9a · CreateModelGenerator bcmul divisor 应走 escapePhpString(format RCE)', function () {
    $hits = findLinesMatching('Generator/CreateModelGenerator.php', "bcmul((string)\\\$value, '{\$divisor}'");
    expect($hits)->toBeEmpty(
        '应该 escapePhpString($divisor) — 找到裸插(format 字段 RCE):' . PHP_EOL . implode(PHP_EOL, $hits)
    );
});

test('F9a · CreateModelGenerator bcdiv divisor 应走 escapePhpString', function () {
    $hits = findLinesMatching('Generator/CreateModelGenerator.php', "bcdiv((string)\\\$value, '{\$divisor}'");
    expect($hits)->toBeEmpty(
        '应该 escapePhpString($divisor) — 找到裸插:' . PHP_EOL . implode(PHP_EOL, $hits)
    );
});

/* ---------------------------------------------------------------------------
 * F9b · CreateModelGenerator $attributes default 注入 — P0
 *
 * 期望:`"'{$v['default']}'"` 应走 escapePhpString。
 *       payload: default: x');evil();//
 * 当前:line 694 三元尾枝裸插。
 * ------------------------------------------------------------------------ */

test('F9b · CreateModelGenerator $attributes default 应走 escapePhpString', function () {
    $hits = findLinesMatching('Generator/CreateModelGenerator.php', "\"'{\$v['default']}'\"");
    expect($hits)->toBeEmpty(
        '应该 escapePhpString($v[\'default\']) — 找到裸插($attributes 注入):' . PHP_EOL . implode(PHP_EOL, $hits)
    );
});

/* ---------------------------------------------------------------------------
 * F9d · CreateModelGenerator docblock @property attr.name 注入 — P1 防御纵深
 *
 * 期望:` * @property X $y {attr.name}` 槽位过 sanitizeDocblock,strip `*\/` 防 docblock 闭合。
 * 当前:CreateModelGenerator.php:528 attr.name 来自 yaml 任意中文,不 sanitize 攻击者可伪造方法注解。
 * ------------------------------------------------------------------------ */

test('F9d · CreateModelGenerator @property attr.name 应走 sanitizeDocblock', function () {
    // 裸拼 pattern:`".($attr['name']` 直接进 docblock 拼接,无 sanitize 包裹
    $hits = findLinesMatching('Generator/CreateModelGenerator.php', "\".(\$attr['name']");
    expect($hits)->toBeEmpty(
        '应该 $this->sanitizeDocblock($attr[\'name\'] ?? $field_name) — 找到裸拼:' . PHP_EOL . implode(PHP_EOL, $hits)
    );
});

/* ---------------------------------------------------------------------------
 * F8 · CreateControllerGenerator field_name / entity PHP 字面量槽位 — P1 防御纵深
 * ------------------------------------------------------------------------ */

test('F8 · CreateControllerGenerator getInEnums field_name 应走 escapePhpString', function () {
    $hits = findLinesMatching('Generator/CreateControllerGenerator.php', "getValues('{\$field_name}')");
    expect($hits)->toBeEmpty(
        '应该 $this->escapePhpString($field_name) — 找到裸拼:' . PHP_EOL . implode(PHP_EOL, $hits)
    );
});

test('F8 · CreateControllerGenerator getUnique field_name 应走 escapePhpString', function () {
    $hits = findLinesMatching('Generator/CreateControllerGenerator.php', "getTable(), '{\$field_name}'");
    expect($hits)->toBeEmpty(
        '应该 $this->escapePhpString($field_name) — 找到裸拼:' . PHP_EOL . implode(PHP_EOL, $hits)
    );
});

test('F8 · CreateControllerGenerator Route::iResource entity 应走 escapePhpString', function () {
    $hits = findLinesMatching('Generator/CreateControllerGenerator.php', "Route::iResource('\" . \$item['entity']");
    expect($hits)->toBeEmpty(
        '应该 escapePhpString($item[\'entity\']) — 找到裸拼:' . PHP_EOL . implode(PHP_EOL, $hits)
    );
});

/* ---------------------------------------------------------------------------
 * F9c · CreateModelGenerator buildEnum string item[0] 应走 escapePhpString — P1
 * ------------------------------------------------------------------------ */

test('F9c · CreateModelGenerator buildEnum string item[0] 应走 escapePhpString', function () {
    $hits = findLinesMatching('Generator/CreateModelGenerator.php', "case {\$new_alias} = '{\$item[0]}'");
    expect($hits)->toBeEmpty(
        '应该 escapePhpString($item[0]) — 找到裸拼(enum case PHP 字面量逃逸):' . PHP_EOL . implode(PHP_EOL, $hits)
    );
});

/* ---------------------------------------------------------------------------
 * SchemaLoader 入口 sanitize · enum value / default / format — P1
 * ------------------------------------------------------------------------ */

test('SchemaLoader::applyEnums 应对 string value 走 sanitizeEnumLabel', function () {
    $abs = __DIR__ . '/../../../src/Designer/SchemaLoader.php';
    $src = file_get_contents($abs);
    // applyEnums 写 entries 前应 sanitize value(标志:value sanitize 注释 + sanitizeEnumLabel($rawVal) 调用)
    expect($src)->toContain('plan-40 §二 P1 防御纵深:enum value sanitize');
    expect($src)->toContain('$this->sanitizeEnumLabel($rawVal)');
});

test('SchemaLoader::sanitizeFieldAttrs 应对 default + format 内容 sanitize', function () {
    $abs = __DIR__ . '/../../../src/Designer/SchemaLoader.php';
    $src = file_get_contents($abs);
    expect($src)->toContain("\$key === 'default' && is_string(\$value)");
    expect($src)->toContain("\$key === 'format' && is_string(\$value)");
    // format strict regex
    expect($src)->toContain('/^[a-z]+(?::[0-9,]+)?$/');
});

/* ---------------------------------------------------------------------------
 * 锚点:escape helper API 不可变(防误删 / 重命名导致测试假绿)
 * ------------------------------------------------------------------------ */

test('escapePhpString / quoteYamlString / sanitizeDocblock helper 存在于 Generator base class', function () {
    $abs = __DIR__ . '/../../../src/Generator/Generator.php';
    $src = file_get_contents($abs);
    expect($src)->toContain('protected function escapePhpString');
    expect($src)->toContain('protected function quoteYamlString');
    expect($src)->toContain('protected function sanitizeDocblock');
});
