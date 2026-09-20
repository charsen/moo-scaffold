<?php declare(strict_types=1);

/**
 * 「表单契约豁免标记」族（`AuditFormContractCommand` 的 3 个私有方法）的**行为钉尸**测试。
 *
 * **为什么先有测试、后有外迁**：这 3 个方法 —— `waivedMarkers`（Request 源码里的
 * `// @moo-waived <field>: <原因>` → `字段名 => 原因`）、`dropForgotten`（剔除控制器层
 * `->forget('<field>')` 掉的字段）、`staleWaivedMarkers`（标记了却当期不构成检出的「陈旧标记」）
 * —— 此前只有 `AuditFormContractCommandTest` 从 `$this->artisan(...)` **端到端**覆盖，
 * **没有一条契约被直接钉住**（且夹具里根本没有 `forget(...)` 调用 ⇒ `dropForgotten` 从未被端到端触达）。
 * 按 `TUNING-PLAN.md` 红线 9「测试钉现状先行」，本文件**先**钉住现状（外迁前即须全绿），
 * **再**把这 3 个搬到 `Support\FormContractMarkers`（`final` + 全静态 + 零属性，常量一并带走）。
 *
 * **本文件是「同一批断言跨两个宿主」的**：外迁前 `fcmSubject()` 返回 `AuditFormContractCommand::class`
 * （3 个都是私有**实例**方法 ⇒ 反射 + 一个容器实例），外迁后返回 `FormContractMarkers::class`
 * （全静态 ⇒ `invoke(null, ...)`）—— **行为断言一个字都没动**，跟着宿主变的只有 `fcmSubject()` 一行。
 *
 * **为什么这一族可以外迁（判据三层）**：① 有没有「单入口可达的族」—— 有，族外只有 3 个调用点，
 * 且都挤在 `inspectFormPath()` 的同一条流水线上；② 族内是否**内聚** —— 是，三个方法合起来就是
 * 「豁免标记的解析 / 剔除 / 陈旧检测」这一条职责；③ 零状态 —— 3 个方法都不碰 `$this`，
 * 也不碰任何容器 / facade / `config()` / `new`（唯一的「新」是 `new ReflectionClass`，属纯反射）。
 * 拆完两边各自成立：新家 = **豁免标记的纯文本处理**，旧家 = **命令编排 + 反射执行 + 落账 + 输出**。
 *
 * ⚠ 本文件只**钉现状**，一个 bug 都不修。§2 / §3 各标了 `〔现状〕` 的既有形态。
 *
 * 文件分两段：**行为**段（§1~§3，跨宿主不变）+ **结构**段（§4，外迁后的形状契约，外迁前整体 skip）。
 */

use Mooeen\Scaffold\Command\AuditFormContractCommand;
use Mooeen\Scaffold\Support\FormContractMarkers;

/** 宿主解析点。**外迁时只改这一行**（连同顶部一条 `use`）。 */
function fcmSubject(): string
{
    return FormContractMarkers::class;
}

/**
 * 外迁后的目标宿主。
 *
 * 刻意写成**字符串字面量**：外迁前这个类还不存在，`class_exists()` 必须能安全求值为 false，
 * §4 才能整体 skip、保住「改前先绿」。与 `fcmSubject()` 的返回值由 §4 末条绑死。
 */
function fcmNewHost(): string
{
    return 'Mooeen\\Scaffold\\Support\\FormContractMarkers';
}

/** 调族内方法。外迁前 3 个都是私有实例方法（命令由容器 new，ctor 无参），外迁后全静态。 */
function fcmCall(string $method, mixed ...$args): mixed
{
    $ref = new ReflectionMethod(fcmSubject(), $method);
    $ref->setAccessible(true);

    if ($ref->isStatic()) {
        return $ref->invoke(null, ...$args);
    }

    return $ref->invoke(app(fcmSubject()), ...$args);
}

/** 迁走的 3 个方法名（唯一真源，§4 各条共用）。 */
function fcmMemberNames(): array
{
    return ['dropForgotten', 'staleWaivedMarkers', 'waivedMarkers'];
}

/** 解析夹具（带 3 条 `@moo-waived` 的 Request 类）。 */
function fcmFixtureClass(): string
{
    return \Mooeen\Scaffold\Tests\Feature\Support\Fixtures\Markers\MarkerRequest::class;
}

/** 夹具工程里**不含任何标记**的既有 Request（走「无标记 ⇒ 返空」分支）。 */
function fcmPlainRequestClass(): string
{
    return \Mooeen\Scaffold\Tests\Feature\Command\Fixtures\FormContract\App\Admin\Requests\Demo\Widget\StoreRequest::class;
}

/** 仓库根。 */
function fcmRepoRoot(): string
{
    return dirname(__DIR__, 3);
}

/* ═══════════════════════════════════════════════════════════════════════
 * §1 waivedMarkers —— Request 源码 → 字段名 => 原因
 * ═══════════════════════════════════════════════════════════════════════ */

