<?php declare(strict_types=1);

use Brick\VarExporter\VarExporter;
use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Foundation\Controller;
use Mooeen\Scaffold\Generator\UpdateAuthorizationGenerator;
use Mooeen\Scaffold\Support\AclActionResolver;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Yaml\Yaml;

/**
 * UpdateAuthorizationGenerator 回归锁(此前 0 测试)。
 *
 * 整链路:start($app, $routes) 全量重建 config/actions.php + lang/{lang}/actions.php
 * + scaffold/acl/{app}.yaml,内容完全由 routes 决定。
 *
 * generator 不读 storage 缓存,而是直接对路由里的 controller 类做反射(parsePMCNames /
 * parseActionInfo 读 docblock,AclActionResolver::resolve 调 controller 的 formatAclName)。
 * 所以这里用真·fixture controller 类(带 @module_name/@controller_name + @acl docblock +
 * formatAclName),手工构造 routes 数组(shape 同 RouterTool::storeActions 输出:每项含 action)。
 *
 * 同时锁 isCrossControllerTransform / getMd5 / resolveAuthorizationInfo 分支。
 *
 * fixture 类名 + 全局函数用唯一前缀 authGen_ 避免 Pest 顶层 redeclare。
 */

/* ===== fixture controllers(docblock 协议 + AclActionResolver formatAclName) ===== */

/**
 * @package_name en:Demo|zh-CN:演示|
 * @module_name en:Content|zh-CN:内容|
 * @controller_name en:Article|zh-CN:文章|
 */
class authGen_ArticleController
{
    /**
     * 文章列表
     *
     * @acl en:List Articles|zh-CN:文章列表|desc:列出全部文章|
     */
    public function index() {}

    /**
     * 创建文章
     *
     * @acl en:Create Article|zh-CN:创建文章|desc:新增一篇|
     */
    public function store() {}

    /**
     * 健康检查(无授权标注,落入白名单)
     */
    public function ping() {}

    // AclActionResolver 反射调:plain=短名-方法,full=类@方法
    public function formatAclName(string $target, bool $plain): string
    {
        [$cls, $m] = explode('::', $target);

        return $plain ? strtolower(class_basename($cls)) . '-' . strtolower($m) : $cls . '@' . strtolower($m);
    }
}

/**
 * @module_name en:Content|zh-CN:内容|
 * @controller_name en:Tag|zh-CN:标签|
 */
class authGen_TagController
{
    /**
     * 标签列表
     *
     * @acl en:List Tags|zh-CN:标签列表|desc:列出标签|
     */
    public function index() {}

    public function formatAclName(string $target, bool $plain): string
    {
        [$cls, $m] = explode('::', $target);

        return $plain ? strtolower(class_basename($cls)) . '-' . $m : $cls . '@' . $m;
    }
}

/**
 * @module_name en:Content|zh-CN:内容|
 * @controller_name en:Preview|zh-CN:预览|
 */
class authGen_PreviewController extends authGen_ArticleController
{
    /**
     * 更新文章
     *
     * @acl en:Update Article|zh-CN:更新文章|desc:修改一篇|
     */
    public function update() {}

    /**
     * 预览文章
     *
     * @acl en:Preview Article|zh-CN:预览文章|desc:预览不保存|
     */
    public function preview() {}

    /**
     * 跨控制器预览
     *
     * @acl en:Cross Preview|zh-CN:跨控制器预览|desc:预览不保存|
     */
    public function crossPreview() {}

    public function getTransformMethods(): array
    {
        return [
            'preview'      => ['store', 'Store', 'update'],
            'crossPreview' => [authGen_ArticleController::class . '::store', authGen_TagController::class . '::index'],
        ];
    }
}

function authGen_make(): UpdateAuthorizationGenerator
{
    return new UpdateAuthorizationGenerator(new NullOutput, app(Filesystem::class), app(Utility::class));
}

/**
 * @module_name en:Content|zh-CN:内容|
 * @controller_name en:Normalized|zh-CN:归一化|
 */
class authGen_NormalizedController extends Controller
{
    protected array $transform_methods = ['preview' => ['store', 'Store', 'update']];

    /**
     * @acl {en: Create Entry, zh-CN: 创建条目, desc: 创建描述}
     */
    public function store() {}

    /**
     * @acl {en: Update Entry, zh-CN: 更新条目, desc: 更新描述}
     */
    public function update() {}

    /**
     * @acl {en: Preview Entry, zh-CN: 预览条目, desc: 预览描述}
     */
    public function preview() {}
}

