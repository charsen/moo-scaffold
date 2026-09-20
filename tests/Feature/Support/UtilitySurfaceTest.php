<?php declare(strict_types=1);

use Mooeen\Scaffold\Support\ActionDoc;
use Mooeen\Scaffold\Support\ActionMeta;
use Mooeen\Scaffold\Support\ConsoleUi;
use Mooeen\Scaffold\Support\Paths;
use Mooeen\Scaffold\Support\StorageRegistry;
use Mooeen\Scaffold\Utility;

/**
 * `Utility` 公开面锚点（2026-09-19，中/高风险队列第 3 项 · 阶段 3a / 3b / 3b-2 / 3b-3）。
 *
 * `Utility` 曾是全仓**最后一个**「有状态服务（持 `Filesystem`）把静态方法混装」的类，也是公开成员最多的
 * 一个（3a 开工时 45 = 1 构造器 + 42 实例 + 2 静态）。四批收缩都**不会以任何失败的形式暴露自己**，
 * 只能靠锚点挡住被无意识写回去：
 *   ①（3a）只被类内调用的 3 个方法收成 `private`；controller 名归一外迁 `Support\ControllerName`，
 *      `Utility` 上留两行 `@deprecated` 转发；`addGitIgnore()` 的输出出口收成 `ConsoleUi` 参数。
 *   ②（3b）DOCMETA 组的 9 个纯函数外迁 `Support\ActionMeta`（归一/判废/菜单/后缀剥离）与
 *      `Support\ActionDoc`（docblock 与反射解析）—— 这批**不留转发**：它们是**实例**方法，
 *      留一行委托等于把 god-class 又撑回去，而「宿主引用 `Utility` 零处」已核（见 NOTES 2026-09-19 3a 条）。
 *   ③（3b-2）PATHS 组的 11 个路径方法 + `formatNameSpace` 外迁 `Support\Paths`（同一个类的静态方法；
 *      方法体逐字未动，只换接收者与命名）—— 同样不留转发，理由同上；`isApiFileExist()` 留在 `Utility`，
 *      因为它做的是「解析 + 断言文件存在」，存在性检查是 IO，不属于纯解析。
 *      ⇒ 公开面 41 → 22（20 个真邻居 + 2 个 3a 转发），`Utility` 自此**再无私有方法**。
 *   ④（3b-3）REGISTRY 组的 9 个「读 `storage/scaffold/*.php` 聚合缓存」的方法外迁
 *      `Support\StorageRegistry`（同样 `final` + 全静态、同样去 `get*` 前缀、同样不留转发）。
 *      原计划写的「14 个读方法」是把三件事拼在一起了 —— 真正可切的只有这 9 个
 *      （`getLangFields` 读的是 schema YAML、4 个 app-target 方法本就已委托 `AppTargetRegistry`）。
 *      ⇒ 公开面 22 → **13**（11 个真邻居 + 2 个 3a 转发）。
 *
 * 转发与真源的**等价性**断言在 `ControllerSuffixNormalizeTest`（防照抄一份分叉实现）；
 * 外迁方法的**语义**断言在 `ActionMetaDocTest` / `ParseActionDescTest` / `PathsTest` /
 * `StorageRegistryTest`；本文件只管结构面。
 */

/**
 * 四批外迁出去、因此**不得**再出现在 `Utility` 上的方法名（旧名）。
 *
 * 分成两组，是因为**注释面**的判据不同（见 `utility_old_names_in_comments()` 与白名单的说明）：
 *   - `UTILITY_RENAMED_METHODS`：名字**也变了** ⇒ 旧名在全仓已不存在，注释里出现旧名就是文档漂移。
 *   - `UTILITY_RELOCATED_METHODS`：只是**换宿主**、名字没变（`ActionDoc::parsePMCNames()` 仍叫这个名）
 *     ⇒ 注释里出现它是正常的（读者照着它能找到方法），不该判红。
 * 两者的**并集**才是「`Utility` 上不得再有」+「代码里不得再经 `Utility` 调」的查询口径。
 */
