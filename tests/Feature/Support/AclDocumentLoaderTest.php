<?php declare(strict_types=1);

use Mooeen\Scaffold\Support\AclDocumentLoader;

/**
 * AclDocumentLoader 回归锁（此前 0 测试）——RouteController ACL 抽屉的数据来源：读
 * scaffold/acl/{app}.yaml（由 moo:acl 生成），压平成 class@method 索引、跨 app 归一化对照。
 * 锁住:缺文件/损坏 yaml 的 default 兜底、meta/stats 强类型化、modules→controllers→actions
 * 压平 + 同 action 取第一条、name 的 zh-CN/字符串两种形态、normalizeKey 去 app 前缀、
 * 跨 app 反向索引、exists()。
 *
 * sandbox:getAclPath() 硬编码 base_path('scaffold/acl/')，setBasePath 把它落 temp dir。
 */
function aclLoader_writeYaml(string $app, string $body): void
{
    $dir = base_path('scaffold/acl');
    @mkdir($dir, 0755, true);
    file_put_contents($dir . '/' . $app . '.yaml', $body);
}

beforeEach(function () {
    $this->sandbox = sys_get_temp_dir() . '/scaffold_acl_' . uniqid();
    @mkdir($this->sandbox, 0755, true);
    $this->origBase = base_path();
    app()->setBasePath($this->sandbox);
    $this->loader = app(AclDocumentLoader::class);
});