it('真实 Controller key 归一化去重后仍按目标关联取文案', function (bool $md5) {
    config(['scaffold.authorization.md5' => $md5]);
    $resolver = new AclActionResolver;
    $acl      = $resolver->resolve(authGen_NormalizedController::class, 'preview');
    expect($acl['targets'])->toHaveCount(3)->and($acl['keys'])->toHaveCount(2);
    authGen_make()->start('admin', [authGen_route(authGen_NormalizedController::class, 'preview')]);
    $labels = (require lang_path('zh-CN/actions.php'))['admin'];
    foreach (['store' => '创建', 'update' => '更新'] as $method => $label) {
        $key = $resolver->resolve(authGen_NormalizedController::class, $method)['key'];
        expect($labels[$key])->toBe($label . '条目')->and($labels[$key . '-desc'])->toBe($label . '描述');
    }
})->with([false, true]);

function authGen_route(string $fqcn, string $method, ?string $name = null): array
{
    return ['action' => $fqcn . '@' . $method, 'name' => $name];
}

beforeEach(function () {
    app(Filesystem::class)->ensureDirectoryExists(lang_path('en'));
    app(Filesystem::class)->ensureDirectoryExists(lang_path('zh-CN'));
    config()->set('scaffold.languages', ['en', 'zh-CN']);
    config()->set('scaffold.author', 'tester');
    config()->set('scaffold.authorization.md5', false); // 关 md5,key 保持可读字面便于断言
    config()->set('scaffold.controller.admin', [
        'name'     => ['zh-CN' => '后台管理', 'en' => 'Admin'],
        'api_name' => '后台管理',
        'path'     => 'app/Admin/Controllers/',
    ]);
});

afterEach(function () {
    $fs = app(Filesystem::class);
    $fs->delete(config_path('actions.php'));
    $fs->delete(lang_path('en/actions.php'));
    $fs->delete(lang_path('zh-CN/actions.php'));
    $fs->deleteDirectory(base_path('scaffold/acl'));
});

/* ---------------------------------------------------------------------------
 * 整链路 · start() 三类产物
 * ------------------------------------------------------------------------ */

it('start() 写 config/actions.php:非白名单 action 按 module>controller 归类,白名单单列', function () {
    $routes = [
        authGen_route(authGen_ArticleController::class, 'index', 'article.index'),
        authGen_route(authGen_ArticleController::class, 'store', 'article.store'),
        authGen_route(authGen_ArticleController::class, 'ping', 'article.ping'), // 无 @acl → whitelist
    ];

    expect(authGen_make()->start('admin', $routes))->toBeTrue();

    $file = config_path('actions.php');
    expect(file_exists($file))->toBeTrue();

    $config = require $file;
    expect($config)->toHaveKey('admin');

    // ping 无 @acl → 进 whitelist(key 由 resolver formatAclName 生成:authgen_articlecontroller@ping)
    $whitelist = $config['admin']['whitelist'];
    expect($whitelist)->toContain('authGen_ArticleController@ping');

    // index/store 进 actions 树(module-key > controller-key > [action keys])
    $flat = json_encode($config['admin']['actions']);
    expect($flat)->toContain('authGen_ArticleController@index');
    expect($flat)->toContain('authGen_ArticleController@store');
    // whitelist 的 ping 不在 actions 树
    expect($flat)->not->toContain('authGen_ArticleController@ping');
});

it('start() 写 lang/{lang}/actions.php:app/module/controller/action 文案齐全', function () {
    $routes = [
        authGen_route(authGen_ArticleController::class, 'index', 'article.index'),
    ];

    expect(authGen_make()->start('admin', $routes))->toBeTrue();

    $en = require lang_path('en/actions.php');
    $zh = require lang_path('zh-CN/actions.php');

    // app 名(来自 controller.admin.name)
    expect($en['admin']['app-admin'])->toBe('Admin');
    expect($zh['admin']['app-admin'])->toBe('后台管理');

    // action 文案来自 @acl(md5 关 → key 即 plain key)
    $actionKey = 'authGen_ArticleController@index';
    expect($en['admin'][$actionKey])->toBe('List Articles');
    expect($zh['admin'][$actionKey])->toBe('文章列表');
    expect($en['admin']["{$actionKey}-desc"])->toBe('列出全部文章');

    // module / controller 文案(@module_name / @controller_name 解析)
    $hasModuleName = collect($en['admin'])->contains('Content');
    $hasCtrlName   = collect($en['admin'])->contains('Article');
    expect($hasModuleName)->toBeTrue();
    expect($hasCtrlName)->toBeTrue();
});

