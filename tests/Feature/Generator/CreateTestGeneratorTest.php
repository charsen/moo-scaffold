<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Generator\CreateTestGenerator;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Output\NullOutput;

/**
 * moo:test（CreateTestGenerator）产物冒烟 —— 自包含沙箱，不依赖 host fixture。
 *
 * ⚠ 缓存 shape 必须贴真实：$attr['app'] 是**数组**（一个控制器可挂多 app），$attr['module'] 是
 *   ['name'=>, 'folder'=>]。早期用 'app'=>'admin'（字符串）写测 → 假绿，真机 "Array to string" 炸。
 *
 * 验：① 单 app → tests/Feature/{App}/{module}/{Controller}Test.php，占位符全替换，FQCN 推对；
 *     ② 多 app（admin+api）→ 各落一份，FQCN 各按 app 命名空间；
 *     ③ once 语义（已存在不带 -f 不覆盖）。
 */
it('moo:test：逐 app 生成路由契约测，FQCN 推导 + 占位符 + once', function () {
    $fs       = app(Filesystem::class);
    $origStor = app()->storagePath();
    $sandbox  = sys_get_temp_dir() . '/gentest_' . uniqid();

    app()->useStoragePath($sandbox . '/storage');
    $fs->ensureDirectoryExists(storage_path('scaffold'));

    $testsDir = $sandbox . '/tests/';                 // 绝对路径 → 生成器原样用，不污染 base_path
    config()->set('scaffold.tests.path', $testsDir);
    config()->set('scaffold.author', 'tester');

    $cache = [
        'Market' => [
            'OrderServiceController' => [
                'app'         => ['admin'],                          // 数组（真实 shape）
                'module'      => ['name' => '市场', 'folder' => 'Market'],
                'model_class' => 'OrderService',
            ],
            'BothController' => [
                'app'         => ['admin', 'api'],                   // 多 app → 各落一份
                'module'      => ['name' => '市场', 'folder' => 'Market'],
                'model_class' => 'Both',
            ],
        ],
    ];
    $fs->put(storage_path('scaffold/controllers.php'), '<?php return ' . var_export($cache, true) . ';');

    try {
        $gen = new CreateTestGenerator(new NullOutput, $fs, app(Utility::class));

        // ── ① 单 app（admin）──────────────────────────────────────
        expect($gen->start('Market', 'OrderServiceController', true))->toBeTrue();
        $file = $testsDir . 'Admin/Market/OrderServiceControllerTest.php';
        expect($fs->isFile($file))->toBeTrue('单 app 测未生成到 Admin/Market/');

        $c = $fs->get($file);
        foreach (['{{author}}', '{{date}}', '{{controller}}', '{{controller_fqcn}}'] as $ph) {
            expect(str_contains($c, $ph))->toBeFalse("残留未替换占位符 {$ph}");
        }
        expect($c)->toContain('App\\Admin\\Controllers\\Market\\OrderServiceController@');
        expect($c)->toContain('tester');

        // ── ② 多 app（admin + api）→ 两份，FQCN 各异 ───────────────
        $gen->start('Market', 'BothController', true);
        $admin = $testsDir . 'Admin/Market/BothControllerTest.php';
        $api   = $testsDir . 'Api/Market/BothControllerTest.php';
        expect($fs->isFile($admin))->toBeTrue('多 app:admin 份未生成');
        expect($fs->isFile($api))->toBeTrue('多 app:api 份未生成');
        expect($fs->get($admin))->toContain('App\\Admin\\Controllers\\Market\\BothController@');
        expect($fs->get($api))->toContain('App\\Api\\Controllers\\Market\\BothController@');

        // ── ③ once：不带 -f 再跑不覆盖 ─────────────────────────────
        $mtime1 = $fs->lastModified($file);
        $gen->start('Market', 'OrderServiceController', false);
        expect($fs->lastModified($file))->toBe($mtime1);

        // ── ④ testDirs：去重后给「择机跑」提示用的相对目录（admin + api 各一）──
        $dirs = $gen->testDirs('Market');
        expect($dirs)->toContain('tests/Feature/Admin/Market');
        expect($dirs)->toContain('tests/Feature/Api/Market');
        expect(count($dirs))->toBe(2);   // 两控制器同 module，去重后仅 Admin/Market + Api/Market
    } finally {
        $fs->deleteDirectory($sandbox);
        app()->useStoragePath($origStor);
    }
});