const UTILITY_RENAMED_METHODS = [
    // 3a：原 private，实现去了 ActionMeta::formatDate ⇒ 改名了
    'formatDisplayDate',
    // 3b：DOCMETA 里**改了名**的 4 个（归一 / 判废 / 菜单 / 后缀剥离）
    'normalizeApiActionMeta', 'isApiActionDeprecated', 'normalizeMenusTransform', 'removeActionNameMethod',
    // 3b-2：PATHS 11 个（去掉与类名重复的 `get*Path` 前缀）
    'getModelPath', 'getResourcePath', 'getAppResourcePath', 'getControllerPath', 'getMigrationPath',
    'getStoragePath', 'getApiPath', 'getAclPath', 'getDatabasePath', 'getSchemaPath', 'formatNameSpace',
    // 3b-3：REGISTRY 8 个（去掉 `get` 前缀，与 PATHS 同批规则）⇒ `StorageRegistry::table()` 等
    'getOneTable', 'getTables', 'getModels', 'getModelIds', 'getControllers',
    'getFields', 'getEnums', 'getEnumWords',
];

/**
 * 只是换宿主、**名字没变**的那些 —— 注释里提它们是正常的（读者照着这个名字能找到方法）。
 *
 * 3b-3 往这里只加了 1 个：`dictionaryStats()` 本身就是名词形态、没改名 ⇒ 它虽已搬去
 * `StorageRegistry`，注释里写 `dictionaryStats()` 仍然是**对的**。**这正是分组判据的价值**：
 * 若把它塞进上面那个组，注释面锚点就会去追一些本来正确的散文。
 */
const UTILITY_RELOCATED_METHODS = [
    'parseByLanguages',
    'parsePMCNames', 'parseActionInfo', 'parseActionName', 'parseActionDesc', 'getActionRequestClass',
    'dictionaryStats',
];

/** 上面两组的并集。 */
const UTILITY_MOVED_METHODS = [...UTILITY_RENAMED_METHODS, ...UTILITY_RELOCATED_METHODS];

/**
 * 3a 有意**保留**的两个 `@deprecated` 静态转发（与 3b / 3b-2 相反，它们是静态方法、转发成本一行）。
 * 这里钉「它们还在」—— 删掉是另一件事，得连 `ControllerSuffixNormalizeTest` 的等价性断言一起改。
 */
const UTILITY_DEPRECATED_FORWARDS = ['stripControllerSuffix', 'ensureControllerSuffix'];

/** 未在任一外迁清单里、必须**留在这儿**的邻居（正向锚点用，防「有人把 Utility 删空」也全绿）。 */
const UTILITY_KEPT_METHODS = [
    'getConfig', 'resolveCurrentLoginUser', 'parseYamlFile', 'addGitIgnore', 'isApiFileExist',
    'targetContext', 'getApps', 'getAppTargets', 'getExtraModules', 'getControllerNamespaces',
    'getLangFields',
];

/** 外迁方法的**四个新宿主**（短类名）—— 这些接收者上的旧方法名是迁移结果，不是漏改。 */
const UTILITY_MOVED_METHOD_HOSTS = ['ActionDoc', 'ActionMeta', 'Paths', 'StorageRegistry'];

/** 四个新宿主各自的文件（`self::` / `static::` 只在**自己**文件里才可能指新宿主）。 */
const UTILITY_MOVED_METHOD_HOST_FILES = ['ActionDoc.php', 'ActionMeta.php', 'Paths.php', 'StorageRegistry.php'];