it('§1 waivedMarkers：解析 `@moo-waived <field>: <原因>`；无标记/非文件类返空；无冒号的不算', function () {
    // 夹具里三条形态：正常 / 字段名含 `.` 与 `*` / 无冒号（不构成标记）
    expect(fcmCall('waivedMarkers', fcmFixtureClass()))->toBe([
        'legacy_field'   => '早期精简：nullable 字段暂不实现',
        'form_config.*'  => '通配符字段名也算',
        'legacy_field_2' => '第二个',
    ]);

    // 文件里没有 `@moo-waived` ⇒ 空数组
    expect(fcmCall('waivedMarkers', fcmPlainRequestClass()))->toBe([]);

    // 内部类没有源文件（`getFileName()` 返 false）⇒ 空数组（守卫分支）
    expect(fcmCall('waivedMarkers', \ArrayObject::class))->toBe([]);

    // eval 出来的类 `getFileName()` 不是真实文件（`is_file()` 假）⇒ 空数组（另一条守卫分支）
    if (! class_exists('FcmEvaledProbe')) {
        eval('class FcmEvaledProbe {}');
    }
    expect(fcmCall('waivedMarkers', 'FcmEvaledProbe'))->toBe([]);
});

/* ═══════════════════════════════════════════════════════════════════════
 * §2 dropForgotten —— 按 `forget('<field>')` 字面剔除
 * ═══════════════════════════════════════════════════════════════════════ */

it('§2 dropForgotten：字面剔除被 forget 的字段，其余保持顺序并归一成 list', function () {
    $src = <<<'PHP'
        public function create() {
            $this->form()->forget('budget_personnel_ids')->forget('legacy_openid');
        }
        PHP;

    expect(fcmCall('dropForgotten', ['budget_personnel_ids', 'keep_me', 'legacy_openid'], $src))
        ->toBe(['keep_me'])
        // 没有 forget 调用 ⇒ 原样（仍是 list）
        ->and(fcmCall('dropForgotten', ['a', 'b'], 'nothing here'))->toBe(['a', 'b'])
        // 空输入 ⇒ 空
        ->and(fcmCall('dropForgotten', [], "forget('a')"))->toBe([])
        // 输入键不连续 ⇒ 输出重新索引成 list（array_filter 保键，array_values 归一）
        ->and(fcmCall('dropForgotten', [2 => 'a', 5 => 'b'], "forget('a')"))->toBe(['b'])
        // 搜索串带引号 ⇒ `forget('bb')` 不会误剔 `b`
        ->and(fcmCall('dropForgotten', ['b'], "forget('bb')"))->toBe(['b'])
        // ⚠ 现状：按整份控制器源码字面匹配 ⇒ 同名字段在**别的 action** 里被 forget 也会连带剔除
        //   （宁可漏报也不误报，见方法注释的「精度边界」）
        ->and(fcmCall('dropForgotten', ['shared'], "public function edit() { \$x->forget('shared'); }"))->toBe([]);
});

/* ═══════════════════════════════════════════════════════════════════════
 * §3 staleWaivedMarkers —— 标记了却当期不构成检出 ⇒ 报 warning
 * ═══════════════════════════════════════════════════════════════════════ */

it('§3 staleWaivedMarkers：只做集合差（waived 键 − extra），按 waived 键序产出 $where + field/reason', function () {
    $where = ['module' => 'M', 'controller' => 'C', 'method' => 'create', 'request' => 'StoreRequest'];

    // `b` 不在当期检出项里 ⇒ 陈旧；`a` 仍在 ⇒ 不算
    expect(fcmCall('staleWaivedMarkers', ['a' => 'r1', 'b' => 'r2'], ['a'], $where))
        ->toBe([$where + ['field' => 'b', 'reason' => 'r2']])
        // 全部仍在 ⇒ 空
        ->and(fcmCall('staleWaivedMarkers', ['a' => 'r1'], ['a', 'z'], $where))->toBe([])
        // 没有任何标记 ⇒ 空
        ->and(fcmCall('staleWaivedMarkers', [], ['a'], $where))->toBe([])
        // 顺序跟随 waived 的**键序**（array_diff 保键 + foreach）
        ->and(fcmCall('staleWaivedMarkers', ['b' => 'r2', 'a' => 'r1'], [], $where))->toBe([
            $where + ['field' => 'b', 'reason' => 'r2'],
            $where + ['field' => 'a', 'reason' => 'r1'],
        ])
        // ⚠ 现状：判定**只看** extra 集合，不区分「控件已移除 / 规则已补回」与别的成因；
        //   extra 为空 ⇒ 所有标记一律判陈旧（无任何守卫）
        ->and(fcmCall('staleWaivedMarkers', ['a' => 'r1'], [], $where))
        ->toBe([$where + ['field' => 'a', 'reason' => 'r1']]);
});