it('start() 写 scaffold/acl/{app}.yaml:meta stats + modules>controllers>actions 树', function () {
    $routes = [
        authGen_route(authGen_ArticleController::class, 'index', 'article.index'),
        authGen_route(authGen_ArticleController::class, 'store', 'article.store'),
        authGen_route(authGen_ArticleController::class, 'ping', 'article.ping'),
        authGen_route(authGen_TagController::class, 'index', 'tag.index'),
    ];

    expect(authGen_make()->start('admin', $routes))->toBeTrue();

    $file = base_path('scaffold/acl/admin.yaml');
    expect(file_exists($file))->toBeTrue();

    $doc = Yaml::parseFile($file);

    expect($doc['meta']['app'])->toBe('admin');
    expect($doc['meta']['generated_by'])->toBeString()->not->toBe('');
    // stats:4 action,2 controller(Article+Tag),其中 1 个 whitelist(ping)
    expect($doc['meta']['stats']['action_count'])->toBe(4);
    expect($doc['meta']['stats']['controller_count'])->toBe(2);
    expect($doc['meta']['stats']['whitelist_count'])->toBe(1);

    // modules > controllers 结构
    expect($doc['modules'])->toBeArray()->not->toBeEmpty();
    $controllers = $doc['modules'][0]['controllers'];
    expect($controllers)->toBeArray();
    $classes = array_column($controllers, 'class');
    expect($classes)->toContain(authGen_ArticleController::class);
});

it('start() 全量重写:第二次跑只保留最新 routes(旧 action 不残留)', function () {
    // 第一次:含 store
    authGen_make()->start('admin', [
        authGen_route(authGen_ArticleController::class, 'index'),
        authGen_route(authGen_ArticleController::class, 'store'),
    ]);

    // 第二次:只剩 index
    authGen_make()->start('admin', [
        authGen_route(authGen_ArticleController::class, 'index'),
    ]);

    $config = require config_path('actions.php');
    $flat   = json_encode($config['admin']['actions']);

    expect($flat)->toContain('authGen_ArticleController@index');
    expect($flat)->not->toContain('authGen_ArticleController@store'); // 全量重建,store 没了
});

it('start() 清理已从 controller 注册表移除的旧 app 聚合键', function () {
    app(Filesystem::class)->put(config_path('actions.php'), <<<'PHP'
<?php return ['admin' => ['whitelist' => [], 'actions' => []], 'api' => ['whitelist' => ['old'], 'actions' => []], 'meta' => ['version' => 1]];
PHP);
    config()->set('actions', require config_path('actions.php'));
    app(Filesystem::class)->ensureDirectoryExists(lang_path('en'));
    app(Filesystem::class)->ensureDirectoryExists(lang_path('zh-CN'));
    app(Filesystem::class)->put(lang_path('en/actions.php'), "<?php return ['api' => ['app-api' => 'Api'], 'meta' => ['version' => 1]];");
    app(Filesystem::class)->put(lang_path('zh-CN/actions.php'), "<?php return ['api' => ['app-api' => '接口'], 'meta' => ['version' => 1]];");

    authGen_make()->start('admin', [authGen_route(authGen_ArticleController::class, 'index')]);

    expect(require config_path('actions.php'))->not->toHaveKey('api')
        ->and(require lang_path('en/actions.php'))->not->toHaveKey('api')
        ->and(require lang_path('zh-CN/actions.php'))->not->toHaveKey('api')
        ->and(require config_path('actions.php'))->toHaveKey('meta')
        ->and(require lang_path('en/actions.php'))->toHaveKey('meta')
        ->and(require lang_path('zh-CN/actions.php'))->toHaveKey('meta');
});

/* ---------------------------------------------------------------------------
 * 产物 · 内容无变化时不重写(避免只刷生成戳的假 diff)
 * ------------------------------------------------------------------------ */

/** moo:auth 的三类产物 */
function authGen_artifacts(): array
{
    return [
        base_path('scaffold/acl/admin.yaml'),
        config_path('actions.php'),
        lang_path('en/actions.php'),
        lang_path('zh-CN/actions.php'),
    ];
}