/**
 * 允许在**注释里**提到**已改名**旧方法名的文件白名单 —— 其余文件里出现旧名就是文档漂移。
 *
 * 为什么需要这条：结构锚点扫的是**代码**（且故意剥掉注释与字符串，见 `utility_scannable_code()`），
 * 所以**注释里的旧名它永远看不见**。2026-09-19 3b-2 收尾时全仓扫了一遍注释，逮到 6 处：
 * `tests/TestCase.php`（`Utility::getDatabasePath`）、`src/Adder/Adder.php` + `tests/.../AdderTest.php`
 * （`formatNameSpace`）、两个 HTTP 测试（`getApiPath`）、`AclDocumentLoaderTest`（`getAclPath`）——
 * 它们都拿旧名当**现役方法**在解释行为，而那个方法已经不存在了。**注释是给人读的**，
 * 人照着它去找方法会找不到（比没有注释更坏）。
 *
 * 白名单是**刻意讲迁移映射**的地方（「旧名 → 新名」的对照表 / 锚点自身的 fixture 说明 /
 * `TargetContextTest` 里「对照侧已换成 Paths」的交代），提旧名是本职，所以放行。
 * 想在新地方提旧名 ⇒ 先往这里登记一次（一次有意识的动作，而不是静默漂移）。
 *
 * **注意 `src/Support/ActionDoc.php` 不在白名单里、也不需要** —— 它那张表映射的是
 * `UTILITY_RELOCATED_METHODS`（名字没变），压根不会被这条锚点扫到。
 *
 * 3b-3 追加 `src/Support/StorageRegistry.php`：与 `Paths.php` 同理，它是**旧名 → 新名**的映射表
 * （`getOneTable`→`table()` 那一串），提旧名正是本职。同批它还在注释面里逮到 **8 处漂移**，
 * 处理方式分两类、**都没进白名单**：
 *   - 7 处是真漂移（`ScaffoldController` / `ScaffoldDashboardTest` / `CreateViewGeneratorTest` /
 *     `UpdateMultilingualGeneratorTest` / `GeneratorFixesTest` / `CreateResourceGeneratorTest` /
 *     `UniqueSemanticsTest`）—— 已就地把旧名换成新宿主。最典型的是 `UniqueSemanticsTest` 那句
 *     「内部调 `$this->utility->getModelIds()`」，方法走了以后这句话是**直接错的**。
 *   - 1 处是**名字撞车**（`AdderTest` 那行注释指的是 `AdderCommand::getControllers()`，与已外迁的
 *     `Utility::getControllers()` 只是重名）—— 没有改名、也没有豁免，而是把那行注释改成
 *     「AdderCommand 自己的控制器列表扫描」，并补一句「不是 StorageRegistry 那个」。
 *     ⇒ 白名单只收「刻意讲迁移映射」的，不被这类误报稀释。
 */
const UTILITY_OLD_NAME_DOC_ALLOWLIST = [
    'src/Support/Paths.php',
    'src/Support/ActionMeta.php',
    'src/Support/StorageRegistry.php',
    'tests/Feature/Support/PathsTest.php',
    'tests/Feature/Support/UtilitySurfaceTest.php',
    'tests/Feature/Support/TargetContextTest.php',
];

/**
 * 供锚点扫描的**代码文本**：剥掉注释与字符串字面量。锚点必须看这个，不能拿正则逐行剥 ——
 * ① **注释**里提到旧方法名（NOTES / 文档注释里很多）不该算违规；而「整行以 `//` 开头」的剥法
 *    又漏不掉**行尾注释**（`$x = f(); // 用 getModelPath`），会把同行真实代码一起漏掉。
 * ② **字符串字面量**里的方法名更不是调用 —— 本文件新增的那条回归守卫自己就写着
 *    `'$u->getModelPath();'` 这样的 fixture 字符串；只看注释的话，锚点会被自己的 fixture 判红。
 *    与 `ReadonlyModeTest` / `PathsTest` 同法：`token_get_all` 按 token 类型丢。
 */
function utility_scannable_code(string $path): string
{
    $dropped = [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE];

    $code = '';

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token)) {
            if (! in_array($token[0], $dropped, true)) {
                $code .= $token[1];
            }

            continue;
        }

        $code .= $token;
    }

    return $code;
}