/* ═══════════════════════════════════════════════════════════════════════
 * §4 结构锚点（外迁后的形状契约，只针对新宿主）
 *
 * ⚠ 外迁前整体 skip —— 同第 6/7/8 项的落地方式：本文件在外迁**前**必须全绿（§4 全 skip 不算红），
 *   外迁**后** §4 必须全转绿。半迁移（类建了但没接上）会**报红**而不是被跳过 ——
 *   skip 的条件是「新宿主这个类存不存在」，不是「我改完了没有」。
 * ═══════════════════════════════════════════════════════════════════════ */

/** 新宿主源码路径。 */
function fcmSourcePath(): string
{
    return fcmRepoRoot() . '/src/Support/FormContractMarkers.php';
}

/** 去掉注释（可选再去掉字符串字面量）后的源码 —— 结构断言必须先剥注释。 */
function fcmCodeOnly(string $php, bool $keepStrings = true): string
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
function fcmOwnMethodNames(string $class): array
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
    $rc = new ReflectionClass(fcmNewHost());

    expect($rc->isFinal())->toBeTrue()
        ->and($rc->getConstructor())->toBeNull()
        ->and(fcmOwnMethodNames(fcmNewHost()))->toBe(fcmMemberNames())
        ->and($rc->getProperties())->toHaveCount(0)
        // 标记正则随族一起搬（`waivedMarkers` 内部 `self::WAIVED_MARKER_PATTERN`）
        ->and($rc->getReflectionConstant('WAIVED_MARKER_PATTERN'))->not->toBeFalse();

    foreach (fcmMemberNames() as $name) {
        $m = $rc->getMethod($name);
        expect($m->isStatic())->toBeTrue("{$name}() 应为 static（零状态族）")
            ->and($m->isPublic())->toBeTrue("{$name}() 应公开（族外 3 个调用点都直接指向它）");
    }
})->skip(! class_exists(fcmNewHost()), '外迁前新宿主尚不存在');

it('§4 依赖面锚点：纯文本处理 —— 零 $this / 零容器 / 零服务；只保留 ReflectionClass', function () {
    $code = fcmCodeOnly((string) file_get_contents(fcmSourcePath()), keepStrings: false);

    foreach (['$this', 'app(', 'config(', 'Utility', 'Filesystem', 'FormWidgetVisibility', 'ConsoleUi'] as $needle) {
        expect($code)->not->toContain($needle);
    }

    // 正向钉住「有意保留」的依赖：`new ReflectionClass`（纯反射，不是服务）与自身常量 `self::`
    expect($code)->toContain('ReflectionClass')
        ->and($code)->toContain('self::WAIVED_MARKER_PATTERN');

    // `use` 清单恰好 1 条（`ReflectionClass`；其余都是全局函数 / 语言构造）
    $head = substr($code, 0, (int) strpos($code, 'final class'));
    preg_match_all('/^use\s+([^\s;]+);/m', $head, $u);

    expect($u[1])->toBe(['ReflectionClass']);
})->skip(! class_exists(fcmNewHost()), '外迁前新宿主尚不存在');

it('§4 「已不存在」锚点：这 3 个方法与常量不得再出现在 AuditFormContractCommand 上', function () {
    $rc = new ReflectionClass(AuditFormContractCommand::class);

    $stillThere = array_values(array_filter(
        fcmMemberNames(),
        static fn (string $name): bool => $rc->hasMethod($name),
    ));

    expect($stillThere)->toBe([])
        ->and($rc->getReflectionConstant('WAIVED_MARKER_PATTERN'))->toBeFalse()
        // 同族但刻意留下的编排/落账方法必须**还在**（别连坐删掉）
        ->and($rc->hasMethod('inspectFormPath'))->toBeTrue()
        ->and($rc->hasMethod('recordFindings'))->toBeTrue();
})->skip(! class_exists(fcmNewHost()), '外迁前新宿主尚不存在');

it('§4 接线锚点：命令的 3 个调用点改指新宿主，且不留本地转发', function () {
    $code = fcmCodeOnly((string) file_get_contents(fcmRepoRoot() . '/src/Command/AuditFormContractCommand.php'));

    expect($code)->toContain('FormContractMarkers::waivedMarkers(')
        ->and($code)->toContain('FormContractMarkers::dropForgotten(')
        ->and($code)->toContain('FormContractMarkers::staleWaivedMarkers(');

    foreach (fcmMemberNames() as $name) {
        expect($code)->not->toContain('$this->' . $name . '(');
    }
})->skip(! class_exists(fcmNewHost()), '外迁前新宿主尚不存在');

it('§4 fcmSubject() 已翻到新宿主 ⇒ §1~§3 的断言现在跑在新类上（断言一字未动）', function () {
    expect(fcmSubject())->toBe(fcmNewHost())
        ->and((new ReflectionMethod(fcmSubject(), 'waivedMarkers'))->isStatic())->toBeTrue();
})->skip(! class_exists(fcmNewHost()), '外迁前新宿主尚不存在');
