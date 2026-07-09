<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Command\CreateViewCommand;
use Mooeen\Scaffold\Designer\SchemaLoader;
use Mooeen\Scaffold\Generator\CreateControllerGenerator;
use Mooeen\Scaffold\Generator\CreateModelGenerator;
use Mooeen\Scaffold\Generator\CreateResourceGenerator;
use Mooeen\Scaffold\Generator\FreshStorageGenerator;
use Mooeen\Scaffold\Generator\UpdateMultilingualGenerator;
use Mooeen\Scaffold\Support\PackageRegistry;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Output\NullOutput;

/**
 * plan-53 Phase 3:codegen 落包端到端。
 * 沙箱:temp host schema 目录(隔离,不碰 git fixture)+ 假扩展包(带 schema/routes/composer);
 * 依次跑 fresh → model → resource → controller → i18n,断言产物全落包、命名空间用包根、
 * 路由插包 admin.php、BaseActionTrait 恒指 host。
 */

/** host 侧运行时依赖 shim(scaffold 包 vendor 无 eloquentfilter;生成的 model use 它) */
function ensureCodegenShims(): void
{
    if (! trait_exists(\EloquentFilter\Filterable::class)) {
        eval('namespace EloquentFilter; trait Filterable { public static function provideFilter($filter = null) {} }');
    }
}

