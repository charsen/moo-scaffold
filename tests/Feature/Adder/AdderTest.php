<?php

declare(strict_types=1);

use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Adder\ControllerAdder;
use Mooeen\Scaffold\Adder\RouterAdder;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Adder 子系统(ControllerAdder / RouterAdder / Adder 基类)的 Pest Feature 覆盖。之前零测试。
 *
 * 把宿主项目指向临时 sandbox(app()->setBasePath),铺好 ControllerAdder/RouterAdder 需要的
 * config('scaffold.controller.admin.*') + stub 路由文件(含字面插入标记 `// :insert_code_here:do_not_delete`),
 * 再通过公开入口 start() 驱动断言。
 *
 * ════════════════════════════════════════════════════════════════════════════
 *  ✅ 历史 bug 已修(src/Adder/Adder.php:70):
 *
 *      protected function getTabs(float $size = 1): string
 *      {
 *          return str_repeat(' ', (int) ($size * 4)); // 加了 (int) 强转
 *      }
 *
 *  当初(无 (int))在 declare(strict_types=1) 下,str_repeat() 第二参收到 float 会无条件
 *  抛 TypeError —— Adder 所有「写文件 / 插路由」路径都先过 getTabs,故曾用 6 个
 *  `toThrow(TypeError,'str_repeat()')` 锚定现状。现已对齐 Generator::getTabs,
 *  下方 6 条全部翻转为 happy-path,按真实产物断言。
 * ════════════════════════════════════════════════════════════════════════════
 *
 * console target 用 Factory(OutputStyle(BufferedOutput)) —— Adder 构造签名是 Command|Factory,
 * Factory 是无需真实 artisan 命令的最轻量满足项,ConsoleUi 渲染只往 BufferedOutput 写、不交互。
 */
beforeEach(function () {
    $this->adderSandbox = sys_get_temp_dir() . '/scaffold_adder_' . uniqid();
    @mkdir($this->adderSandbox, 0777, true);
    $this->adderOrigBase = base_path();
    app()->setBasePath($this->adderSandbox);

    config(['scaffold.author' => 'tester']);
    config(['scaffold.controller.admin' => [
        'name'          => ['zh-CN' => '后台管理', 'en' => 'Admin'],
        'path'          => 'app/Admin/Controllers/',
        'request_path'  => 'app/Admin/Requests/',
        'resource_path' => 'app/Admin/Resources/',
        'route'         => 'routes/admin.php',
    ]]);
    config(['scaffold.resource.path' => 'app/Http/Resources/']);
    config(['scaffold.class.controller' => 'Mooeen\Scaffold\Foundation\Controller']);

    // 全局 resource 兜底目录(checkGlobalResource 会 allFiles 扫这里)。
    @mkdir($this->adderSandbox . '/app/Http/Resources', 0777, true);

    // stub 路由文件,含 RouterAdder 查找的字面插入标记。
    @mkdir($this->adderSandbox . '/routes', 0777, true);

    $this->adderFs      = new Filesystem;
    $this->adderUtility = app(Utility::class);
    $this->adderConsole = new Factory(new OutputStyle(new ArrayInput([]), new BufferedOutput));
});

afterEach(function () {
    app()->setBasePath($this->adderOrigBase);
    $fs = new Filesystem;
    if (is_dir($this->adderSandbox)) {
        $fs->deleteDirectory($this->adderSandbox);
    }
});

function adder_seedRoutes(string $body): void
{
    file_put_contents(test()->adderSandbox . '/routes/admin.php', $body);
}

function adder_makeControllerAdder(): ControllerAdder
{
    return new ControllerAdder(test()->adderConsole, test()->adderFs, test()->adderUtility);
}

function adder_makeRouterAdder(): RouterAdder
{
    return new RouterAdder(test()->adderConsole, test()->adderFs, test()->adderUtility);
}

// ─── Utility 后缀归一化(单一真源,ControllerAdder::start 直接调,无 getTabs,可真断言) ──

it('ensureControllerSuffix 补尾缀、已有不重复、空串原样', function () {
    expect(Utility::ensureControllerSuffix('User'))->toBe('UserController');
    expect(Utility::ensureControllerSuffix('UserController'))->toBe('UserController');
    expect(Utility::ensureControllerSuffix(''))->toBe('');
});

it('stripControllerSuffix 只剥尾缀、中间含 Controller 不动', function () {
    expect(Utility::stripControllerSuffix('UserController'))->toBe('User');
    expect(Utility::stripControllerSuffix('User'))->toBe('User');
    expect(Utility::stripControllerSuffix('ControllerManager'))->toBe('ControllerManager');
});