/**
 * 在一段已剥注释的源码里，找出**接收者既不是四个新宿主、也不是新宿主自引用**的「旧方法名调用」。
 *
 * 为什么必须连**接收者**一起看 —— 两头都会出错：
 *   ① 只按方法名扫（`/(?:->|::)\s*(name)\s*\(/`）：新宿主**继承了同名方法**
 *      （`ActionDoc::parsePMCNames()` 就是原 `Utility::parsePMCNames()`），于是正确的新调用被一起判红；
 *   ② 只锚 `Utility::` / `$this->utility->` / `app(Utility::class)->`：会漏掉**变量接收者**。
 *      3b-2 实测踩过 —— `tests/.../TargetContextTest.php` 里的 `$u->getModelPath()` 因漏检而漏改，
 *      锚点全绿、直到运行期 `Call to undefined method Utility::getModelPath()` 才炸。
 *
 * 所以放行的应当是「谁在调」（宿主身份），而不是「调什么」（方法名）。
 *
 * ③（3b-3 补）接收者是**裸 `$this` / `self` / `static`**（即「本文件的类自己」）时，再补一层判据：
 *    若**同一段源码里定义**了同名方法，那它调的是**自己的**同名方法 —— `AdderCommand::getControllers($path, $folder)`
 *    与 `Utility::getControllers(bool $merge_all)` 只是**重名**，不是漏改。没有这层判据这条锚点会误伤它
 *    （3b-3 实测红过）。注意这不削弱覆盖面：**漏改的真实形态是 `$this->utility->xxx()` / `$u->xxx()` /
 *    `Utility::xxx()`**，它们的接收者不是裸 `$this`，照旧一律判红。
 *
 * @return list<string> 命中的旧方法名（去重、保持出现顺序）
 */
function utility_stale_moved_calls(string $code, string $fileName): array
{
    // 接收者：显式 `app(Utility::class)`（写在最前，免得被类名分支先吃掉）、
    // 带命名空间的类名、`$this->prop`、以及任何 `$var`（变量接收者必须可见，见上文 ②）。
    $receiver = 'app\(Utility::class\)'
        . '|\\\\?[A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)*'
        . '|\$this->\w+'
        . '|\$\w+';

    $pattern = '/(?<![\w$\\\\])(' . $receiver . ')\s*(?:->|::)\s*('
        . implode('|', UTILITY_MOVED_METHODS) . ')\s*\(/';

    if (! preg_match_all($pattern, $code, $matches, PREG_SET_ORDER)) {
        return [];
    }

    $selfIsNewHost = in_array($fileName, UTILITY_MOVED_METHOD_HOST_FILES, true);
    $stale         = [];

    foreach ($matches as $hit) {
        $recv = $hit[1];

        if (($pos = strrpos($recv, '\\')) !== false) {
            $recv = substr($recv, $pos + 1);   // 取短类名：`\Mooeen\…\Utility` → `Utility`
        }

        if (in_array($recv, UTILITY_MOVED_METHOD_HOSTS, true)) {
            continue;   // 新宿主自身：迁移结果
        }

        if ($selfIsNewHost && ($recv === 'self' || $recv === 'static')) {
            continue;   // 新宿主内部的 `self::`（如 ActionDoc 的私有 `parseByLanguages`）
        }

        // 见上文 ③：裸 `$this` / `self` / `static`（`this` 是被 `\$\w+` 吃掉 `$` 后的归一形态）
        if (in_array($recv, ['$this', 'this', 'self', 'static'], true)
            && preg_match('/function\s+' . preg_quote($hit[2], '/') . '\s*\(/', $code) === 1) {
            continue;   // 同一段代码里定义了这个名字 ⇒ 调的是自己的方法，不是漏改
        }

        if (! in_array($hit[2], $stale, true)) {
            $stale[] = $hit[2];
        }
    }

    return $stale;
}

/**
 * **只取注释**（含 docblock）里的文本，找出其中提到的**已改名**方法名。
 *
 * 与 `utility_scannable_code()` 正好互补：那个要**丢掉**注释（注释里提旧名不算违规 —— 讲迁移时必然要提），
 * 这个**只留**注释。理由是同一个事实的两面：注释不是代码，所以它不是「漏改」；
 * 但注释是**给人读的文档**，拿旧名当现役用法解释行为，人照它找方法就会找不到 ⇒ 那是文档漂移。
 *
 * **只查 `UTILITY_RENAMED_METHODS`，不查 `UTILITY_RELOCATED_METHODS`** —— 后者名字没变、仍在对面存在
 * （`ActionDoc::parsePMCNames()` 就是同一个名字），读者照着它能找到方法，所以不是漂移。
 * 这正是「判据要按事实分家」而不是「按清单一把扫」的地方：第一版把两组一起扫，
 * 立刻把 `ActionDoc.php` 的映射表与三个测试里对**现役** `parseActionDesc` 的正常描述全判了红。
 *
 * @return list<string> 命中的已改名方法名（去重、保持 UTILITY_RENAMED_METHODS 的顺序）
 */