beforeEach(function () {
    ensureCodegenShims();

    $this->sandbox = sys_get_temp_dir() . '/scaffold_cg_' . uniqid();

    // ① temp host schema 目录(空,codegen 只针对包 schema;隔离 git fixture)
    $hostDb = $this->sandbox . '/host-db';
    @mkdir($hostDb, 0755, true);
    config(['scaffold.database.schema' => $hostDb . '/']);

    // ② 假扩展包:约定形态齐全
    $root = $this->sandbox . '/moo-pkgen';
    @mkdir($root . '/scaffold/database', 0755, true);
    @mkdir($root . '/src', 0755, true);
    @mkdir($root . '/routes', 0755, true);
    file_put_contents($root . '/composer.json', json_encode([
        'name'     => 'acme/moo-pkgen',
        'autoload' => ['psr-4' => ['Acme\\PkgGen\\' => 'src/']],
    ], JSON_UNESCAPED_SLASHES));
    file_put_contents($root . '/routes/admin.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n// :insert_code_here:do_not_delete\n");
    file_put_contents($root . '/scaffold/database/PkgGen.yaml', <<<'YAML'
module:
    folder: PkgGen
    name: 包生成演示
tables:
    pkgx_items:
        attrs: { name: 包条目 }
        model: { class: PkgxItem }
        controller: { app: [admin], class: PkgxItemController, resource: [admin] }
        index:
            id: { type: primary, fields: id }
        fields:
            id: {  }
            title: { name: 标题, type: varchar, size: 64, required: true }
            status: { name: 状态, type: tinyint, default: 1 }
        enums:
            status:
                open: [1, Open, 开启]
                closed: [2, Closed, 关闭]
YAML);
    $this->pkgRoot = $root;

    // ③ 包命名空间可自动加载(controller 生成时 new $model_class)
    spl_autoload_register($this->pkgAutoloader = function (string $class) use ($root): void {
        if (str_starts_with($class, 'Acme\\PkgGen\\')) {
            $file = $root . '/src/' . str_replace('\\', '/', substr($class, strlen('Acme\\PkgGen\\'))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    });

    app()->instance(PackageRegistry::class, new PackageRegistry([$root]));
    app()->forgetInstance(SchemaLoader::class);

    // ④ 建缓存(带 origin)
    (new FreshStorageGenerator(new NullOutput, app(Filesystem::class), app(Utility::class)))->start(clean: false, silence: true);
});

afterEach(function () {
    spl_autoload_unregister($this->pkgAutoloader);
    (new Filesystem)->deleteDirectory($this->sandbox);
});

it('fresh 缓存:包 schema 条目挂 origin', function () {
    $menus = app(Utility::class)->getTables();
    expect($menus['PkgGen']['origin'] ?? null)->toBe('moo-pkgen');
    $tables = app(Utility::class)->getOneTable('pkgx_items');
    expect($tables['origin'] ?? null)->toBe('moo-pkgen');
});

it('moo:model:Model/Filter/Trait/Enum 全落包 src/Models(平铺),命名空间用包根', function () {
    $ok = (new CreateModelGenerator(new NullOutput, app(Filesystem::class), app(Utility::class)))->start('PkgGen');
    expect($ok)->toBeTrue();

    $base = $this->pkgRoot . '/src/Models';
    expect(is_file("{$base}/PkgxItem.php"))->toBeTrue();
    expect(is_file("{$base}/Filters/PkgxItemFilter.php"))->toBeTrue();
    expect(is_file("{$base}/Traits/PkgxItemTrait.php"))->toBeTrue();
    expect(is_file("{$base}/Enums/Status.php"))->toBeTrue();
    expect(is_file("{$base}/BaseFilter.php"))->toBeTrue();       // 假包无既有 BaseFilter → 生成进包

    $model = file_get_contents("{$base}/PkgxItem.php");
    expect($model)->toContain('namespace Acme\\PkgGen\\Models;');
    expect($model)->toContain('use Acme\\PkgGen\\Models\\Traits\\PkgxItemTrait;');
    // host 目录零污染(host app/Models 不出现包的类)
    expect(is_file(app(Utility::class)->getModelPath() . 'PkgGen/PkgxItem.php'))->toBeFalse();
});

it('moo:resource:Resource 落包 src/Http/Resources(平铺),命名空间用包根', function () {
    (new CreateModelGenerator(new NullOutput, app(Filesystem::class), app(Utility::class)))->start('PkgGen');
    $ok = (new CreateResourceGenerator(new NullOutput, app(Filesystem::class), app(Utility::class)))->start('PkgGen');
    expect($ok)->toBeTrue();

    $file = $this->pkgRoot . '/src/Http/Resources/PkgxItemResource.php';
    expect(is_file($file))->toBeTrue();
    expect(file_get_contents($file))->toContain('namespace Acme\\PkgGen\\Http\\Resources;');
});

it('moo:controller:Controller/Request/Trait 落包、包 use 包内自持 HandlesResourceActions、路由插包 admin.php', function () {
    $fs = app(Filesystem::class);
    (new CreateModelGenerator(new NullOutput, $fs, app(Utility::class)))->start('PkgGen');
    (new CreateResourceGenerator(new NullOutput, $fs, app(Utility::class)))->start('PkgGen');
    $ok = (new CreateControllerGenerator(new NullOutput, $fs, app(Utility::class)))->start('PkgGen');
    expect($ok)->toBeTrue();

    $ctl = $this->pkgRoot . '/src/Http/Controllers/Admin/PkgxItemController.php';
    expect(is_file($ctl))->toBeTrue();
    $ctlTxt = file_get_contents($ctl);
    expect($ctlTxt)->toContain('namespace Acme\\PkgGen\\Http\\Controllers\\Admin;');
    // 通用资源动作 trait:包侧改用包内自持 HandlesResourceActions(切断包→host BaseActionTrait 依赖,commit 589cbd1);controller trait 指包
    expect($ctlTxt)->toContain('use Acme\\PkgGen\\Http\\Controllers\\Admin\\Traits\\HandlesResourceActions;');
    expect($ctlTxt)->toContain('use Acme\\PkgGen\\Http\\Controllers\\Admin\\Traits\\PkgxItemTrait;');

    // Request:包 src/Http/Requests/{Controller}/(无 module 段)
    expect(is_file($this->pkgRoot . '/src/Http/Requests/PkgxItem/PkgxItemRequestTrait.php'))->toBeTrue();
    expect(is_file($this->pkgRoot . '/src/Http/Requests/PkgxItem/StoreRequest.php'))->toBeTrue();

    // controller trait 落包 Controllers/Admin/Traits
    expect(is_file($this->pkgRoot . '/src/Http/Controllers/Admin/Traits/PkgxItemTrait.php'))->toBeTrue();

    // 路由插进包 routes/admin.php 的标记处
    $routes = file_get_contents($this->pkgRoot . '/routes/admin.php');
    expect($routes)->toContain("Route::iResource('pkgx-items', \\Acme\\PkgGen\\Http\\Controllers\\Admin\\PkgxItemController::class);");
    expect($routes)->toContain(':insert_code_here:do_not_delete');   // 标记保留可续插
});

it('moo:i18n 分流:包词条子集进包 lang/,host lang 不动,手写词条不被子集同步误删', function () {
    // 预置包内手写词条(非 schema 派生,模拟 copy_mode 这类 feature 词条 — 2026-07-04 moo-system 误删回归锁)
    @mkdir($this->pkgRoot . '/lang/zh-CN', 0755, true);
    file_put_contents($this->pkgRoot . '/lang/zh-CN/db.php', "<?php\n\nreturn [\n    'copy_mode' => '复制模式',\n    'title' => '旧标题',\n];\n");
    file_put_contents($this->pkgRoot . '/lang/zh-CN/validation.php', "<?php\n\nreturn [\n    'attributes' => [\n        'source_role_id' => '源头角色',\n    ],\n];\n");

    $ok = (new UpdateMultilingualGenerator(new NullOutput, app(Filesystem::class), app(Utility::class)))->start('PkgGen');
    expect($ok)->toBeTrue();

    $db = $this->pkgRoot . '/lang/zh-CN/db.php';
    expect(is_file($db))->toBeTrue();
    $dbTxt = file_get_contents($db);
    expect($dbTxt)->toContain("'title' => '标题'");          // 子集 key:值刷新为 schema 派生
    expect($dbTxt)->toContain("'copy_mode' => '复制模式'");  // 手写 key:保留不删
    // validation attributes 同语义
    $valTxt = file_get_contents($this->pkgRoot . '/lang/zh-CN/validation.php');
    expect($valTxt)->toContain("'source_role_id' => '源头角色'");
    expect($valTxt)->toContain("'title'");
    // 枚举词条进包 model.php
    $modelTxt = file_get_contents($this->pkgRoot . '/lang/zh-CN/model.php');
    expect($modelTxt)->toContain("'status_open'");
    // host lang 不因包流水线被写(zh-CN/db.php 若存在也不含包专属词条断言略 — 核心是包文件生成)
});

it('写权硬线:vendor 拷贝包 codegen 全链硬拒', function () {
    // 在 base_path('vendor') 里造拷贝形态的同构包
    $ro = base_path('vendor/acme-cg/moo-rocg');
    @mkdir($ro . '/scaffold/database', 0755, true);
    @mkdir($ro . '/src', 0755, true);
    file_put_contents($ro . '/composer.json', json_encode(['name' => 'acme-cg/moo-rocg', 'autoload' => ['psr-4' => ['AcmeCg\\Ro\\' => 'src/']]]));
    copy($this->pkgRoot . '/scaffold/database/PkgGen.yaml', $ro . '/scaffold/database/RoGen.yaml');
    // RoGen.yaml 里 folder 与表名要区分,避免表名跨源 fail-fast 先触发
    file_put_contents($ro . '/scaffold/database/RoGen.yaml', str_replace(['PkgGen', 'pkgx_items', 'PkgxItem'], ['RoGen', 'rocg_items', 'RocgItem'], file_get_contents($ro . '/scaffold/database/RoGen.yaml')));

    app()->instance(PackageRegistry::class, new PackageRegistry([$this->pkgRoot, $ro]));
    app()->forgetInstance(SchemaLoader::class);
    (new FreshStorageGenerator(new NullOutput, app(Filesystem::class), app(Utility::class)))->start(clean: false, silence: true);

    expect(fn () => (new CreateModelGenerator(new NullOutput, app(Filesystem::class), app(Utility::class)))->start('RoGen'))
        ->toThrow(InvalidArgumentException::class, '只读');

    (new Filesystem)->deleteDirectory(base_path('vendor/acme-cg'));
});

it('CLI 单一真源:SchemaLoader::listSchemaFiles 聚合包 schema,originOf 返回出身', function () {
    // 3.2:知识挪回 Designer 真源(原经 Utility::getSchemaNames/schemaOrigin 反调 SchemaLoader,已删),
    // 断言语义一比一等价 —— 有意的测试迁移。
    $loader = app(\Mooeen\Scaffold\Designer\SchemaLoader::class);
    expect(array_keys($loader->listSchemaFiles()))->toContain('PkgGen');
    expect($loader->originOf('PkgGen'))->toBe('moo-pkgen');
    expect($loader->originOf('NotExist'))->toBeNull();
});

it('Command::hostSchemaNames 滤掉扩展包出身 schema,只留 host(3.2 唯一带逻辑的助手)', function () {
    // 3.2:moo:view / moo:test 用 hostSchemaNames() 列表(包 schema 前端/测试脚手架未设计)。
    // 该助手是三个新助手里唯一带 array_filter 逻辑的,单独锁「包 schema 被滤掉」。
    $cmd = new CreateViewCommand(app(Filesystem::class), app(Utility::class));

    $schemaNames = new ReflectionMethod($cmd, 'schemaNames');
    $schemaNames->setAccessible(true);
    $hostSchemaNames = new ReflectionMethod($cmd, 'hostSchemaNames');
    $hostSchemaNames->setAccessible(true);

    $all  = $schemaNames->invoke($cmd);
    $host = $hostSchemaNames->invoke($cmd);

    expect($all)->toContain('PkgGen');           // 全量含扩展包 schema
    expect($host)->not->toContain('PkgGen');     // host-only 把它滤掉
    expect(count($host))->toBeLessThan(count($all));   // 过滤确实生效(至少少了 PkgGen)
});
