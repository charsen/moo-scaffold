<?php declare(strict_types=1);

/**
 * 「控制器扫描目标」推导（`AuditFormContractCommand` 的 3 个私有方法）的**行为钉尸**测试。
 *
 * **为什么先有测试、后有外迁**：这 3 个方法 —— `resolveRoot`（`--scope` → 绝对扫描根）、
 * `controllerNamespace`（扫描根 → 控制器命名空间）、`requestNamespace`（控制器命名空间 →
 * Request 命名空间）—— 此前只有 `AuditFormContractCommandTest` 从 `$this->artisan(...)` **端到端**
 * 覆盖，**没有一条契约被直接钉住**（且那几条用的都是「绝对 scope + PSR-4 命中」这一种输入）。
 * 按 `TUNING-PLAN.md` 红线 9「测试钉现状先行」，本文件**先**钉住现状（外迁前即须全绿），
 * **再**把这 3 个搬到 `Support\ControllerScanTarget`（`final` + 全静态 + 零属性）。
 *
 * **本文件是「同一批断言跨两个宿主」的**：外迁前 `cstSubject()` 返回 `AuditFormContractCommand::class`
 * （3 个都是私有**实例**方法 ⇒ 反射 + 一个容器实例），外迁后返回 `ControllerScanTarget::class`
 * （全静态 ⇒ `invoke(null, ...)`）—— **行为断言一个字都没动**，跟着宿主变的只有 `cstSubject()` 一行。
 *
 * **为什么这一族可以外迁（判据三层）**：① 有没有「单入口可达的族」—— 有，族外只有 2 个调用点
 * （`handle()` 用 `resolveRoot`、`resolveNamespace()` 用 `controllerNamespace`、`inspectController()`
 * 用 `requestNamespace`）；② 族内是否**内聚** —— 是，三个方法合起来就是「把 `--scope` 变成一个
 * （根目录 + 控制器命名空间 + Request 命名空间）三元组」；③ 拆完两边是否各自成立 —— 新家 = **纯推导**
 * （零 `$this`、零服务），旧家 = **命令编排 + 反射执行 + 落账 + 输出**。
 * **同族但刻意不搬的两个**（它们要 `$this->option()` / `$this->console()`，搬走就得把命令行上下文注进来）：
 * `resolveNamespace()`（读 `--namespace` + 就地报错）、`resolveControllerFiles()`（glob + 就地报错）。
 * 另：`inspectController()` 与命名空间解析**不是一个职责**（它是执行驱动、还调族外 `inspectFormPath`），
 * 早期把 4 个方法归为一簇的记录**已被本次复核推翻**。
 *
 * ⚠ 本文件只**钉现状**，一个 bug 都不修。§1 末条与 §3 末条各标了一处 `〔现状 · 疑似缺陷〕`。
 *
 * 文件分两段：**行为**段（§1~§3，跨宿主不变）+ **结构**段（§4，外迁后的形状契约，外迁前整体 skip）。
 */

use Mooeen\Scaffold\Command\AuditFormContractCommand;
use Mooeen\Scaffold\Support\ControllerScanTarget;

/** 宿主解析点。**外迁时只改这一行**（连同顶部一条 `use`）。 */
function cstSubject(): string
{
    return ControllerScanTarget::class;
}

/**
 * 外迁后的目标宿主。
 *
 * 刻意写成**字符串字面量**：外迁前这个类还不存在，`class_exists()` 必须能安全求值为 false，
 * §4 才能整体 skip、保住「改前先绿」。与 `cstSubject()` 的返回值由 §4 末条绑死。
 */
function cstNewHost(): string
{
    return 'Mooeen\\Scaffold\\Support\\ControllerScanTarget';
}

/**
 * 调族内方法。
 *
 * 外迁前 3 个都是**私有实例**方法（命令由容器 new，ctor 无参），外迁后全静态。
 */
function cstCall(string $method, mixed ...$args): mixed
{
    $ref = new ReflectionMethod(cstSubject(), $method);
    $ref->setAccessible(true);

    if ($ref->isStatic()) {
        return $ref->invoke(null, ...$args);
    }

    return $ref->invoke(app(cstSubject()), ...$args);
}

/** 迁走的 3 个方法名（唯一真源，§4 各条共用）。 */
function cstMemberNames(): array
{
    return ['controllerNamespace', 'requestNamespace', 'resolveRoot'];
}

/**
 * 夹具扫描根 —— 与 `AuditFormContractCommandTest::formContractScope()` 指向**同一个目录**
 * （刻意不去调那条 helper：跨文件复用测试助手会耦合两个文件的加载顺序）。
 */