it('多目标转换逐 key 采用目标文案，不被别名路由或路由顺序覆盖', function (bool $reverse, bool $onlyAlias) {
    $routes = [
        authGen_route(authGen_PreviewController::class, 'store'),
        authGen_route(authGen_PreviewController::class, 'update'),
        authGen_route(authGen_ArticleController::class, 'store'),
        authGen_route(authGen_TagController::class, 'index'),
    ];
    if ($onlyAlias) {
        $routes = [];
    }
    $routes[] = authGen_route(authGen_PreviewController::class, 'preview');
    $routes[] = authGen_route(authGen_PreviewController::class, 'crossPreview');
    if ($reverse) {
        $routes = array_reverse($routes);
    }
    authGen_make()->start('admin', $routes);
    $en = (require lang_path('en/actions.php'))['admin'];
    $zh = (require lang_path('zh-CN/actions.php'))['admin'];
    foreach ([
        'authGen_PreviewController@store'  => ['Create Article', '创建文章', '新增一篇'],
        'authGen_PreviewController@update' => ['Update Article', '更新文章', '修改一篇'],
        'authGen_ArticleController@store'  => ['Create Article', '创建文章', '新增一篇'],
        'authGen_TagController@index'      => ['List Tags', '标签列表', '列出标签'],
    ] as $key => [$english, $chinese, $description]) {
        expect($en[$key])->toBe($english)
            ->and($zh[$key])->toBe($chinese)
            ->and($zh[$key . '-desc'])->toBe($description);
    }
    $config = (require config_path('actions.php'))['admin'];
    expect($config['whitelist'])->toBe([]);
    expect(json_encode($config['actions']))->not->toContain('@preview')->not->toContain('@crossPreview');
    $document = Yaml::parseFile(base_path('scaffold/acl/admin.yaml'));
    $actions  = collect($document['modules'])->flatMap(fn ($module) => $module['controllers'])
        ->flatMap(fn ($controller) => $controller['actions']);
    $preview = $actions->firstWhere('action', 'preview');
    expect($preview['name']['zh-CN'])->toBe('预览文章')
        ->and($preview['keys'])->toBe(['authGen_PreviewController@store', 'authGen_PreviewController@update'])
        ->and($preview['whitelist'])->toBeFalse();
    $before = array_map('file_get_contents', authGen_artifacts());
    authGen_make()->start('admin', $routes);
    expect(array_map('file_get_contents', authGen_artifacts()))->toBe($before);
})->with([false, true])->with([false, true]);

/** 把已生成产物的生成戳改老,模拟"上一次跑命令留下的文件";返回改后内容 */
function authGen_ageStamp(string $file): string
{
    $content = (string) file_get_contents($file);

    $aged = str_ends_with($file, '.yaml')
        ? preg_replace(
            ["/generated_at: '.+'/", "/generated_by: '.+'/"],
            ["generated_at: '2026-01-01 00:00:00'", "generated_by: 'previous-runner'"],
            $content,
        )
        : preg_replace(
            ['/@generated_at .+/', '/@generated_by .+/'],
            ['@generated_at 2026-01-01 00:00:00', '@generated_by previous-runner'],
            $content,
        );

    file_put_contents($file, (string) $aged);

    return (string) $aged;
}

it('php 产物带生成戳注释头,且 require 回来仍是数组', function () {
    authGen_make()->start('admin', [authGen_route(authGen_ArticleController::class, 'index')]);

    foreach ([config_path('actions.php'), lang_path('en/actions.php'), lang_path('zh-CN/actions.php')] as $file) {
        $content = (string) file_get_contents($file);

        // 开头形态按 host Pint 口径钉死:declare_strict_types + linebreak_after_opening_tag=false
        expect($content)->toStartWith('<?php declare(strict_types=1);' . PHP_EOL . PHP_EOL . '/*')
            ->toContain(' * @generated_by ')
            ->toContain(' * @generated_at ')
            ->toContain('请勿手改')
            ->and(require $file)->toBeArray()->toHaveKey('admin');
    }
});

it('三类产物 · 同一组 routes 重跑全部不重写,生成戳保持原值', function () {
    $routes = [
        authGen_route(authGen_ArticleController::class, 'index'),
        authGen_route(authGen_TagController::class, 'index'),
    ];

    authGen_make()->start('admin', $routes);

    $before = [];
    foreach (authGen_artifacts() as $file) {
        $before[$file] = authGen_ageStamp($file);
    }

    authGen_make()->start('admin', $routes);

    foreach (authGen_artifacts() as $file) {
        expect(file_get_contents($file))->toBe($before[$file], $file . ' 不该被重写');
    }
});

