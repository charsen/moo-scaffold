<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Generator\CreateResourceGenerator;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Output\NullOutput;

/**
 * CreateResourceGenerator 回归锁(此前 0 测试)。
 *
 * 两条路并走:
 *   1. 整链路:在 storage_path('scaffold/') 写最小化缓存(models.php + {table}.php),
 *      调 start() 跑通 → 断言产出的 Resource 文件内容。
 *      generator 不直接读 yaml,而是经 Utility 从 storage 缓存读
 *      (start() 读 models.php;buildResource 经 getOneTable 读 {table}.php)。
 *   2. 纯方法:反射调 private getFieldCode,逐分支锁字段规则派生
 *      (whenTrashed / whenDate / password 跳过 / whenHas vs 索引字段 / enum _txt append / options)。
 *
 * 全局唯一前缀 resourceGen_ 避免 Pest 顶层 redeclare。
 * 缓存/产物写临时目录,afterEach 清理。
 */

// generator base ctor 接受 Command|Factory|OutputInterface;NullOutput 静音 console。
function resourceGen_make(): CreateResourceGenerator
{
    return new CreateResourceGenerator(new NullOutput, app(Filesystem::class), app(Utility::class));
}

// 写一份最小 storage 缓存(models.php + 单表 {table}.php),返回 storage scaffold 目录。
function resourceGen_seedStorage(array $models, string $tableName, array $tableAttr): string
{
    $fs  = app(Filesystem::class);
    $dir = storage_path('scaffold/');
    $fs->ensureDirectoryExists($dir);

    $fs->put($dir . 'models.php', '<?php return ' . var_export($models, true) . ';');
    $fs->put($dir . $tableName . '.php', '<?php return ' . var_export($tableAttr, true) . ';');

    // 记录本测试写的文件,afterEach 精确清理(不动同目录其它缓存)
    $GLOBALS['resourceGen_seeded'] = [$dir . 'models.php', $dir . $tableName . '.php'];

    return $dir;
}

beforeEach(function () {
    // 隔离 storage:把 storage_path 重定向到本测试独占的临时目录,这样喂的最小缓存
    // (models.php / {table}.php)写在临时 storage,绝不覆盖/删除共享 testbench storage/scaffold
    // 下 宿主项目 派生的缓存(DesignerController / UniqueSemanticsTest 依赖 models.php 等)。
    $this->resourceGen_origStorage = app()->storagePath();
    app()->useStoragePath(sys_get_temp_dir() . '/resourceGen_st_' . uniqid());
    app(Filesystem::class)->ensureDirectoryExists(storage_path('scaffold'));

    // 全局兜底 resource 路径 + admin app 配置(getResourceTargets 读 controller.{app})。
    config()->set('scaffold.author', 'tester');
    config()->set('scaffold.resource.path', 'app/Http/Resources/');
    config()->set('scaffold.controller.admin', [
        'name'          => ['zh-CN' => '后台管理', 'en' => 'Admin'],
        'path'          => 'app/Admin/Controllers/',
        'resource_path' => 'app/Admin/Resources/',
    ]);
});

afterEach(function () {
    $fs = app(Filesystem::class);
    // 只删本测试写的缓存文件,不整目录 deleteDirectory —— 同套件别的 test
    // (如 UniqueSemanticsTest)依赖 storage/scaffold 下的其它缓存(model_ids.php 等)。
    foreach ($GLOBALS['resourceGen_seeded'] ?? [] as $file) {
        $fs->delete($file);
    }
    $GLOBALS['resourceGen_seeded'] = [];
    $fs->deleteDirectory(base_path('app/Admin/Resources'));
    $fs->deleteDirectory(base_path('app/Http/Resources'));

    // 拆掉隔离的临时 storage,还原原 storage path(共享缓存全程未被触碰)
    $fs->deleteDirectory(storage_path());
    app()->useStoragePath($this->resourceGen_origStorage);
});

/* ---------------------------------------------------------------------------
 * Path 1 · 整链路:start() 经缓存生成 Resource 文件
 * ------------------------------------------------------------------------ */