function cstFixtureScope(): string
{
    return dirname(__DIR__) . '/Command/Fixtures/FormContract/App/Admin/Controllers';
}

/** 仓库根。 */
function cstRepoRoot(): string
{
    return dirname(__DIR__, 3);
}

it('宿主解析点有效：cstSubject() 指向的类存在且持有 3 个族成员', function () {
    $rc = new ReflectionClass(cstSubject());

    expect(class_exists(cstSubject()))->toBeTrue();

    foreach (cstMemberNames() as $name) {
        expect($rc->hasMethod($name))->toBeTrue();
    }
});

/* ═══════════════════════════════════════════════════════════════════════
 * §1 resolveRoot —— `--scope` → 绝对扫描根
 * ═══════════════════════════════════════════════════════════════════════ */

it('§1 resolveRoot：空串落默认 scope；相对拼 base_path；绝对走 realpath；realpath 失败则原样', function () {
    $default = rtrim(base_path('app/Admin/Controllers'), '/');

    expect(cstCall('resolveRoot', ''))->toBe($default)
        // 显式给同一个值 ⇒ 同一结果
        ->and(cstCall('resolveRoot', 'app/Admin/Controllers'))->toBe($default)
        // 尾斜杠被 rtrim 掉（相对分支）
        ->and(cstCall('resolveRoot', 'app/Admin/Controllers/'))->toBe($default);

    // 绝对路径走 realpath —— 这正是本处不能"自己拼 base_path"的原因（`/tmp` 在 macOS 上是指向
    // `/private/tmp` 的软链，`base_path()` 那套绝对判定不认盘符之外的平台差异）
    expect(cstCall('resolveRoot', '/tmp'))->toBe(rtrim((string) realpath('/tmp'), '/'))
        // realpath 失败 ⇒ 原样返回（不抛）
        ->and(cstCall('resolveRoot', '/no/such/dir-xyz-123'))->toBe('/no/such/dir-xyz-123')
        // 盘符也算绝对（判定委托 `Support\Paths::isAbsolute` —— 全仓唯一口径）；本机是 macOS，
        // realpath 必然失败 ⇒ 只验证「走了绝对分支 + rtrim」
        ->and(cstCall('resolveRoot', 'C:\\dir\\sub'))->toBe('C:\\dir\\sub')
        // ⚠ 现状：绝对根 `/` 会被 rtrim 吃成空串 —— `--scope=/` 会把扫描根变成 `''`
        //   （`resolveControllerFiles('')` 随后 glob 出空、命令按"无事可做"退出 0）。属既有形态，
        //   本文件只钉不改：真要改是行为变更，得单独过堂。
        ->and(cstCall('resolveRoot', '/'))->toBe('');
});

/* ═══════════════════════════════════════════════════════════════════════
 * §2 controllerNamespace —— 扫描根 → 控制器命名空间（PSR-4 优先，base_path 兜底）
 * ═══════════════════════════════════════════════════════════════════════ */

it('§2 controllerNamespace：PSR-4 前缀命中、根即前缀时不再补 `\\`、base_path 兜底、范围外 null', function () {
    // ① PSR-4 命中 —— 两条注册前缀各自映射正确：
    //    `Mooeen\Scaffold\` → src、`Mooeen\Scaffold\Tests\` → tests。
    //    ⚠ **「最长前缀胜出」那条规则本文件测不到**（认账）：两条前缀目录（`src` / `tests`）
    //    互不包含，所以任一给定路径只会命中其中一条，`strlen($dir) > strlen($best[0])` 这个
    //    比较在当前的 autoload 配置下**不可观测**。要测它得先造「一条前缀的目录是另一条的子目录」，
    //    那需要改 composer 配置 ⇒ 不属本项范围。这里只钉「每条前缀各自映射对」。
    expect(cstCall('controllerNamespace', cstFixtureScope()))
        ->toBe('Mooeen\\Scaffold\\Tests\\Feature\\Command\\Fixtures\\FormContract\\App\\Admin\\Controllers')
        // src 下的路径命中另一条前缀
        ->and(cstCall('controllerNamespace', cstRepoRoot() . '/src/Support'))->toBe('Mooeen\\Scaffold\\Support')
        // 前缀根就是根本身 ⇒ 相对部分为空，不再补分隔符
        ->and(cstCall('controllerNamespace', cstRepoRoot() . '/src'))->toBe('Mooeen\\Scaffold')
        // 尾斜杠先被归一（否则 `str_starts_with` 的边界比较会失配）
        ->and(cstCall('controllerNamespace', cstRepoRoot() . '/src/Support/'))->toBe('Mooeen\\Scaffold\\Support');

    // ② 不在任何 PSR-4 前缀下 ⇒ 回落「base_path 相对路径」这条兜底分支，`app/` 段换成 `App\`
    expect(cstCall('controllerNamespace', base_path('app/Admin/Controllers')))->toBe('App\\Admin\\Controllers')
        // 没有 `app/` 段 ⇒ 只是把 `/` 换成 `\`（**保持小写** —— 现状，不做 Studly）
        ->and(cstCall('controllerNamespace', base_path('some/deep/dir')))->toBe('some\\deep\\dir');

    // ③ 边界必须按目录比，不能按裸字符串前缀：`srcX` 不是 `src` 的子目录 ⇒ 两条分支都不命中 ⇒ null
    expect(cstCall('controllerNamespace', cstRepoRoot() . '/srcX'))->toBeNull()
        // ④ 既不在 PSR-4 下、也不在 base_path 下 ⇒ null（调用方据此报错并退出 FAILURE）
        ->and(cstCall('controllerNamespace', '/definitely-not-a-real-root-xyz/Controllers'))->toBeNull()
        // ⑤ 空串 ⇒ null（`resolveRoot('')` 不会给空串，这里是直接调用的兜底）
        ->and(cstCall('controllerNamespace', ''))->toBeNull();
});