function utility_old_names_in_comments(string $path): array
{
    $comments = '';

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $comments .= $token[1] . "\n";
        }
    }

    if ($comments === '') {
        return [];
    }

    $hits = [];

    foreach (UTILITY_RENAMED_METHODS as $name) {
        // 方法名都是 字母/数字 组成，`\b` 够用；用 preg_quote 免得将来有人加进带正则元字符的名字
        if (preg_match('/\b' . preg_quote($name, '/') . '\b/', $comments) === 1) {
            $hits[] = $name;
        }
    }

    return $hits;
}

it('四批外迁的方法在 Utility 上已不存在（不是 private、也不留转发）', function () {
    $rc = new ReflectionClass(Utility::class);

    foreach (UTILITY_MOVED_METHODS as $name) {
        expect($rc->hasMethod($name))->toBeFalse("Utility::{$name}() 应已不存在（真源外迁，不留转发）");
    }

    // 3a 的两个静态转发相反，必须**还在**（删它们要连等价性断言一起改，是有意识的动作）
    foreach (UTILITY_DEPRECATED_FORWARDS as $name) {
        expect($rc->hasMethod($name))->toBeTrue("Utility::{$name}() 是 3a 有意保留的转发，不该被删")
            ->and($rc->getMethod($name)->isStatic())->toBeTrue();
    }

    // 正向锚点：同批次必须**留在这儿**的邻居还在 —— 否则「有人把 Utility 删空了」也能让上面全绿。
    foreach (UTILITY_KEPT_METHODS as $kept) {
        expect($rc->hasMethod($kept))->toBeTrue("Utility::{$kept}() 不该被删（它不在任何外迁清单里）");
    }

    // 四个真源确实在对面
    expect(class_exists(Paths::class))->toBeTrue()
        ->and(class_exists(ActionMeta::class))->toBeTrue()
        ->and(class_exists(ActionDoc::class))->toBeTrue()
        ->and(class_exists(StorageRegistry::class))->toBeTrue();

    // 3b-3 的 9 个「确实搬到了对面」+ **新名字**确实存在 —— 只断言「旧名没了」是不够的：
    // 把 Utility 上这 9 个连同 StorageRegistry 一起删光，上面那圈也全绿。
    foreach (['table', 'tables', 'models', 'modelIds', 'controllers', 'fields', 'enums', 'enumWords', 'dictionaryStats'] as $new) {
        expect((new ReflectionClass(StorageRegistry::class))->hasMethod($new))->toBeTrue("StorageRegistry::{$new}() 应存在（REGISTRY 组的真源）");
    }

    // 外迁后 `Utility` 不该再留任何私有方法（3a 收成 private 的那 3 个已在 3b / 3b-2 全部离开）
    expect($rc->getMethods(ReflectionMethod::IS_PRIVATE))->toBe([]);
});