it('ensure/strip 互为逆操作(短名与 FQCN 都成立)', function () {
    expect(Utility::stripControllerSuffix(Utility::ensureControllerSuffix('Memo')))->toBe('Memo');
    expect(Utility::ensureControllerSuffix(Utility::stripControllerSuffix('MemoController')))->toBe('MemoController');
    expect(Utility::stripControllerSuffix('App\\Admin\\Controllers\\Light\\MemoController'))
        ->toBe('App\\Admin\\Controllers\\Light\\Memo');
});

// ─── RouterAdder::start —— 幂等短路分支(在 getTabs 之前 return false,真断言) ─────

it('RouterAdder:路由已存在时返回 false 且不改动路由文件(幂等短路,不经 getTabs)', function () {
    $existing = "Route::get('light/memos', [App\\Admin\\Controllers\\Light\\MemoController::class, 'index']);";
    $body     = "<?php\n\n{$existing}\n    // :insert_code_here:do_not_delete\n";
    adder_seedRoutes($body);

    $controller = ['class' => 'App\\Admin\\Controllers\\Light\\MemoController', 'action' => 'index'];

    $ok = adder_makeRouterAdder()->start('admin', $controller, 'get light/memos');

    expect($ok)->toBeFalse();
    expect(file_get_contents($this->adderSandbox . '/routes/admin.php'))->toBe($body); // 未被改写
});

// ─── 以下 6 条原为 getTabs bug 锚点,bug 已修,翻转为 happy-path 正向断言 ─────────────

// [原锚 1] RouterAdder 插入分支:不同 action 不命中幂等 → 进入插入。
// 翻转后断言:新 Route::get(...,'index') 行插到 :insert_code_here: 标记之前,start() 返回 true,
// 标记仍保留(下次还能继续插)。
it('RouterAdder 插入分支:不同 action 不命中幂等 → 插入新路由到标记前并返回 true', function () {
    $other  = "Route::get('light/memos', [App\\Admin\\Controllers\\Light\\MemoController::class, 'list']);";
    $marker = '// :insert_code_here:do_not_delete';
    adder_seedRoutes("<?php\n\n{$other}\n    {$marker}\n");

    $controller = ['class' => 'App\\Admin\\Controllers\\Light\\MemoController', 'action' => 'index'];

    $ok = adder_makeRouterAdder()->start('admin', $controller, 'get light/memos');

    expect($ok)->toBeTrue();

    $written   = file_get_contents($this->adderSandbox . '/routes/admin.php');
    $route_str = "Route::get('light/memos', [App\\Admin\\Controllers\\Light\\MemoController::class, 'index']);";

    // 旧 list 路由仍在;新 index 路由被插入;插入标记保留供后续继续插。
    expect($written)->toContain($other);
    expect($written)->toContain($route_str);
    expect($written)->toContain($marker);
    // 新路由出现在标记之前(标记被 route_str + 换行 + 标记 替换)。
    expect(strpos($written, $route_str))->toBeLessThan(strrpos($written, $marker));
});

// [原锚 2] 新建 controller:buildNewController + buildNewControllerTrait。
// 翻转后断言:
//   - app/Admin/Controllers/Light/MemoController.php 落地,namespace + `class MemoController extends Controller`
//   - 作者署名 @Author: tester
//   - 同目录 Traits/MemoTrait.php 落地(buildNewControllerTrait;注意名字是剥后缀后的 Memo+Trait)
//   - 默认 use Mooeen\Scaffold\Foundation\BaseResource;action 签名 `public function index(): BaseResource`
//   - start() 返回 ['class' => 'App\Admin\Controllers\MemoController', 'action' => 'index']
it('新建 controller:落地 controller + trait 文件,注入默认 BaseResource action', function () {
    $result = adder_makeControllerAdder()->start('admin', 'Light', 'Memo', true, 'index', '', '');

    $controllerFile = $this->adderSandbox . '/app/Admin/Controllers/Light/MemoController.php';
    $traitFile      = $this->adderSandbox . '/app/Admin/Controllers/Light/Traits/MemoTrait.php';

    expect($controllerFile)->toBeFile();
    expect($traitFile)->toBeFile();

    $controllerSrc = file_get_contents($controllerFile);
    expect($controllerSrc)->toContain('namespace App\\Admin\\Controllers\\Light;');
    expect($controllerSrc)->toContain('class MemoController extends Controller');
    expect($controllerSrc)->toContain('@Author: tester');
    expect($controllerSrc)->toContain('use App\\Admin\\Controllers\\Light\\Traits\\MemoTrait;');
    expect($controllerSrc)->toContain('use Mooeen\\Scaffold\\Foundation\\BaseResource;');
    expect($controllerSrc)->toContain('public function index(): BaseResource');

    $traitSrc = file_get_contents($traitFile);
    expect($traitSrc)->toContain('namespace App\\Admin\\Controllers\\Light\\Traits;');
    expect($traitSrc)->toContain('trait MemoTrait');

    expect($result)->toBe([
        'class'  => 'App\\Admin\\Controllers\\MemoController',
        'action' => 'index',
    ]);
});

