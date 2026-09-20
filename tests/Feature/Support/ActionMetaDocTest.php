<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Mooeen\Scaffold\Support\ActionDoc;
use Mooeen\Scaffold\Support\ActionMeta;

/**
 * `Access Support\ActionMeta` / `Support\ActionDoc` —— 2026-09-19 自 `Utility` 外迁的纯静态助手
 * （中/高风险队列第 3 项 · 阶段 3b）。
 *
 * 这两个类此前是 `Utility` 上的 9 个实例方法，**只有间接覆盖**（经 `CreateApiGenerator` /
 * `ApiController` 的整链用例），没有一条直接钉住它们自己的语义 —— 外迁正好把这块补上：
 * 现在它们是「单一真源」，自己得有直接测试，否则下次谁改一行正则，红的会是某个 generator 的
 * 整链用例，排查成本全在定位上。
 *
 * 文件分两段：
 *   ① 语义（读得出什么）：`ActionMeta` 的归一/判废/菜单/后缀剥离，`ActionDoc` 的 docblock 解析；
 *   ② 形状（必须一直是什么）：`final` + 全静态 + 无私有时只读状态 —— 防它们被写回「有状态服务」。
 */
beforeEach(function () {
    // `ActionDoc::parseByLanguages()` 的外界依赖只有这一项配置（原走 `Utility::getConfig()`）。
    config()->set('scaffold.languages', ['en', 'zh-CN']);
});

// ---------------------------------------------------------------- ① ActionMeta 语义

it('ActionMeta::removeMethodSuffix 只剥末尾的 HTTP 方法后缀', function () {
    expect(ActionMeta::removeMethodSuffix('order_get'))->toBe('order');
    expect(ActionMeta::removeMethodSuffix('order_post'))->toBe('order');
    expect(ActionMeta::removeMethodSuffix('order_delete'))->toBe('order');
    expect(ActionMeta::removeMethodSuffix('order_put'))->toBe('order');
    expect(ActionMeta::removeMethodSuffix('order_patch'))->toBe('order');
    expect(ActionMeta::removeMethodSuffix('order_head'))->toBe('order');
    expect(ActionMeta::removeMethodSuffix('order_options'))->toBe('order');
    expect(ActionMeta::removeMethodSuffix('order_any'))->toBe('order');
    // 大小写不敏感（路由里 `_GET` 也见过）
    expect(ActionMeta::removeMethodSuffix('order_GET'))->toBe('order');
});

it('ActionMeta::removeMethodSuffix 支持 `_get|post` 复合后缀与数组入参', function () {
    expect(ActionMeta::removeMethodSuffix('order_get|post'))->toBe('order');
    expect(ActionMeta::removeMethodSuffix('order_post|put|delete'))->toBe('order');

    // 数组入参：逐项剥离（旧签名就是 string|array）
    expect(ActionMeta::removeMethodSuffix(['order_get', 'user_post', 'plain']))
        ->toBe(['order', 'user', 'plain']);
});

it('ActionMeta::removeMethodSuffix 不动中间/开头的方法名，也不吃近似词', function () {
    expect(ActionMeta::removeMethodSuffix('get_order'))->toBe('get_order');       // 开头不剥
    expect(ActionMeta::removeMethodSuffix('order_getaway'))->toBe('order_getaway'); // `_get` 后还有字符
    expect(ActionMeta::removeMethodSuffix('order_status'))->toBe('order_status'); // 无后缀原样
    expect(ActionMeta::removeMethodSuffix(''))->toBe('');                          // 空串
});

it('ActionMeta::isDeprecated 接受 bool / 数字 / 字符串三种写法', function () {
    expect(ActionMeta::isDeprecated(['deprecated' => true]))->toBeTrue();
    expect(ActionMeta::isDeprecated(['deprecated' => false]))->toBeFalse();

    expect(ActionMeta::isDeprecated(['deprecated' => 1]))->toBeTrue();
    expect(ActionMeta::isDeprecated(['deprecated' => 0]))->toBeFalse();
    expect(ActionMeta::isDeprecated(['deprecated' => 2]))->toBeFalse(); // 只有 1 算

    foreach (['1', 'true', 'TRUE', 'yes', 'deprecated', ' true '] as $truthy) {
        expect(ActionMeta::isDeprecated(['deprecated' => $truthy]))->toBeTrue();
    }
    foreach (['0', '', 'no', 'false', 'nope'] as $falsy) {
        expect(ActionMeta::isDeprecated(['deprecated' => $falsy]))->toBeFalse();
    }

    // 手写 YAML 常常整行省略这个键
    expect(ActionMeta::isDeprecated([]))->toBeFalse();
});

