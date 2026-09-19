<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Mooeen\Scaffold\Http\Middleware\ScaffoldAuthenticate;

/**
 * 统一 JSON 信封的**边界**：框架层错误**不套信封**，而且这是**有意**的（不是迁移欠账）。
 *
 * 为什么需要专门一条守卫：`{message:"…"}` 曾被定性成「第 6 种旧形态」并计划迁完就删，
 * 但实测（2026-09-19 探针）证明它的产出方**一直都在**、而且**不该**被迁 ——
 * `abort(404, '文案')` 在 JSON 请求下由 Laravel 的 exception handler 渲成 `{"message":"文案"}`，
 * 而 `abort*()` 在 `src/` 里有 20+ 处（`Support\LocalMarkdownEditor` / `PlansController` /
 * `ApiController` / `Requests\LocalMarkdown\PreviewRequest`），校验袋是 `{message, errors}`，
 * 限流是 `{message:"Too Many Attempts."}`。给这些套信封 = 跟框架对着干（要接管 handler），
 * 收益为零、风险全在框架升级上。
 *
 * 因此**前端 `ScaffoldApi.errorText()` 的 `j.message` 读法是永久契约**，不是兼容分支：
 * 删了它，「文件已被修改，请重新打开后再编辑」这类排障文案会降级成 `HTTP 409`。
 * 本文件把这条契约的**后端一侧**钉住（JS 一侧见 `tests/javascript/scaffold-api.test.js`）。
 *
 * 反向作用同样重要：本文件同时断言这些响应**没有** `ok` 键 —— 若有人"顺手统一"给框架层
 * 也套上信封，这里会红，提示他先改前端读法。
 */
beforeEach(function () {
    $this->directory = sys_get_temp_dir() . '/scaffold_fw_shape_' . uniqid();
    mkdir($this->directory);
    mkdir($this->directory . '/nested');
    $this->slug = 'nested/中文.md';
    $this->raw  = "# 原文\n";
    file_put_contents($this->directory . '/' . $this->slug, $this->raw);

    config(['scaffold.plans.path' => $this->directory, 'scaffold.config_ui.readonly' => false]);
    app()->instance('env', 'local');
    // 只绕开登录与 CSRF（与 LocalMarkdownEditingTest 同口径）；写保护中间件保持真实，
    // 否则 abort 会发生在中间件层、测不到下面这些业务 abort。
    $this->withoutMiddleware([ScaffoldAuthenticate::class, VerifyCsrfToken::class]);
    $this->withSession(['_token' => 'fw-shape-token']);
    $this->withHeader('X-CSRF-TOKEN', 'fw-shape-token');
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->directory);
});

it('框架层 abort()：body 恰好 {message}，没有 ok 键（信封在这里不适用）', function () {
    Route::get('/_fw_shape_404', static fn () => abort(404, '预检：框架层 abort 文案'));

    $res  = $this->getJson('/_fw_shape_404');
    $body = json_decode((string) $res->getContent(), true);

    expect($res->getStatusCode())->toBe(404)
        ->and(array_keys($body))->toBe(['message'], '框架层 body 恰好只有 message 键')
        ->and($body['message'])->toBe('预检：框架层 abort 文案')
        // 反向锚点：给框架层套信封会让这两条变红
        ->and(array_key_exists('ok', $body))->toBeFalse('框架层响应没有 ok 布尔')
        ->and(array_key_exists('error', $body))->toBeFalse('框架层响应没有 error 键');
});

it('真产线路径：并发编辑冲突的 409，前端 toast 的就是 body.message', function () {
    // version 是合法 64 位十六进制但与磁盘不符 ⇒ 过校验、命中 LocalMarkdownEditor:72 的 409
    $res = $this->postJson('/scaffold/plans/save', [
        'slug'    => $this->slug,
        'content' => '# 新正文',
        'version' => str_repeat('a', 64),
    ]);
    $body = json_decode((string) $res->getContent(), true);

    expect($res->getStatusCode())->toBe(409)
        ->and($body)->toBe(['message' => '文件已被修改，请重新打开后再编辑。'])
        // 磁盘没被动过：这句话不能只对一半
        ->and(file_get_contents($this->directory . '/' . $this->slug))->toBe($this->raw);
});

it('校验袋：422 顶层恰好 message + errors（errors 由前端直读，不许被信封改写）', function () {
    $res  = $this->postJson('/scaffold/plans/save', []);
    $body = json_decode((string) $res->getContent(), true);

    expect($res->getStatusCode())->toBe(422)
        ->and(array_keys($body))->toBe(['message', 'errors'], '校验袋恰好 message + errors 两键')
        ->and($body['errors'])->toHaveKeys(['slug', 'content'])
        ->and(array_key_exists('ok', $body))->toBeFalse();
});

it('限流 429 也是框架层 message（e2e 真踩过的那条 Too Many Attempts）', function () {
    // 计数器落在 cache 上，先清干净再数边界，避免受本文件其它用例影响
    cache()->clear();

    $statuses = [];
    for ($i = 1; $i <= 40; $i++) {
        $statuses[$i] = $this->postJson('/scaffold/plans/save', [])->getStatusCode();
    }

    // plans.save 挂 throttle:30,1 ⇒ 前 30 次放行、第 31 次起 429
    expect(array_search(429, $statuses, true))->toBe(31);

    $body = json_decode((string) $this->postJson('/scaffold/plans/save', [])->getContent(), true);

    expect($body)->toBe(['message' => 'Too Many Attempts.']);
});