// [原锚 3] 新建 controller 传无后缀名:文件名归一化为 MemoController(不双后缀)。
// 翻转后断言:存在 .../Light/MemoController.php,不存在 .../Light/MemoControllerController.php。
it('新建 controller 传无后缀名:文件名归一化为 MemoController,不出现双后缀', function () {
    adder_makeControllerAdder()->start('admin', 'Light', 'Memo', true, 'index', '', '');

    expect($this->adderSandbox . '/app/Admin/Controllers/Light/MemoController.php')->toBeFile();
    expect($this->adderSandbox . '/app/Admin/Controllers/Light/MemoControllerController.php')->not->toBeFile();
});

// [原锚 4] 新建 controller 带 request + resource:buildRequest / buildResource。
// 翻转后断言:
//   - app/Admin/Requests/Light/Memo/StoreMemoRequest.php 落地(class StoreMemoRequest extends FormRequest)
//   - app/Admin/Resources/Light/Memo/MemoResource.php 落地(class MemoResource extends BaseResource)
//   - controller use 两者,签名 `public function store(StoreMemoRequest $request): MemoResource`
it('新建 controller 带 request+resource:生成 Request/Resource 文件并 use 注入', function () {
    adder_makeControllerAdder()->start('admin', 'Light', 'Memo', true, 'store', 'storeMemo', 'memo');

    $requestFile  = $this->adderSandbox . '/app/Admin/Requests/Light/Memo/StoreMemoRequest.php';
    $resourceFile = $this->adderSandbox . '/app/Admin/Resources/Light/Memo/MemoResource.php';

    expect($requestFile)->toBeFile();
    expect($resourceFile)->toBeFile();

    expect(file_get_contents($requestFile))
        ->toContain('namespace App\\Admin\\Requests\\Light\\Memo;')
        ->toContain('class StoreMemoRequest extends FormRequest');

    expect(file_get_contents($resourceFile))
        ->toContain('namespace App\\Admin\\Resources\\Light\\Memo;')
        ->toContain('class MemoResource extends BaseResource');

    $controllerSrc = file_get_contents($this->adderSandbox . '/app/Admin/Controllers/Light/MemoController.php');
    expect($controllerSrc)
        ->toContain('use App\\Admin\\Requests\\Light\\Memo\\StoreMemoRequest;')
        ->toContain('use App\\Admin\\Resources\\Light\\Memo\\MemoResource;')
        ->toContain('public function store(StoreMemoRequest $request): MemoResource');
});

// [原锚 5] 新建 controller resource 带 Collection 后缀。
// 翻转后断言:use BaseResourceCollection;返回类型 BaseResourceCollection;body `MemoResource::collection($result)`;
//            MemoResource.php 仍按去掉 Collection 后缀的资源名落地。
it('新建 controller resource 带 Collection 后缀:走 BaseResourceCollection 返回类型', function () {
    adder_makeControllerAdder()->start('admin', 'Light', 'Memo', true, 'index', '', 'memoCollection');

    $resourceFile = $this->adderSandbox . '/app/Admin/Resources/Light/Memo/MemoResource.php';
    expect($resourceFile)->toBeFile();
    expect(file_get_contents($resourceFile))->toContain('class MemoResource extends BaseResource');

    $controllerSrc = file_get_contents($this->adderSandbox . '/app/Admin/Controllers/Light/MemoController.php');
    expect($controllerSrc)
        ->toContain('use Mooeen\\Scaffold\\Foundation\\BaseResourceCollection;')
        ->toContain('use App\\Admin\\Resources\\Light\\Memo\\MemoResource;')
        ->toContain('public function index(): BaseResourceCollection')
        ->toContain('return MemoResource::collection($result);');
});