it('ActionMeta::normalize 优先取 meta 子数组，缺失时回落到扁平键', function () {
    $nested = ActionMeta::normalize([
        'meta' => [
            'creator'           => '  alice  ',
            'created_at'        => '  2026-09-19 10:00:00  ',
            'deprecated_by'     => 'bob',
            'deprecated_reason' => '换成 v2',
        ],
        'creator' => '会被 meta 覆盖',
    ]);

    expect($nested['creator'])->toBe('alice');                 // trim
    expect($nested['created_at'])->toBe('2026-09-19 10:00:00'); // 不格式化时原样（仅 trim）
    expect($nested['deprecated_by'])->toBe('bob');
    expect($nested['deprecated_reason'])->toBe('换成 v2');

    // 无 meta：一层层回落到扁平的 creator → user
    expect(ActionMeta::normalize(['creator' => 'carol'])['creator'])->toBe('carol');
    expect(ActionMeta::normalize(['user' => 'dave'])['creator'])->toBe('dave');
    expect(ActionMeta::normalize([])['creator'])->toBe('');

    // meta 写成非数组（手写 YAML 会写成字符串）不得炸
    expect(ActionMeta::normalize(['meta' => 'oops'])['creator'])->toBe('');
});

it('ActionMeta::normalize 在 $formatDates 时把三个时间统一成 Y-m-d', function () {
    $data = ActionMeta::normalize([
        'created_at'    => '2026-09-19T10:00:00+08:00',
        'updated_at'    => '2026/09/19 10:00:00',
        'deprecated_at' => '2026-09-19',
    ], true);

    expect($data['created_at'])->toBe('2026-09-19');
    expect($data['updated_at'])->toBe('2026-09-19'); // `Y/m/d` 也能认
    expect($data['deprecated_at'])->toBe('2026-09-19');

    // 空值保持空串（不变成「今天」）
    expect(ActionMeta::normalize([], true)['created_at'])->toBe('');
});

it('ActionMeta::normalizeMenus 兼容旧的扁平写法，并保证 controllers 可安全追加', function () {
    $data = ActionMeta::normalizeMenus([
        'system' => '系统管理',                        // 旧扁平写法：只有名字
        'biz'    => ['name' => '业务', 'controllers' => ['OrderController']],
        'bare'   => ['name' => '裸的'],               // 数组但没有 controllers
        'bad'    => 42,                               // 标量→丢掉
    ]);

    expect($data)->toBe([
        'system' => ['name' => '系统管理', 'controllers' => []],
        'biz'    => ['name' => '业务', 'controllers' => ['OrderController']],
        'bare'   => ['name' => '裸的', 'controllers' => []],
    ]);
});

// ---------------------------------------------------------------- ② ActionDoc 语义

it('ActionDoc::parsePMCNames 从 DocBlock 读出包/模块/控制器三处多语言名', function () {
    $names = ActionDoc::parsePMCNames(new ReflectionClass(actiondoc_fixture_controller()));

    expect($names['package']['name'])->toBe(['en' => 'System', 'zh-CN' => '系统']);
    expect($names['module']['name'])->toBe(['en' => 'Order', 'zh-CN' => '订单']);
    expect($names['controller']['name'])->toBe(['en' => 'Order', 'zh-CN' => '订单管理']);
});

it('ActionDoc::parsePMCNames 无 DocBlock / 无 tag 时三处都是空壳而不是 null', function () {
    $names = ActionDoc::parsePMCNames(new ReflectionClass(actiondoc_fixture_bare()));

    // 每个语言键都得在（键序即 scaffold.languages），值空串 —— 展示端靠它 `.` 索引
    expect($names['package']['name'])->toBe(['en' => '', 'zh-CN' => '']);
    expect($names['module']['name'])->toBe(['en' => '', 'zh-CN' => '']);
    expect($names['controller']['name'])->toBe(['en' => '', 'zh-CN' => '']);
});

