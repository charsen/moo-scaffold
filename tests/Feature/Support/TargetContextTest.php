<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Support\PackageRegistry;
use Mooeen\Scaffold\Utility;

/**
 * TargetContext / Utility::targetContext 出身解析单测(plan-53,前身 plan P0)。
 *
 * 锁三条核心保证:
 * ① host(target=null)解析结果跟现有 getModelPath/getMigrationPath/... 一致 —— 零回归;
 * ② 扩展包出身:路径按统一目录约定落「包目录」、命名空间用「psr-4 根 + 段」
 *    —— formatNameSpace 路径推导推不出包命名空间的核心修复;
 * ③ 写权硬线透传:registry 的 writable 进 TargetContext。
 */
beforeEach(function () {
    $this->sandbox = sys_get_temp_dir() . '/scaffold_tc_' . uniqid();
    $root          = $this->sandbox . '/moo-demo';
    @mkdir($root . '/scaffold/database', 0755, true);
    @mkdir($root . '/src', 0755, true);
    file_put_contents($root . '/composer.json', json_encode([
        'name'     => 'acme/moo-demo',
        'autoload' => ['psr-4' => ['Acme\\Demo\\' => 'src/']],
    ], JSON_UNESCAPED_SLASHES));
    $this->pkgRoot = $root;
    app()->instance(PackageRegistry::class, new PackageRegistry([$root]));
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->sandbox);
});

it('host target(null)沿用现有 host 路径与命名空间', function () {
    $u   = app(Utility::class);
    $ctx = $u->targetContext(null);

    expect($ctx->isHost())->toBeTrue();
    expect($ctx->writable)->toBeTrue();
    expect($ctx->pathFor('model'))->toBe($u->getModelPath());
    expect($ctx->pathFor('migration'))->toBe($u->getMigrationPath());
    expect($ctx->pathFor('storage'))->toBe($u->getStoragePath());
    expect($ctx->pathFor('database'))->toBe($u->getDatabasePath('schema'));
    expect($ctx->namespaceFor('model'))->toBe(rtrim($u->formatNameSpace($u->getModelPath(true)), '\\'));
});

it('host namespaceFor 带模块子段', function () {
    $ctx = app(Utility::class)->targetContext(null);
    expect($ctx->namespaceFor('model', 'System'))->toBe($ctx->namespaceFor('model') . '\\System');
});

it('扩展包出身:路径按约定落包目录、命名空间用 psr-4 根 + 段、app 固定 admin', function () {
    $ctx  = app(Utility::class)->targetContext('moo-demo');
    $base = $this->pkgRoot . '/';

    expect($ctx->isHost())->toBeFalse();
    expect($ctx->app)->toBe('admin');
    expect($ctx->pathFor('model'))->toBe($base . 'src/Models/');
    expect($ctx->pathFor('model', 'System'))->toBe($base . 'src/Models/System');
    expect($ctx->pathFor('controller'))->toBe($base . 'src/Http/Controllers/Admin/');
    expect($ctx->pathFor('migration'))->toBe($base . 'database/migrations/');
    expect($ctx->pathFor('database'))->toBe($base . 'scaffold/database/');
    expect($ctx->pathFor('docs'))->toBe($base . 'docs/');
    expect($ctx->pathFor('lang'))->toBe($base . 'lang/');
    // route 是文件,不带尾 /
    expect($ctx->pathFor('route'))->toBe($base . 'routes/admin.php');
    // 缓存是 host 侧聚合物(条目挂 origin 键),不按包分桶
    expect($ctx->pathFor('storage'))->toBe(app(Utility::class)->getStoragePath());
    // 命名空间:psr-4 根 + 段(核心修复 —— 路径推导推不出 Acme\Demo\Models)
    expect($ctx->namespaceFor('model'))->toBe('Acme\\Demo\\Models');
    expect($ctx->namespaceFor('model', 'System'))->toBe('Acme\\Demo\\Models\\System');
    expect($ctx->namespaceFor('controller'))->toBe('Acme\\Demo\\Http\\Controllers\\Admin');
});

it('写权硬线透传:沙箱包(vendor 外)writable=true', function () {
    expect(app(Utility::class)->targetContext('moo-demo')->writable)->toBeTrue();
});

it('未发现的扩展包抛异常', function () {
    expect(fn () => app(Utility::class)->targetContext('nope'))
        ->toThrow(InvalidArgumentException::class);
});

it('未配置 paths 的 kind 取路径抛异常', function () {
    $ctx = app(Utility::class)->targetContext(null);
    expect(fn () => $ctx->pathFor('controller'))->toThrow(InvalidArgumentException::class);
});
