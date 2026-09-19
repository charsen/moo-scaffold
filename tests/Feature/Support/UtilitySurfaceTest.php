<?php declare(strict_types=1);

use Mooeen\Scaffold\Support\ActionDoc;
use Mooeen\Scaffold\Support\ActionMeta;
use Mooeen\Scaffold\Support\ConsoleUi;
use Mooeen\Scaffold\Utility;

/**
 * `Utility` 公开面锚点（2026-09-19，中/高风险队列第 3 项 · 阶段 3a / 3b）。
 *
 * `Utility` 是当时全仓**最后一个**「有状态服务（持 `Filesystem`）把静态方法混装」的类，也是公开成员最多的
 * 一个（3a 开工时 45 = 1 构造器 + 42 实例 + 2 静态）。本项做了两批收缩，每处都要钉住 —— 它们都不会以
 * 任何失败的形式暴露自己，只能靠锚点挡住被无意识写回去：
 *   ①（3a）只被类内调用的 3 个方法收成 `private`；controller 名归一实现外迁 `Support\ControllerName`，
 *      `Utility` 上只留两行 `@deprecated` 转发；`addGitIgnore()` 的输出出口收成 `ConsoleUi` 参数。
 *   ②（3b）DOCMETA 组的 9 个纯函数外迁 `Support\ActionMeta`（归一/判废/菜单/后缀剥离）与
 *      `Support\ActionDoc`（docblock 与反射解析）—— 这批**不留转发**：它们是**实例**方法，
 *      留一行委托等于把 god-class 又撑回去，而「宿主引用 `Utility` 零处」已核（见 NOTES 2026-09-19 3a 条），
 *      漏改的内部调用点会以 `Call to undefined method` **响亮**失败，不会静默。⇒ 公开面 41 → 32。
 *
 * 转发与真源的**等价性**断言在 `ControllerSuffixNormalizeTest`（防照抄一份分叉实现）；
 * 外迁方法的**语义**断言在 `ActionMetaDocTest` / `ParseActionDescTest`；本文件只管结构面。
 */
it('Utility 上还剩的那个内部专用方法保持 private', function () {
    $rc = new ReflectionClass(Utility::class);

    expect($rc->getMethod('getResourcePath')->isPrivate())->toBeTrue();
});

it('3a/3b 外迁的方法在 Utility 上已不存在（不是 private、也不留转发）', function () {
    $rc = new ReflectionClass(Utility::class);

    // 3b 外迁到 ActionMeta / ActionDoc 的 9 个
    foreach ([
        'normalizeApiActionMeta', 'isApiActionDeprecated', 'normalizeMenusTransform', 'removeActionNameMethod',
        'parsePMCNames', 'parseActionInfo', 'parseActionName', 'parseActionDesc', 'getActionRequestClass',
    ] as $name) {
        expect($rc->hasMethod($name))->toBeFalse("Utility::{$name}() 应已不存在（真源外迁，不留转发）");
    }

    // 3a 外迁的两个纯函数（实现分别去了 ActionMeta::formatDate / ActionDoc::parseByLanguages 的 private）
    expect($rc->hasMethod('formatDisplayDate'))->toBeFalse();
    expect($rc->hasMethod('parseByLanguages'))->toBeFalse();

    // 正向锚点：同批次必须**留在这儿**的邻居还在 —— 否则「有人把 Utility 删空了」也能让上面全绿。
    foreach (['parseYamlFile', 'getConfig', 'targetContext', 'getTables', 'addGitIgnore', 'getResourcePath'] as $kept) {
        expect($rc->hasMethod($kept))->toBeTrue("Utility::{$kept}() 不该被删（它不在本批外迁清单里）");
    }

    // 真源确实在对面
    expect(class_exists(ActionMeta::class))->toBeTrue();
    expect(class_exists(ActionDoc::class))->toBeTrue();
});

it('Utility 公开面不再增长（预算 32 个公开方法，不含构造器）', function () {
    $public = array_filter(
        (new ReflectionClass(Utility::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn (ReflectionMethod $m): bool => $m->getName() !== '__construct',
    );

    expect(count($public))->toBeLessThanOrEqual(32);
});

it('addGitIgnore 首参收 ConsoleUi（不再是「无类型 $command + 内联 new」）', function () {
    $params = (new ReflectionClass(Utility::class))->getMethod('addGitIgnore')->getParameters();

    expect($params)->toHaveCount(1);
    expect((string) $params[0]->getType())->toBe(ConsoleUi::class);
});

it('src/ 内部不再经 Utility 调已外迁的 9 个方法，一律直调 ActionMeta / ActionDoc', function () {
    $root = dirname(__DIR__, 3);

    $moved = [
        'normalizeApiActionMeta', 'isApiActionDeprecated', 'normalizeMenusTransform', 'removeActionNameMethod',
        'parsePMCNames', 'parseActionInfo', 'parseActionName', 'parseActionDesc', 'getActionRequestClass',
    ];
    // 三种取用 Utility 的写法都要覆盖：静态调用 / 注入属性 / 容器定位
    $pattern = '/(?:\\\\Utility::|Utility::|\$this->utility->|app\(Utility::class\)->)('
        . implode('|', $moved) . ')\(/';

    $offenders = [];
    $callers   = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php' || $file->getFilename() === 'Utility.php') {
            continue;
        }

        $rel  = str_replace($root . '/', '', $file->getPathname());
        $code = (string) preg_replace('#^\s*(//|\*|/\*).*$#m', '', (string) file_get_contents($file->getPathname()));

        if (preg_match_all($pattern, $code, $m)) {
            $offenders[] = $rel . ' → ' . implode(', ', array_unique($m[1]));
        }

        if (preg_match('/(?:ActionMeta|ActionDoc)::\w+\(/', $code)) {
            $callers[] = $rel;
        }
    }

    expect($offenders)->toBe([], "以下文件仍经 Utility 调已外迁的方法（应直调 ActionMeta::/ActionDoc::）：\n  " . implode("\n  ", $offenders));

    // 正向锚点：确认扫描真的扫到了迁移后的真源调用，而不是文件集/正则坏了导致空过。
    expect($callers)->toContain(
        'src/Generator/CreateApiGenerator.php',
        'src/Generator/UpdateAuthorizationGenerator.php',
        'src/Http/Controllers/RouteController.php',
        'src/Http/Controllers/ApiController.php',
    );
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