it('ActionDoc::parseActionInfo 读 @acl：有则名字直取、无则视为白名单', function () {
    $cls  = actiondoc_fixture_controller();
    $with = ActionDoc::parseActionInfo(new ReflectionMethod($cls, 'index'));
    $none = ActionDoc::parseActionInfo(new ReflectionMethod($cls, 'show'));

    expect($with['whitelist'])->toBeFalse();
    expect($with['name'])->toBe(['en' => 'Order List', 'zh-CN' => '订单列表']);
    expect($with['desc'])->toBe('分页返回当前用户的订单。');

    expect($none['whitelist'])->toBeTrue(); // 没写 @acl ⇒ 免鉴权
    expect($none['name'])->toBe(['en' => '', 'zh-CN' => '']);
});

// `parseActionName` / `parseActionDesc` 的语义不在这里重复钉 —— 它们有自己的专文件
// `ParseActionDescTest`（本阶段一并从 `Utility` 迁到 `ActionDoc`），重复两份只会让改口径时要改两处。

it('ActionDoc::getActionRequestClass 只认名为 $request 的类类型参数', function () {
    $cls = actiondoc_fixture_controller();

    expect(ActionDoc::getActionRequestClass(new ReflectionMethod($cls, 'withRequest')))
        ->toBeInstanceOf(Request::class);

    // 内建类型（`int $request`）/ 名字不叫 $request / 没有参数 ⇒ 都是 null，不得抛
    expect(ActionDoc::getActionRequestClass(new ReflectionMethod($cls, 'withBuiltinRequest')))->toBeNull();
    expect(ActionDoc::getActionRequestClass(new ReflectionMethod($cls, 'withWrongName')))->toBeNull();
    expect(ActionDoc::getActionRequestClass(new ReflectionMethod($cls, 'noParams')))->toBeNull();
});

// ---------------------------------------------------------------- ③ 形状锚点

it('ActionMeta / ActionDoc 是 final 纯静态助手（与 Paths / FieldName / ControllerName 同形）', function () {
    foreach ([ActionMeta::class, ActionDoc::class] as $class) {
        $rc = new ReflectionClass($class);

        expect($rc->isFinal())->toBeTrue("{$class} 必须是 final");
        expect($rc->getNamespaceName())->toBe('Mooeen\Scaffold\Support');
        expect($rc->getConstructor())->toBeNull("{$class} 不该有构造器（无状态）");

        foreach ($rc->getMethods() as $method) {
            expect($method->isStatic())->toBeTrue("{$class}::{$method->getName()}() 必须是 static");
        }
    }
});

it('两个内部专用助手保持 private（外迁时从 Utility 带过来的可见性不许放宽）', function () {
    expect((new ReflectionClass(ActionMeta::class))->getMethod('formatDate')->isPrivate())->toBeTrue();
    expect((new ReflectionClass(ActionDoc::class))->getMethod('parseByLanguages')->isPrivate())->toBeTrue();
    expect((new ReflectionClass(ActionDoc::class))->getMethod('normalizeDocComment')->isPrivate())->toBeTrue();
});

// ---------------------------------------------------------------- fixtures

/**
 * 带 `@package_name` / `@module_name` / `@controller_name` / `@acl` 的控制器替身。
 *
 * 多语言串的写法跟生成器真实产出同形：`en:System|zh-CN:系统|`（结尾必须有 `|`、`,` 或 `}`，
 * 解析正则靠它收尾）。
 *
 * 注意 `@package_name` 三条必须挂在**类**上（写成 `new` 后紧跟 docblock、再写 `class`）
 * 而不是挂在构造器上 —— `parsePMCNames()` 走 `ReflectionClass::getDocComment()`，挂在方法上
 * 它只会读到 `false`，然后三处全空（这正是本 fixture 第一版写错、被第一条断言抓到的形态）。
 */
function actiondoc_fixture_controller(): object
{
    return new /**
     * @package_name en:System|zh-CN:系统|
     * @module_name en:Order|zh-CN:订单|
     * @controller_name en:Order|zh-CN:订单管理|
     */
    class
    {
        /**
         * 订单列表
         *
         * @acl en:Order List|zh-CN:订单列表|desc:分页返回当前用户的订单。|
         */
        public function index(): void {}

        /**
         * 详情
         */
        public function show(): void {}

        public function withRequest(Request $request): void {}

        public function withBuiltinRequest(int $request): void {}

        public function withWrongName(Request $payload): void {}

        public function noParams(): void {}
    };
}

/** 完全没有 DocBlock 的替身（`getDocComment()` 返回 false）。 */
function actiondoc_fixture_bare(): object
{
    return new class
    {
        public function nothing(): void {}
    };
}