it('Utility 公开面不再增长（预算 13 个公开方法，不含构造器）', function () {
    $public = array_filter(
        (new ReflectionClass(Utility::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn (ReflectionMethod $m): bool => $m->getName() !== '__construct',
    );

    // 预算不能靠「清单少写了几项」蒙对：留在这儿的那批必须一个不少，否则上面这条会假绿。
    expect(count(UTILITY_KEPT_METHODS) + count(UTILITY_DEPRECATED_FORWARDS))->toBe(13)
        ->and(count($public))->toBeLessThanOrEqual(13);
});

it('addGitIgnore 首参收 ConsoleUi（不再是「无类型 $command + 内联 new」）', function () {
    $params = (new ReflectionClass(Utility::class))->getMethod('addGitIgnore')->getParameters();

    expect($params)->toHaveCount(1);
    expect((string) $params[0]->getType())->toBe(ConsoleUi::class);
});

it('src/ 与 tests/ 里，除四个新宿主外不再有对已外迁方法名的调用', function () {
    $root = dirname(__DIR__, 3);

    $offenders = [];
    $callers   = [];

    foreach (['/src', '/tests'] as $dir) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . $dir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $rel  = str_replace($root . '/', '', $file->getPathname());
            $code = utility_scannable_code($file->getPathname());

            // 接收者感知（不是「接收者无关」）：新宿主继承同名方法，按名字扫必然自伤；
            // 判定逻辑与踩坑记录见 utility_stale_moved_calls() 的文档注释。
            if (($stale = utility_stale_moved_calls($code, $file->getFilename())) !== []) {
                $offenders[] = $rel . ' → ' . implode(', ', $stale);
            }

            if (preg_match('/(?:ActionMeta|ActionDoc|Paths|StorageRegistry)::\w+\(/', $code)) {
                $callers[] = $rel;
            }
        }
    }

    expect($offenders)->toBe([], "以下文件仍在调已外迁的方法（真源是 ActionMeta:: / ActionDoc:: / Paths:: / StorageRegistry::）：\n  " . implode("\n  ", $offenders));

    // 正向锚点：确认扫描真的扫到了外迁后的真源调用，而不是文件集/正则坏了导致空过。
    // 四个真源各取几个代表文件（`Paths` 侧特意含 `Utility.php` 自己与 tests 里的变量接收者那处）。
    foreach ([
        'src/Generator/CreateApiGenerator.php',
        'src/Generator/UpdateAuthorizationGenerator.php',
        'src/Http/Controllers/ApiController.php',
        'src/Http/Controllers/RouteController.php',
        'src/Generator/CreateControllerGenerator.php',
        'src/Generator/FreshStorageGenerator.php',
        'src/Support/AclDocumentLoader.php',
        'src/Utility.php',
        'tests/Feature/Support/TargetContextTest.php',
        // 3b-3：REGISTRY 侧的代表 —— 生成器、命令基类（那两处**绕开 Utility 直读**的收口）、
        // 后台控制器、以及包内唯一的**非基类**消费者 SchemaLoader
        'src/Support/StorageRegistry.php',
        'src/Generator/UpdateMultilingualGenerator.php',
        'src/Command/Command.php',
        'src/Http/Controllers/ScaffoldController.php',
        'src/Designer/SchemaLoader.php',
    ] as $mustCall) {
        expect($callers)->toContain($mustCall);
    }
});