afterEach(function () {
    app()->setBasePath($this->origBase);
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->sandbox, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($rii as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($this->sandbox);
});

it('yaml 不存在 → 返回 default 空结构', function () {
    $doc = $this->loader->loadApp('admin', '后台');

    expect($doc['meta']['app'])->toBe('admin');
    expect($doc['meta']['app_name'])->toBe('后台');
    expect($doc['meta']['path'])->toBe('scaffold/acl/admin.yaml');
    expect($doc['meta']['stats'])->toBe([
        'module_count'     => 0,
        'controller_count' => 0,
        'action_count'     => 0,
        'whitelist_count'  => 0,
    ]);
    expect($doc['modules'])->toBe([]);
    expect($this->loader->exists('admin'))->toBeFalse();
});

it('损坏 yaml → 退回 default（不抛）', function () {
    aclLoader_writeYaml('admin', "meta: [broken\n : :");

    $doc = $this->loader->loadApp('admin', '后台');

    expect($doc['modules'])->toBe([]);
    expect($doc['meta']['app'])->toBe('admin');
    // 文件确实存在（只是内容坏）
    expect($this->loader->exists('admin'))->toBeTrue();
});

it('loadApp 把 meta/stats 强类型化、modules 转成 list', function () {
    aclLoader_writeYaml('admin', <<<'YAML'
        meta:
          app: admin
          app_name: 后台管理
          generated_at: '2026-01-01 10:00:00'
          generated_by: charsen
          stats:
            module_count: '3'
            controller_count: 5
            action_count: 12
            whitelist_count: 2
        modules:
          m1: { name: 用户模块, controllers: [] }
          m2: { name: 订单模块, controllers: [] }
        YAML);

    $doc = $this->loader->loadApp('admin', 'fallback名');

    expect($doc['meta']['app'])->toBe('admin');
    expect($doc['meta']['app_name'])->toBe('后台管理'); // yaml 优先于参数
    expect($doc['meta']['generated_by'])->toBe('charsen');
    expect($doc['meta']['stats']['module_count'])->toBe(3); // '3' → int
    expect($doc['meta']['stats']['controller_count'])->toBe(5);
    // modules map → list（array_values）
    expect(array_is_list($doc['modules']))->toBeTrue();
    expect($doc['modules'])->toHaveCount(2);
});

it('indexByControllerAction 压平 module→controller→action 成 class@method 索引', function () {
    aclLoader_writeYaml('admin', <<<'YAML'
        meta: { app: admin }
        modules:
          - name: { zh-CN: 图书模块 }
            controllers:
              - class: App\Http\Controllers\BookController
                name: { zh-CN: 图书 }
                actions:
                  - action: index
                    plain_key: admin-book-index
                    key: App\Http\Controllers\BookController@index
                    title: 列表
                    name: { zh-CN: 图书列表 }
                    whitelist: true
                  - action: store
                    plain_key: admin-book-store
                    title: 新增
                    whitelist: false
                    acl_transformed: true
                    acl_targets: ['App\Http\Controllers\BookController@store', '']
        YAML);

    $index = $this->loader->indexByControllerAction('admin');
    $book  = $index['App\Http\Controllers\BookController'];

    expect($book)->toHaveKeys(['index', 'store']);
    expect($book['index']['plain_key'])->toBe('admin-book-index');
    expect($book['index']['zh_name'])->toBe('图书列表');
    expect($book['index']['whitelist'])->toBeTrue();
    expect($book['index']['module_zh'])->toBe('图书模块');
    expect($book['index']['controller_zh'])->toBe('图书');
    expect($book['store']['whitelist'])->toBeFalse();
    expect($book['store']['acl_transformed'])->toBeTrue();
    expect($book['store']['acl_targets'])->toBe(['App\Http\Controllers\BookController@store']); // 空串被过滤
});

it('indexByControllerAction 同一 action 出现多次只取第一条；跳过空 class/method', function () {
    aclLoader_writeYaml('admin', <<<'YAML'
        meta: { app: admin }
        modules:
          - name: M
            controllers:
              - class: App\X
                actions:
                  - { action: store, title: 第一条 }
                  - { action: store, title: 第二条重复 }
                  - { action: '', title: 空方法跳过 }
              - class: ''
                actions:
                  - { action: index, title: 空类跳过 }
        YAML);

    $index = $this->loader->indexByControllerAction('admin');

    expect(array_keys($index))->toBe(['App\X']);     // 空 class 整个跳过
    expect(array_keys($index['App\X']))->toBe(['store']); // 空 method 跳过
    expect($index['App\X']['store']['title'])->toBe('第一条'); // 取第一条
});

it('name 支持 zh-CN map 与纯字符串两种形态', function () {
    aclLoader_writeYaml('admin', <<<'YAML'
        meta: { app: admin }
        modules:
          - name: 纯字符串模块
            controllers:
              - class: App\Y
                name: 纯字符串控制器
                actions:
                  - { action: index, name: 纯字符串动作 }
        YAML);

    $info = $this->loader->indexByControllerAction('admin')['App\Y']['index'];

    expect($info['module_zh'])->toBe('纯字符串模块');
    expect($info['controller_zh'])->toBe('纯字符串控制器');
    expect($info['zh_name'])->toBe('纯字符串动作');
});

it('normalizeKey 去掉 app 前缀；单段原样；空串原样', function () {
    expect($this->loader->normalizeKey('admin-light-book-index'))->toBe('light-book-index');
    expect($this->loader->normalizeKey('api-light-book-index'))->toBe('light-book-index');
    expect($this->loader->normalizeKey('home'))->toBe('home'); // 单段
    expect($this->loader->normalizeKey(''))->toBe('');
});

it('indexCrossAppByNormalizedKey 把跨 app 同业务 key 归到一起', function () {
    aclLoader_writeYaml('admin', <<<'YAML'
        meta: { app: admin }
        modules:
          - name: M
            controllers:
              - class: App\Admin\BookController
                actions:
                  - { action: index, plain_key: admin-book-index, name: { zh-CN: 图书列表 } }
        YAML);
    aclLoader_writeYaml('api', <<<'YAML'
        meta: { app: api }
        modules:
          - name: M
            controllers:
              - class: App\Api\BookController
                actions:
                  - { action: index, plain_key: api-book-index, name: { zh-CN: 图书列表 } }
        YAML);

    $cross = $this->loader->indexCrossAppByNormalizedKey(['admin' => '后台', 'api' => '接口']);

    expect($cross)->toHaveKey('book-index');
    expect($cross['book-index'])->toHaveCount(2);
    $apps = array_column($cross['book-index'], 'app');
    sort($apps);
    expect($apps)->toBe(['admin', 'api']);
});

it('yamlRelativePath 返回稳定相对路径', function () {
    expect($this->loader->yamlRelativePath('admin'))->toBe('scaffold/acl/admin.yaml');
});

it('exists() 反映文件是否生成', function () {
    expect($this->loader->exists('ghost'))->toBeFalse();
    aclLoader_writeYaml('ghost', "meta: { app: ghost }\nmodules: []\n");
    expect($this->loader->exists('ghost'))->toBeTrue();
});

it('indexByControllerAction 同实例按 app 缓存 —— 改写 yaml 后旧索引仍生效(未重复解析,2026-06-10 修)', function () {
    aclLoader_writeYaml('admin', <<<'YAML'
modules:
  - name: { zh-CN: 模块A }
    controllers:
      - class: App\Admin\MemoController
        name: { zh-CN: 备忘 }
        actions:
          - { action: index, plain_key: admin-memo-index, key: k1, title: 列表 }
YAML);

    $first = $this->loader->indexByControllerAction('admin');
    expect($first)->toHaveKey('App\Admin\MemoController');

    // 改写 yaml:若没有实例内缓存,第二次会重新解析读到 NoteController(bug 版本行为)
    aclLoader_writeYaml('admin', <<<'YAML'
modules:
  - name: { zh-CN: 模块B }
    controllers:
      - class: App\Admin\NoteController
        name: { zh-CN: 笔记 }
        actions:
          - { action: index, plain_key: admin-note-index, key: k2, title: 列表 }
YAML);

    $second = $this->loader->indexByControllerAction('admin');
    expect($second)->toBe($first);   // 命中缓存 → 同请求内 yaml 只解析一遍
});