it('三类产物 · routes 真变化时全部重写并刷新生成戳', function () {
    authGen_make()->start('admin', [authGen_route(authGen_ArticleController::class, 'index')]);

    $before = [];
    foreach (authGen_artifacts() as $file) {
        $before[$file] = authGen_ageStamp($file);
    }

    authGen_make()->start('admin', [
        authGen_route(authGen_ArticleController::class, 'index'),
        authGen_route(authGen_ArticleController::class, 'store'),
    ]);

    foreach (authGen_artifacts() as $file) {
        expect(file_get_contents($file))->not->toBe($before[$file], $file . ' 应被重写')
            ->not->toContain('2026-01-01 00:00:00')
            ->not->toContain('previous-runner');
    }

    // 新 action 确实落进了 config 与 acl(不只是时间戳变了)
    expect(file_get_contents(config_path('actions.php')))->toContain('authGen_ArticleController@store')
        ->and(file_get_contents(base_path('scaffold/acl/admin.yaml')))->toContain('authGen_ArticleController@store');
});

it('2.1.16 之前的无头部产物被补写一次头部,补完后保持稳定', function () {
    $routes = [authGen_route(authGen_ArticleController::class, 'index')];
    authGen_make()->start('admin', $routes);

    // 退回旧格式:只有 <?php + return,没有生成戳头部
    $file   = config_path('actions.php');
    $legacy = '<?php' . PHP_EOL . 'return ' . VarExporter::export(require $file) . ';' . PHP_EOL;
    file_put_contents($file, $legacy);

    // 数组内容一致但缺头部 → 补写一次
    authGen_make()->start('admin', $routes);
    expect(file_get_contents($file))->not->toBe($legacy)->toContain('@generated_at ');

    // 补完之后不再反复刷
    $stamped = authGen_ageStamp($file);
    authGen_make()->start('admin', $routes);
    expect(file_get_contents($file))->toBe($stamped);
});

/* ---------------------------------------------------------------------------
 * 纯方法 · getMd5 / isCrossControllerTransform
 * ------------------------------------------------------------------------ */
it('getMd5 · md5 开关 on → 16 位截断,off → 原样返回', function () {
    $gen = authGen_make();
    $ref = new ReflectionMethod($gen, 'getMd5');
    $ref->setAccessible(true);

    config()->set('scaffold.authorization.md5', false);
    expect($ref->invoke($gen, 'admin-content-article'))->toBe('admin-content-article');

    config()->set('scaffold.authorization.md5', true);
    $hashed = $ref->invoke($gen, 'admin-content-article');
    expect($hashed)->toHaveLength(16);
    expect($hashed)->toBe(substr(md5('admin-content-article'), 8, 16));
});

it('isCrossControllerTransform · 仅当 transformed 且 target 指向别的 controller 时为 true', function () {
    $gen = authGen_make();
    $ref = new ReflectionMethod($gen, 'isCrossControllerTransform');
    $ref->setAccessible(true);

    // 未 transform → false
    expect($ref->invoke($gen, [
        'acl_transformed' => false,
        'acl_targets'     => ['Foo::index'],
        'controller'      => 'Foo',
    ]))->toBeFalse();

    // transform 但 target 仍是同 controller → false(同 controller 内部 create→store)
    expect($ref->invoke($gen, [
        'acl_transformed' => true,
        'acl_targets'     => ['Foo::store'],
        'controller'      => 'Foo',
    ]))->toBeFalse();

    // transform 且 target 指向别的 controller → true(跨 controller 复用 ACL key)
    expect($ref->invoke($gen, [
        'acl_transformed' => true,
        'acl_targets'     => ['Bar::store'],
        'controller'      => 'Foo',
    ]))->toBeTrue();

    // 空 targets / 空 controller → false(防御分支)
    expect($ref->invoke($gen, [
        'acl_transformed' => true,
        'acl_targets'     => [],
        'controller'      => 'Foo',
    ]))->toBeFalse();
});

it('VarExporter 产出可 require 的 PHP 数组(actions.php 与 lang 都依赖)', function () {
    // 锁住 generator 用的导出器行为,actions.php 必须能被 require 回数组
    $code = '<?php return ' . VarExporter::export(['admin' => ['whitelist' => [], 'actions' => []]]) . ';';
    $tmp  = tempnam(sys_get_temp_dir(), 'authgen');
    file_put_contents($tmp, $code);
    $back = require $tmp;
    unlink($tmp);

    expect($back)->toBe(['admin' => ['whitelist' => [], 'actions' => []]]);
});
