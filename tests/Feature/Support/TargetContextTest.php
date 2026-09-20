<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Support\PackageRegistry;
use Mooeen\Scaffold\Support\Paths;
use Mooeen\Scaffold\Utility;

/**
 * TargetContext / Utility::targetContext 出身解析单测(plan-53,前身 plan P0)。
 *
 * 锁三条核心保证:
 * ① host(target=null)解析结果跟现有路径真源（`Paths::model()` / `migration()` / …）一致 —— 零回归;
 * ② 扩展包出身:路径按统一目录约定落「包目录」、命名空间用「psr-4 根 + 段」
 *    —— `Paths::namespaceOf()` 路径推导推不出包命名空间的核心修复;
 * ③ 写权硬线透传:registry 的 writable 进 TargetContext。
 *
 * ⚠ 本文件末条（host 的 `pathFor('controller')` 抛错）钉的正是 `TUNING-PLAN.md` §三 P2 要补的缺口 ——
 * P2 给 host 臂补上 controller/request 的 path+namespace 时，这条会由「抛错」翻转成「返回路径」，
 * 那是**有意**变更，改它时要连 TUNING-PLAN 的状态一起更新。
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
    // 对照侧 2026-09-19 起是 `Support\Paths`（原 `Utility::getModelPath()` 等外迁，第 3 项 · 阶段 3b-2）——
    // 断言要的仍是「TargetContext 的 host 臂 == 路径真源」，只是真源换了出处。
    expect($ctx->pathFor('model'))->toBe(Paths::model());
    expect($ctx->pathFor('migration'))->toBe(Paths::migration());
    expect($ctx->pathFor('storage'))->toBe(Paths::storage());
    expect($ctx->pathFor('database'))->toBe(Paths::database('schema'));
    expect($ctx->namespaceFor('model'))->toBe(rtrim(Paths::namespaceOf(Paths::model(true)), '\\'));
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
    expect($ctx->pathFor('storage'))->toBe(Paths::storage());
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