/* ═══════════════════════════════════════════════════════════════════════
 * §3 requestNamespace —— 控制器命名空间 → Request 命名空间
 * ═══════════════════════════════════════════════════════════════════════ */

it('§3 requestNamespace：`\\Controllers` → `\\Requests`；没有该段则追加；⚠ 子串命中会连带替换〔现状〕', function () {
    expect(cstCall('requestNamespace', 'App\\Admin\\Controllers'))->toBe('App\\Admin\\Requests')
        // `str_replace` 会替换**所有**出现 ⇒ 后缀段也保住
        ->and(cstCall('requestNamespace', 'App\\Admin\\Controllers\\Sub'))->toBe('App\\Admin\\Requests\\Sub')
        // 没有 `\Controllers` 段 ⇒ 直接追加
        ->and(cstCall('requestNamespace', 'App\\Admin'))->toBe('App\\Admin\\Requests')
        ->and(cstCall('requestNamespace', 'App'))->toBe('App\\Requests')
        // 裸 `Controllers`（无前导反斜杠）不算命中 `\Controllers` ⇒ 走追加分支
        ->and(cstCall('requestNamespace', 'Controllers'))->toBe('Controllers\\Requests')
        // ⚠ 现状：判定是 `str_contains($ns, '\\Controllers')` —— **子串**命中，不是「段相等」。
        //   `ControllersX` 这种目录名会被连带替换成 `RequestsX`（一个并不存在的命名空间）。
        //   真实链路可达（`--scope=app/Admin/ControllersX`）。属既有形态，只钉不改。
        ->and(cstCall('requestNamespace', 'App\\Admin\\ControllersX'))->toBe('App\\Admin\\RequestsX');
});

/* ═══════════════════════════════════════════════════════════════════════
 * §4 结构锚点（外迁后的形状契约，只针对新宿主）
 *
 * ⚠ 外迁前整体 skip —— 同第 6/7 项的落地方式：本文件在外迁**前**必须全绿（§4 全 skip 不算红），
 *   外迁**后** §4 必须全转绿。半迁移（类建了但没接上）会**报红**而不是被跳过 ——
 *   skip 的条件是「新宿主这个类存不存在」，不是「我改完了没有」。
 * ═══════════════════════════════════════════════════════════════════════ */

/** 新宿主源码路径。 */
function cstSourcePath(): string
{
    return cstRepoRoot() . '/src/Support/ControllerScanTarget.php';
}

/** 去掉注释（可选再去掉字符串字面量）后的源码 —— 结构断言必须先剥注释。 */
function cstCodeOnly(string $php, bool $keepStrings = true): string
{
    $out = '';

    foreach (token_get_all($php) as $t) {
        if (! is_array($t)) {
            $out .= $t;

            continue;
        }
        if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
            continue;
        }
        if (! $keepStrings && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        $out .= $t[1];
    }

    return $out;
}

