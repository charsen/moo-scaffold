<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Generator\CreateViewGenerator;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Output\NullOutput;

/**
 * moo:view(CreateViewGenerator)产物冒烟 —— 补 2026-06-25 体检发现的空白:
 * 其它生成器都有「产物有效性」测,唯独前端 .vue 生成器零覆盖。
 *
 * 产物是 Vue(.vue,非 PHP)→ 套不上 GeneratorOutputValidity 的 token_get_all 语法断言;
 * 改验**模板渲染契约**:
 *   ① index / trashed / show 三个 .vue 都生成;
 *   ② 4 个 meta 占位符({{author}} / {{date}} / {{model_class}} / {{acl_key}})全被替换
 *      —— 残留 = buildStub 退化 / 渲染漏键;
 *   ③ 替换值真出现(防「stub 本来就没这键」的假绿)。
 *
 * ⚠ 断言只盯这 4 个**具名**占位符,绝不盯泛 `{{ }}` —— Vue 自己的 mustache 插值也是 `{{ }}`,
 *   泛断言会被 Vue 模板误伤。
 */
it('moo:view:生成 index/trashed/show 三个 .vue,meta 占位符全部替换', function () {
    $fs       = app(Filesystem::class);
    $origStor = app()->storagePath();
    $sandbox  = sys_get_temp_dir() . '/genview_' . uniqid();
    app()->useStoragePath($sandbox . '/storage');
    $fs->ensureDirectoryExists(storage_path('scaffold'));

    $viewsRoot = $sandbox . '/views/';
    config()->set('scaffold.frontend.views', $viewsRoot);
    config()->set('scaffold.author', 'tester');

    // controllers 缓存(getControllers(false) 直读):schema → controller → attr。
    // start() 用 module.folder 拼目录、model_class 进 meta;class 会被 stripControllerSuffix 重写。
    $cache = [
        'TestSchema' => [
            'FooController' => [
                'module'      => ['folder' => 'Foo'],
                'class'       => 'Foo',
                'model_class' => 'App\\Models\\Foo',
            ],
        ],
    ];
    $fs->put(storage_path('scaffold/controllers.php'), '<?php return ' . var_export($cache, true) . ';');

    try {
        $ok = (new CreateViewGenerator(new NullOutput, $fs, app(Utility::class)))
            ->start('TestSchema', 'FooController', true);
        expect($ok)->toBeTrue();

        // module = snake('Foo','-') = foo;entity = snake('Foo','-') = foo → views/foo/foo/
        $dir     = $viewsRoot . 'foo/foo/';
        $acl_key = 'test-schema-foo';   // snake('TestSchema'.'Foo','-')

        foreach (['index.vue', 'trashed.vue', 'show.vue'] as $name) {
            $p = $dir . $name;
            expect($fs->isFile($p))->toBeTrue("{$name} 未生成");
            $c = $fs->get($p);
            foreach (['{{author}}', '{{date}}', '{{model_class}}', '{{acl_key}}'] as $ph) {
                expect(str_contains($c, $ph))->toBeFalse("{$name} 残留未替换占位符 {$ph}");
            }
        }

        // 假绿守护:index.vue 确实用了 model_class + acl_key 两个键(见 stub),验它俩的值真被填进去
        $index = $fs->get($dir . 'index.vue');
        expect($index)->toContain('App\\Models\\Foo');
        expect($index)->toContain($acl_key);
    } finally {
        $fs->deleteDirectory($sandbox);
        app()->useStoragePath($origStor);
    }
});
