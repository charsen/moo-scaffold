<?php declare(strict_types=1);

use Mooeen\Scaffold\Support\ConsoleUi;
use Mooeen\Scaffold\Utility;

/**
 * `Utility` 公开面锚点（2026-09-19，中/高风险队列第 3 项 · 阶段 3a）。
 *
 * `Utility` 是当时全仓**最后一个**「有状态服务（持 `Filesystem`）把静态方法混装」的类，也是公开成员最多的
 * 一个（45 = 1 构造器 + 42 实例 + 2 静态）。本项做了三处收缩，三处都要钉住 —— 它们都不会以任何失败的形式
 * 暴露自己，只能靠锚点挡住被无意识写回去：
 *   ① 只被类内调用的 3 个方法收成 `private`（公开面 44 → 41，全仓零外部调用点已核）；
 *   ② controller 名归一实现外迁 `Support\ControllerName`，`Utility` 上只留 `@deprecated` 转发
 *      ⇒ 新的**内部**调用点必须直调真源，否则废弃面会重新扩散；
 *   ③ `addGitIgnore()` 的输出出口收成 `ConsoleUi` 参数（原是无类型 `$command` + 内联 `new ConsoleUi`）。
 *
 * 转发与真源的**等价性**断言在 `ControllerSuffixNormalizeTest`（防照抄一份分叉实现），本文件只管结构面。
 */
it('Utility 的 3 个内部专用方法保持 private', function () {
    $rc = new ReflectionClass(Utility::class);

    foreach (['formatDisplayDate', 'getResourcePath', 'parseByLanguages'] as $name) {
        expect($rc->hasMethod($name))->toBeTrue();
        expect($rc->getMethod($name)->isPrivate())->toBeTrue();
    }
});

it('Utility 公开面不再增长（预算 41 个公开方法，不含构造器）', function () {
    $public = array_filter(
        (new ReflectionClass(Utility::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn (ReflectionMethod $m): bool => $m->getName() !== '__construct',
    );

    expect(count($public))->toBeLessThanOrEqual(41);
});

it('addGitIgnore 首参收 ConsoleUi（不再是「无类型 $command + 内联 new」）', function () {
    $params = (new ReflectionClass(Utility::class))->getMethod('addGitIgnore')->getParameters();

    expect($params)->toHaveCount(1);
    expect((string) $params[0]->getType())->toBe(ConsoleUi::class);
});

it('src/ 内部不再调用 Utility 上已废弃的两个后缀转发，一律直调 ControllerName', function () {
    $root = dirname(__DIR__, 3);

    $offenders = [];
    $callers   = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php' || $file->getFilename() === 'Utility.php') {
            continue;
        }

        $rel  = str_replace($root . '/', '', $file->getPathname());
        $code = (string) preg_replace('#^\s*(//|\*|/\*).*$#m', '', (string) file_get_contents($file->getPathname()));

        if (preg_match_all('/Utility::(stripControllerSuffix|ensureControllerSuffix)\(/', $code, $m)) {
            $offenders[] = $rel . ' → ' . implode(', ', array_unique($m[1]));
        }

        if (preg_match('/ControllerName::(strip|ensure)\(/', $code)) {
            $callers[] = $rel;
        }
    }

    expect($offenders)->toBe([], "以下文件仍在调 Utility 上已废弃的转发（应直调 ControllerName::strip()/ensure()）：\n  " . implode("\n  ", $offenders));

    // 正向锚点：确认扫描真的扫到了迁移后的真源调用，而不是文件集/正则坏了导致空过。
    expect($callers)->toContain(
        'src/Adder/ControllerAdder.php',
        'src/Designer/SchemaLoader.php',
        'src/Generator/CreateApiGenerator.php',
        'src/Generator/CreateControllerGenerator.php',
        'src/Generator/CreateViewGenerator.php',
        'src/Generator/FreshStorageGenerator.php',
        'src/Http/Controllers/RouteController.php',
    );
});