// [原锚 6] 非新建模式追加 action:getActionFunction 追加到类闭合 } 之前。
// 先手工铺一个最小 controller(不经 Adder 写),再走 new_controller=false 追加 action。
// 翻转后断言:原 controller 末尾被插入 `public function edit(): BaseResource`,use BaseResource 注入,
//            返回 ['class' => 'App\Admin\Controllers\Light\MemoController', 'action' => 'edit']。
it('非新建模式追加 action:在类闭合前插入 action 并注入 use', function () {
    $dir = $this->adderSandbox . '/app/Admin/Controllers/Light';
    @mkdir($dir, 0777, true);
    // 含 use 行(getFirstUseLine 命中)+ 独立闭合 }(getEndLine 命中)。
    file_put_contents(
        $dir . '/MemoController.php',
        "<?php declare(strict_types=1);\n\nnamespace App\\Admin\\Controllers\\Light;\n\nuse Mooeen\\Scaffold\\Foundation\\Controller;\n\nclass MemoController extends Controller\n{\n}\n"
    );

    $result = adder_makeControllerAdder()->start('admin', 'Light', 'Light/MemoController', false, 'edit', '', '');

    $controllerSrc = file_get_contents($dir . '/MemoController.php');
    expect($controllerSrc)
        ->toContain('use Mooeen\\Scaffold\\Foundation\\BaseResource;')
        ->toContain('public function edit(): BaseResource');

    // action 落在类闭合 } 之前。
    expect(strpos($controllerSrc, 'public function edit(): BaseResource'))
        ->toBeLessThan(strrpos($controllerSrc, '}'));

    expect($result)->toBe([
        'class'  => 'App\\Admin\\Controllers\\Light\\MemoController',
        'action' => 'edit',
    ]);
});

// ─── checkGlobalResource:复用已存在 resource 时拼 use 语句 ─────────────────────────
// resource.path 带尾 `/`(config 默认 'app/Http/Resources/')→ formatNameSpace 产出尾部带 `\` 的
// namespace,再拼 `\\{class}` 得到双反斜杠 `Resources\\Foo`(空命名空间段)→ 生成的 controller use
// 语句 PHP 语法错。rtrim namespace 尾部反斜杠修(2026-06-09)。
it('checkGlobalResource · resource.path 带尾 / 时复用 resource 的 use 语句无双反斜杠', function () {
    // 预放一个已存在的全局 Resource → 触发 checkGlobalResource 命中分支(否则总返 false 走新建路径)
    file_put_contents(
        $this->adderSandbox . '/app/Http/Resources/FooResource.php',
        "<?php\n\nnamespace App\\Http\\Resources;\n\nclass FooResource {}\n"
    );
    config(['scaffold.resource.path' => 'app/Http/Resources/']); // 尾斜杠是 bug 触发条件

    $adder = adder_makeControllerAdder();
    $ref   = new ReflectionMethod($adder, 'checkGlobalResource');
    $ref->setAccessible(true);

    $use = $ref->invoke($adder, 'FooResource');

    expect($use)->toBeString();
    expect($use)->toStartWith('use ');
    expect(rtrim($use))->toEndWith('FooResource;');
    // 关键:无双反斜杠(bug 版本产出 `Resources\\FooResource`,PHP 语法错)
    expect($use)->not->toContain('\\\\');
});

// ─── AdderCommand::parseAction —— 空/null 输入不崩(2026-06-21:原 explode(null) TypeError)──
it('parseAction:空/null 输入 action 为空且不崩,多空格容错', function () {
    $cmd = (new ReflectionClass(\Mooeen\Scaffold\Command\AdderCommand::class))->newInstanceWithoutConstructor();
    $m   = new ReflectionMethod($cmd, 'parseAction');
    $m->setAccessible(true);

    expect($m->invoke($cmd, null))->toBe(['', '', '']);          // 原先 explode(' ', null) → TypeError
    expect($m->invoke($cmd, '   '))->toBe(['', '', '']);
    expect($m->invoke($cmd, 'create  StoreRequest'))->toBe(['create', 'StoreRequest', '']);   // 多空格容错
    expect($m->invoke($cmd, 'index IndexRequest IndexResource'))->toBe(['index', 'IndexRequest', 'IndexResource']);
});

// ─── AdderCommand::getControllers —— 列表项带 folder/ 分隔(2026-06-21:原 folder+name 糊一起)──
it('getControllers:列表项 = folder/name(Market/BaseServiceController),不再糊成 MarketBaseServiceController', function () {
    $dir = base_path('app/Admin/Controllers/Market');
    @mkdir($dir, 0777, true);
    file_put_contents($dir . '/BaseServiceController.php', "<?php\n\nclass BaseServiceController {}\n");

    $cmd    = (new ReflectionClass(\Mooeen\Scaffold\Command\AdderCommand::class))->newInstanceWithoutConstructor();
    $fsProp = new ReflectionProperty($cmd, 'filesystem');
    $fsProp->setAccessible(true);
    $fsProp->setValue($cmd, app(Filesystem::class));

    $m = new ReflectionMethod($cmd, 'getControllers');
    $m->setAccessible(true);
    $list = $m->invoke($cmd, 'app/Admin/Controllers/', 'Market');

    expect($list)->toContain('Market/BaseServiceController');         // 带 / 分隔、可读
    expect($list)->not->toContain('MarketBaseServiceController');     // 不再糊一起
    expect($list)->toContain('<NEW_ONE>');                           // 仍保留新建项
});