it('上一条锚点的正则看得见真实世界的四种接收者写法（3b-2 漏检的回归守卫）', function () {
    // 上面那条锚点的价值全在「它能不能看见真实写法」—— 看不见的写法就是它的盲区，
    // 而 3b-2 的漏检恰恰发生在盲目区里（`$u->getModelPath()` 漏检 ⇒ 漏改 ⇒ 运行期才炸）。
    // 这里钉住「四种接收者都必须被看见」：若有人把正则收窄回白名单接收者，本测试立刻红。
    $stale = static fn (string $code, string $file = 'X.php'): array => utility_stale_moved_calls($code, $file);

    // ① 属性接收者 ② 变量接收者（3b-2 踩的就是这个）③ 静态类名 ④ app() 解析
    expect($stale('$this->utility->getModelPath();'))->toBe(['getModelPath'])
        ->and($stale('$u->getModelPath();'))->toBe(['getModelPath'])
        ->and($stale('Utility::getApiPath();'))->toBe(['getApiPath'])
        ->and($stale('app(Utility::class)->getSchemaPath();'))->toBe(['getSchemaPath'])
        // 带命名空间的写法同样要看见（短类名归一后仍是 Utility）
        ->and($stale('\Mooeen\Scaffold\Utility::getAclPath();'))->toBe(['getAclPath'])
        // 3b-3 新增的 REGISTRY 名字走的是同一条判据，四种写法一个不少
        ->and($stale('$this->utility->getTables();'))->toBe(['getTables'])
        ->and($stale('$u->getEnumWords();'))->toBe(['getEnumWords'])
        ->and($stale('app(Utility::class)->getOneTable($t);'))->toBe(['getOneTable']);

    // 反向：以四个新宿主为**接收者**的调用一律放行 —— 判定看的是接收者身份，不是方法名本身。
    // （所以即便某名字在新宿主上已不存在，也不会被这条锚点判红；那是「新宿主自己的 API」问题。）
    expect($stale('ActionDoc::parsePMCNames($rc);'))->toBe([])
        ->and($stale('ActionDoc::getActionRequestClass($m);'))->toBe([])
        ->and($stale('ActionMeta::removeActionNameMethod($x);'))->toBe([])
        ->and($stale('\Mooeen\Scaffold\Support\Paths::getModelPath();'))->toBe([])
        // `StorageRegistry::models()` 里的 `models` 不在旧名清单里，真正要放行的是**同名旧名**落在新宿主上的写法
        ->and($stale('\Mooeen\Scaffold\Support\StorageRegistry::getModels();'))->toBe([])
        ->and($stale('StorageRegistry::getControllers(false);'))->toBe([])
        ->and($stale('self::parseByLanguages($s);', 'ActionDoc.php'))->toBe([])
        ->and($stale('self::getEnums(false);', 'StorageRegistry.php'))->toBe([]);

    // 但同一个 `self::` 出现在**别人**的文件里就是漏改：`Utility` 调自己已删掉的方法。
    expect($stale('self::parseByLanguages($s);', 'Utility.php'))->toBe(['parseByLanguages'])
        ->and($stale('self::getEnums(false);', 'Utility.php'))->toBe(['getEnums']);

    // 名字撞车的**非 Utility** 接收者不该被误判：`AdderCommand::getControllers($path, $folder)`
    // 是命令自己的目录扫描（与 `Utility::getControllers(bool)` 只是同名）——
    // 判据是「同一段代码里**定义**了同名方法」，所以下面两条必须给出**相反**的结论，
    // 否则这层判据就成了「看见 `$this->` 就放行」的盲区。
    $defines = "protected function getControllers(\$path, \$folder): array { return []; }\n"
        . '$controllers = $this->getControllers($path, $folder);';

    expect($stale($defines, 'AdderCommand.php'))->toBe([])
        // 少了那行定义 ⇒ 同一句调用立刻变成漏改（若有人把判据写成「`$this->` 一律放行」，这条会红）
        ->and($stale('$controllers = $this->getControllers($path, $folder);', 'AdderCommand.php'))->toBe(['getControllers'])
        // 但 `$this->utility->` / `$u->` 这些**真实漏改形态**不受影响，照旧判红
        ->and($stale('$all = $this->utility->getControllers(false);'))->toBe(['getControllers'])
        ->and($stale('$all = $u->getControllers(false);'))->toBe(['getControllers']);
});

it('旧方法名只允许出现在「迁移说明」白名单文件里（注释也是文档，也会漂移）', function () {
    $root = dirname(__DIR__, 3);

    $offenders  = [];
    $allowedHit = [];

    foreach (['/src', '/tests'] as $dir) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . $dir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $rel  = str_replace($root . '/', '', $file->getPathname());
            $hits = utility_old_names_in_comments($file->getPathname());

            if ($hits === []) {
                continue;
            }

            if (in_array($rel, UTILITY_OLD_NAME_DOC_ALLOWLIST, true)) {
                $allowedHit[] = $rel;

                continue;
            }

            $offenders[] = $rel . ' → ' . implode(', ', $hits);
        }
    }

    expect($offenders)->toBe([], "以下文件的注释里把已不存在的方法名当现役用法写了（改用新宿主，或登记进 UTILITY_OLD_NAME_DOC_ALLOWLIST）：\n  " . implode("\n  ", $offenders));

    // helper 的判据自检（与代码面锚点的**互补关系**）：同一段文本，注释面看得见、代码面看不见。
    $tmp = sys_get_temp_dir() . '/scaffold_oldname_' . uniqid() . '.php';
    file_put_contents($tmp, "<?php\n// 用 getApiPath 取路径\n\$x = Paths::api('schema');\n");

    try {
        expect(utility_old_names_in_comments($tmp))->toBe(['getApiPath'])
            ->and(utility_scannable_code($tmp))->not->toContain('getApiPath');
    } finally {
        @unlink($tmp);
    }

    // 正向锚点：确认扫描真的扫到了白名单里的「迁移说明」，而不是文件集/正则坏了导致空过。
    // 这两处是「同一个旧名、两种正当写法」的代表：真源的映射表 + 锚点自身的说明。
    expect($allowedHit)->toContain(
        'src/Support/Paths.php',
        'tests/Feature/Support/UtilitySurfaceTest.php',
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
        $code = utility_scannable_code($file->getPathname());

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