/** 某类**自己声明**的普通方法名（升序；`__*` 魔术方法不算家族成员）。 */
function cstOwnMethodNames(string $class): array
{
    $rc    = new ReflectionClass($class);
    $names = array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        array_filter(
            $rc->getMethods(),
            static fn (ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === $class
                && ! str_starts_with($m->getName(), '__'),
        ),
    );
    sort($names);

    return $names;
}

it('§4 宿主形状：final + 无构造函数 + 3 个方法恰好齐全且全为 static 且全为 public', function () {
    $rc = new ReflectionClass(cstNewHost());

    expect($rc->isFinal())->toBeTrue()
        // 零状态 ⇒ 连构造函数都不该有（没有依赖要注）
        ->and($rc->getConstructor())->toBeNull()
        // 方法集合恰好等于这一族 —— 少一个（漏搬）或多一个（顺手加料）都红
        ->and(cstOwnMethodNames(cstNewHost()))->toBe(cstMemberNames())
        // 零属性
        ->and($rc->getProperties())->toHaveCount(0);

    foreach (cstMemberNames() as $name) {
        $m = $rc->getMethod($name);
        expect($m->isStatic())->toBeTrue("{$name}() 应为 static（零状态族）")
            ->and($m->isPublic())->toBeTrue("{$name}() 应公开（族外 3 个调用点都直接指向它）");
    }
})->skip(! class_exists(cstNewHost()), '外迁前新宿主尚不存在');

it('§4 依赖面锚点：纯推导 —— 零 $this / 零容器 / 零服务；只保留 `base_path()` 与 `Paths::` 两个既有全局', function () {
    $code = cstCodeOnly((string) file_get_contents(cstSourcePath()), keepStrings: false);

    foreach (['$this', 'app(', 'config(', 'Utility', 'Filesystem', 'FormWidgetVisibility', 'ConsoleUi', 'self::'] as $needle) {
        expect($code)->not->toContain($needle);
    }

    // ⚠ 刻意**保留**的全局依赖（原样搬过来的既有形态，不是新引入的）：
    //   `Paths::isAbsolute`（绝对路径判定，全仓唯一口径）与 `base_path()`（相对分支基线）。
    //   把这两条**正向**钉住，等于说明「不是漏扫，是有意的」。
    expect($code)->toContain('Paths::isAbsolute(')
        ->and($code)->toContain('base_path(')
        // PSR-4 反推要遍历 composer 的 autoloader 实例
        ->and($code)->toContain('ClassLoader');

    // `use` 清单恰好 1 条：`Paths` 与 `ReflectionClass` 都不需要（前者同命名空间、后者本族不用）
    $head = substr($code, 0, (int) strpos($code, 'final class'));
    preg_match_all('/^use\s+([^\s;]+);/m', $head, $u);

    expect($u[1])->toBe(['Composer\Autoload\ClassLoader']);
})->skip(! class_exists(cstNewHost()), '外迁前新宿主尚不存在');

it('§4 「已不存在」锚点：这 3 个方法不得再出现在 AuditFormContractCommand 上', function () {
    $rc = new ReflectionClass(AuditFormContractCommand::class);

    $stillThere = array_values(array_filter(
        cstMemberNames(),
        static fn (string $name): bool => $rc->hasMethod($name),
    ));

    expect($stillThere)->toBe([])
        // 同族但刻意留下的两个必须**还在**（别连坐删掉）
        ->and($rc->hasMethod('resolveNamespace'))->toBeTrue()
        ->and($rc->hasMethod('resolveControllerFiles'))->toBeTrue();
})->skip(! class_exists(cstNewHost()), '外迁前新宿主尚不存在');

it('§4 接线锚点：命令的 3 个调用点改指新宿主，且不留本地转发', function () {
    $code = cstCodeOnly((string) file_get_contents(cstRepoRoot() . '/src/Command/AuditFormContractCommand.php'));

    expect($code)->toContain('ControllerScanTarget::resolveRoot(')
        ->and($code)->toContain('ControllerScanTarget::controllerNamespace(')
        ->and($code)->toContain('ControllerScanTarget::requestNamespace(');

    foreach (cstMemberNames() as $name) {
        expect($code)->not->toContain('$this->' . $name . '(');
    }
})->skip(! class_exists(cstNewHost()), '外迁前新宿主尚不存在');

it('§4 cstSubject() 已翻到新宿主 ⇒ §1~§3 的断言现在跑在新类上（断言一字未动）', function () {
    expect(cstSubject())->toBe(cstNewHost())
        ->and((new ReflectionMethod(cstSubject(), 'resolveRoot'))->isStatic())->toBeTrue();
})->skip(! class_exists(cstNewHost()), '外迁前新宿主尚不存在');