it('start() 读缓存生成 Resource 文件(整链路 happy path)', function () {
    $models = [
        'Demo' => [
            'Article' => [
                'module'     => ['name' => '文章', 'folder' => 'Content'],
                'table_name' => 'articles',
                'app'        => ['admin'],
                'resource'   => ['admin'],
            ],
        ],
    ];

    $tableAttr = [
        'name'   => '文章',
        'index'  => ['id' => ['type' => 'primary', 'fields' => 'id', 'method' => 'btree']],
        'fields' => [
            'id'    => ['name' => '编号', 'type' => 'bigint', 'unsigned' => true],
            'title' => ['name' => '标题', 'type' => 'string'],
        ],
        'enums' => [],
    ];

    resourceGen_seedStorage($models, 'articles', $tableAttr);

    $ok = resourceGen_make()->start('Demo');

    expect($ok)->toBeTrue();

    $file = base_path('app/Admin/Resources/Content/ArticleResource.php');
    expect(file_exists($file))->toBeTrue();

    $content = file_get_contents($file);
    // namespace 由 resource_path 派生
    expect($content)->toContain('namespace App\\Admin\\Resources\\Content;');
    expect($content)->toContain('class ArticleResource extends BaseResource');
    expect($content)->toContain('@Description: 文章 资源');
    expect($content)->toContain('@Author: tester');
    // 索引字段 id → 裸输出;非索引 title → whenHas
    expect($content)->toContain("'id' => \$this->id,");
    expect($content)->toContain("'title' => \$this->whenHas('title'),");
    // options 兜底行
    expect($content)->toContain("'options' => \$this->whenAppended('options'),");
});

it('start() 带 only_table → 只生成该表 Resource,同 schema 其它表跳过', function () {
    // moo:free -t/--table 单表模式的 generator 层锁:同一 schema 两张表,只点名 articles
    $models = [
        'Demo' => [
            'Article' => [
                'module'     => ['name' => '内容', 'folder' => 'Content'],
                'table_name' => 'articles',
                'app'        => ['admin'],
                'resource'   => ['admin'],
            ],
            'Comment' => [
                'module'     => ['name' => '内容', 'folder' => 'Content'],
                'table_name' => 'comments',
                'app'        => ['admin'],
                'resource'   => ['admin'],
            ],
        ],
    ];
    $minAttr = fn (string $name): array => [
        'name'   => $name,
        'index'  => [],
        'fields' => ['id' => ['name' => '编号', 'type' => 'bigint']],
        'enums'  => [],
    ];

    $fs  = app(Filesystem::class);
    $dir = storage_path('scaffold/');
    $fs->ensureDirectoryExists($dir);
    $fs->put($dir . 'models.php', '<?php return ' . var_export($models, true) . ';');
    $fs->put($dir . 'articles.php', '<?php return ' . var_export($minAttr('文章'), true) . ';');
    $fs->put($dir . 'comments.php', '<?php return ' . var_export($minAttr('评论'), true) . ';');

    resourceGen_make()->start('Demo', false, 'articles');

    // 点名表 → 生成;未点名的同 schema 表 → 不生成
    expect(file_exists(base_path('app/Admin/Resources/Content/ArticleResource.php')))->toBeTrue();
    expect(file_exists(base_path('app/Admin/Resources/Content/CommentResource.php')))->toBeFalse();
});

it('start() 对未知 schema 返回 false 且不写文件', function () {
    resourceGen_seedStorage([], 'noop', ['name' => '', 'index' => [], 'fields' => [], 'enums' => []]);

    $ok = resourceGen_make()->start('NotThere');

    expect($ok)->toBeFalse();
    expect(file_exists(base_path('app/Admin/Resources/Content/ArticleResource.php')))->toBeFalse();
});

it('start() 已存在文件且非 force → 跳过不覆盖', function () {
    $models = [
        'Demo' => [
            'Article' => [
                'module'     => ['name' => '文章', 'folder' => 'Content'],
                'table_name' => 'articles',
                'app'        => ['admin'],
                'resource'   => ['admin'],
            ],
        ],
    ];
    $tableAttr = [
        'name'   => '文章',
        'index'  => [],
        'fields' => ['id' => ['name' => '编号', 'type' => 'bigint']],
        'enums'  => [],
    ];
    resourceGen_seedStorage($models, 'articles', $tableAttr);

    $file = base_path('app/Admin/Resources/Content/ArticleResource.php');
    app(Filesystem::class)->ensureDirectoryExists(dirname($file));
    file_put_contents($file, '// SENTINEL existing content');

    resourceGen_make()->start('Demo', false);

    // 非 force,已存在 → 内容原样保留
    expect(file_get_contents($file))->toBe('// SENTINEL existing content');
});

it('start() force=true → 覆盖已存在文件', function () {
    $models = [
        'Demo' => [
            'Article' => [
                'module'     => ['name' => '文章', 'folder' => 'Content'],
                'table_name' => 'articles',
                'app'        => ['admin'],
                'resource'   => ['admin'],
            ],
        ],
    ];
    $tableAttr = [
        'name'   => '文章',
        'index'  => [],
        'fields' => ['id' => ['name' => '编号', 'type' => 'bigint']],
        'enums'  => [],
    ];
    resourceGen_seedStorage($models, 'articles', $tableAttr);

    $file = base_path('app/Admin/Resources/Content/ArticleResource.php');
    app(Filesystem::class)->ensureDirectoryExists(dirname($file));
    file_put_contents($file, '// SENTINEL existing content');

    resourceGen_make()->start('Demo', true);

    $content = file_get_contents($file);
    expect($content)->not->toContain('SENTINEL');
    expect($content)->toContain('class ArticleResource extends BaseResource');
});

