<?php declare(strict_types=1);

use Brick\VarExporter\VarExporter;
use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Generator\UpdateAuthorizationGenerator;
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

        return $plain ? strtolower(class_basename($cls)) . '-' . $m : $cls . '@' . $m;
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

function authGen_make(): UpdateAuthorizationGenerator
{
    return new UpdateAuthorizationGenerator(new NullOutput, app(Filesystem::class), app(Utility::class));
}

function authGen_route(string $fqcn, string $method, ?string $name = null): array
{
    return ['action' => $fqcn . '@' . $method, 'name' => $name];
}

beforeEach(function () {
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