/* ---------------------------------------------------------------------------
 * Path 2 · 纯方法:getFieldCode 字段规则派生逐分支(line ~158-194)
 * ------------------------------------------------------------------------ */

function resourceGen_fieldCode(array $tableAttr): string
{
    $gen = resourceGen_make();
    $ref = new ReflectionMethod($gen, 'getFieldCode');
    $ref->setAccessible(true);

    return $ref->invoke($gen, $tableAttr);
}

it('getFieldCode · 索引字段裸输出,非索引字段 whenHas()', function () {
    $code = resourceGen_fieldCode([
        'fields' => [
            'id'   => ['name' => '编号', 'type' => 'bigint'],
            'name' => ['name' => '名称', 'type' => 'string'],
        ],
        'enums' => [],
        'index' => ['id' => ['type' => 'primary', 'fields' => 'id']],
    ]);

    expect($code)->toContain("'id' => \$this->id,");
    expect($code)->toContain("'name' => \$this->whenHas('name'),");
});

it('getFieldCode · 下划线开头 / password 字段被跳过', function () {
    $code = resourceGen_fieldCode([
        'fields' => [
            '_internal'     => ['name' => '内部', 'type' => 'string'],
            'password'      => ['name' => '密码', 'type' => 'string'],
            'user_password' => ['name' => '用户密码', 'type' => 'string'],
            'keep'          => ['name' => '保留', 'type' => 'string'],
        ],
        'enums' => [],
        'index' => [],
    ]);

    expect($code)->not->toContain('_internal');
    expect($code)->not->toContain("'password'");
    expect($code)->not->toContain("'user_password'");
    expect($code)->toContain("'keep' => \$this->whenHas('keep'),");
});

it('getFieldCode · deleted_at → whenTrashed', function () {
    $code = resourceGen_fieldCode([
        'fields' => ['deleted_at' => ['name' => '删除时间', 'type' => 'datetime']],
        'enums'  => [],
        'index'  => [],
    ]);

    expect($code)->toContain("'deleted_at' => \$this->whenTrashed(\$this->deleted_at),");
});

it('getFieldCode · date/datetime/timestamp(非 updated_at)→ whenDate', function () {
    $code = resourceGen_fieldCode([
        'fields' => [
            'published_at' => ['name' => '发布时间', 'type' => 'datetime'],
            'birth'        => ['name' => '生日', 'type' => 'date'],
            'ts'           => ['name' => '时间戳', 'type' => 'timestamp'],
        ],
        'enums' => [],
        'index' => [],
    ]);

    expect($code)->toContain("'published_at' => \$this->whenDate('published_at'),");
    expect($code)->toContain("'birth' => \$this->whenDate('birth'),");
    expect($code)->toContain("'ts' => \$this->whenDate('ts'),");
});

it('getFieldCode · updated_at 是 datetime 但走通用分支(whenHas),不是 whenDate', function () {
    $code = resourceGen_fieldCode([
        'fields' => ['updated_at' => ['name' => '更新时间', 'type' => 'datetime']],
        'enums'  => [],
        'index'  => [],
    ]);

    expect($code)->toContain("'updated_at' => \$this->whenHas('updated_at'),");
    expect($code)->not->toContain("whenDate('updated_at')");
});

it('getFieldCode · enum 字段追加 {field}_txt whenAppended 行', function () {
    $code = resourceGen_fieldCode([
        'fields' => ['status' => ['name' => '状态', 'type' => 'tinyint']],
        'enums'  => ['status' => ['open' => [1, 'Open', '开启']]],
        'index'  => [],
    ]);

    expect($code)->toContain("'status' => \$this->whenHas('status'),");
    expect($code)->toContain("'status_txt' => \$this->whenAppended('status_txt'),");
});

it('getFieldCode · 始终追加 options whenAppended 兜底行,首行去缩进', function () {
    $code = resourceGen_fieldCode([
        'fields' => ['id' => ['name' => '编号', 'type' => 'bigint']],
        'enums'  => [],
        'index'  => ['id' => ['type' => 'primary']],
    ]);

    expect($code)->toContain("'options' => \$this->whenAppended('options'),");
    // 首行被 trim(stub 已缩进),不以空格起头
    expect($code)->not->toStartWith(' ');
    expect($code)->toStartWith("'id'");
});
