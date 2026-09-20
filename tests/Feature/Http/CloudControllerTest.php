<?php declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Http;
use Mooeen\Monitor\Cloud\CloudClient;
use Mooeen\Monitor\Cloud\CloudSync;
use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Mooeen\Monitor\Recorder\SqlSlowRecorder;
use Mooeen\Scaffold\Http\Controllers\ScaffoldController;
use Mooeen\Scaffold\Http\Middleware\EnforceScaffoldWritable;
use Mooeen\Scaffold\Http\Middleware\ScaffoldAuthenticate;

/**
 * S-Cloud 页(/scaffold/cloud)回归锁(2026-06-10 三修):
 *   1. 手动推送也打心跳 —— 原先只有 moo:cloud:push 命令打,scheduler 没跑、全靠
 *      手动推送的 host 被云端「推送中断」哨兵长期误报。
 *   2. 推送反馈不失真 —— 跨类型或单批逐条 partial 都保留已确认 / 已隔离事实;
 *      分类型开关全跳过时不再显示「已确认 0 条」假成功。
 *   3. open 空但 resolved 桶有待推时,缓冲卡不再谎报"缓冲为空"(推送推 open+resolved 两桶)。
 *
 * 采集/推送链路来自 moo-monitor-laravel(Mooeen\Monitor),数据缓冲在
 * storage_path('moo-monitor/...')—— 沙箱改为 setBasePath + useStoragePath 双隔离。
 */
beforeEach(function () {
    $this->withoutMiddleware([
        ScaffoldAuthenticate::class,
        VerifyCsrfToken::class,
        ValidateCsrfToken::class,
        EnforceScaffoldWritable::class,
    ]);

    $this->origBase    = base_path();
    $this->origStorage = storage_path();
    $this->origEnv     = app()->environment();
    $this->sandbox     = sys_get_temp_dir() . '/scaffold_cloudctrl_' . uniqid();
    @mkdir($this->sandbox . '/storage', 0755, true);
    // Blade 组件编译走 Application::getNamespace() 读 base_path/composer.json —— 沙箱
    // base_path 下渲染视图必须放个最小桩,否则 500(AccountControllerTest 同款坑)
    file_put_contents($this->sandbox . '/composer.json', '{"autoload":{"psr-4":{"App\\\\":"app/"}}}');
    app()->setBasePath($this->sandbox);
    app()->useStoragePath($this->sandbox . '/storage');

    config([
        'moo-monitor.runtime.enabled'  => true,
        'moo-monitor.sql_slow.enabled' => true,
        'moo-monitor.cloud.enabled'    => true,
        'moo-monitor.cloud.base_url'   => 'https://cloud.test',
        'moo-monitor.cloud.token'      => 'tok-' . str_repeat('a1', 20),
        'scaffold.config_ui.readonly'  => false,
    ]);
    app()->forgetInstance(RuntimeErrorRecorder::class);
    app()->forgetInstance(SqlSlowRecorder::class);
});

afterEach(function () {
    app()->setBasePath($this->origBase);
    app()->useStoragePath($this->origStorage);
    app()->instance('env', $this->origEnv);
    cloudCtrl_rrmdir($this->sandbox);
});

function cloudCtrl_rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $p = $dir . '/' . $f;
        is_dir($p) ? cloudCtrl_rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

it('手动推送也打心跳(原先只有调度命令打 → 纯手动推送的 host 被哨兵误报中断)', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'saved' => 1, 'filtered' => 0, 'skipped' => 0])]);

    app(RuntimeErrorRecorder::class)->record(new RuntimeException('boom'));

    $r = $this->post('/scaffold/cloud/push');
    $r->assertRedirect();
    $r->assertSessionHas('flash_message');

    Http::assertSent(fn ($req) => $req->url() === 'https://cloud.test/' . CloudClient::PATH_HEARTBEAT);
});

it('部分成功不报整体失败:runtimes 推完后 slow_sql 失败 → 消息带上已推计数与失败归属', function () {
    Http::fake([
        'cloud.test/' . CloudClient::PATH_RUNTIMES     => Http::response(['ok' => true, 'saved' => 1, 'filtered' => 0, 'skipped' => 0]),
        'cloud.test/' . CloudClient::PATH_SLOW_QUERIES => Http::response(['ok' => false, 'error' => '云端炸了'], 200),
        'cloud.test/' . CloudClient::PATH_HEARTBEAT    => Http::response(['ok' => true]),
    ]);

    app(RuntimeErrorRecorder::class)->record(new RuntimeException('boom'));
    app(SqlSlowRecorder::class)->record('select * from t where id = ?', 'select * from t where id = 1', 250.0, '/app/X.php', 1);

    $r = $this->post('/scaffold/cloud/push');
    $r->assertRedirect();
    $r->assertSessionHas('flash_error');

    $msg = (string) session('flash_error');
    expect($msg)->toContain('已确认 1 条');       // bug 版本:只有"推送失败:云端炸了",已确认事实全丢
    expect($msg)->toContain('慢 SQL');            // 失败归属到具体类型
    expect($msg)->toContain('云端炸了');
});

it('同批逐条 partial：已确认项不因 retryable skipped 被 UI 隐瞒，且 summary 缓存失效', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    $sync = Mockery::mock(CloudSync::class);
    $sync->shouldReceive('types')->once()->andReturn(['runtimes']);
    $sync->shouldReceive('sync')->once()->with('runtimes', false, false)->andReturn([
        'skipped'  => false,
        'ok'       => false,
        'error'    => '1 条记录等待重试；其余记录已确认，不会重复上报',
        'pushed'   => 1,
        'rejected' => 0,
    ]);
    app()->instance(CloudSync::class, $sync);
    cache()->put(ScaffoldController::CLOUD_SUMMARY_CACHE_KEY, ['stale' => true], 60);

    $this->post('/scaffold/cloud/push')->assertRedirect()->assertSessionHas('flash_error');

    $msg = (string) session('flash_error');
    expect($msg)->toContain('已确认 1 条')
        ->and($msg)->toContain('运行时错误 推送未完成')
        ->and($msg)->toContain('1 条记录等待重试')
        ->and(cache()->has(ScaffoldController::CLOUD_SUMMARY_CACHE_KEY))->toBeFalse();
});

it('运行时错误 partial 不阻断后续慢 SQL，跨类型分别完成后统一反馈', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    $sync = Mockery::mock(CloudSync::class);
    $sync->shouldReceive('types')->once()->andReturn(['runtimes', 'slow_sql']);
    $sync->shouldReceive('sync')->once()->with('runtimes', false, false)->andReturn([
        'skipped'  => false,
        'ok'       => false,
        'error'    => '1 条记录等待重试；其余记录已确认，不会重复上报',
        'pushed'   => 1,
        'rejected' => 0,
    ]);
    $sync->shouldReceive('sync')->once()->with('slow_sql', false, false)->andReturn([
        'skipped'  => false,
        'ok'       => true,
        'error'    => null,
        'pushed'   => 2,
        'rejected' => 0,
    ]);
    $sync->shouldReceive('pruneLocal')->once()->with('slow_sql', Mockery::type('int'))->andReturn([
        'purged'     => 0,
        'prunedOpen' => 0,
    ]);
    app()->instance(CloudSync::class, $sync);

    $this->post('/scaffold/cloud/push')->assertRedirect()->assertSessionHas('flash_error');

    expect((string) session('flash_error'))
        ->toContain('已确认 3 条')
        ->and((string) session('flash_error'))->toContain('运行时错误 推送未完成')
        ->and((string) session('flash_error'))->not->toContain('慢 SQL 推送未完成');
});

it('永久 skipped 的隔离数量进入成功反馈', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    $sync = Mockery::mock(CloudSync::class);
    $sync->shouldReceive('types')->once()->andReturn(['runtimes']);
    $sync->shouldReceive('sync')->once()->with('runtimes', false, false)->andReturn([
        'skipped'  => false,
        'ok'       => true,
        'error'    => null,
        'pushed'   => 1,
        'rejected' => 1,
    ]);
    $sync->shouldReceive('pruneLocal')->once()->andReturn(['purged' => 0, 'prunedOpen' => 0]);
    app()->instance(CloudSync::class, $sync);

    $this->post('/scaffold/cloud/push')->assertRedirect()->assertSessionHas('flash_message');

    $msg = (string) session('flash_message');
    expect($msg)->toContain('已确认 1 条')
        ->and($msg)->toContain('已隔离 1 条');
});

it('分类型开关全关 → 明确提示「被跳过」，不再显示「已确认 0 条」假成功', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    config(['moo-monitor.cloud.push.runtimes' => false, 'moo-monitor.cloud.push.slow_sql' => false]);
    cache()->put(ScaffoldController::CLOUD_SUMMARY_CACHE_KEY, ['stable' => true], 60);

    $r = $this->post('/scaffold/cloud/push');
    $r->assertRedirect();
    $r->assertSessionHas('flash_error');
    expect((string) session('flash_error'))->toContain('运行时错误：runtimes 推送已关闭')
        ->and((string) session('flash_error'))->toContain('慢 SQL：slow_sql 推送已关闭')
        ->and(cache()->has(ScaffoldController::CLOUD_SUMMARY_CACHE_KEY))->toBeTrue();
});

it('同类型同步锁被占用时，手动推送显示真实原因而非误报配置关闭', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    $sync = Mockery::mock(CloudSync::class);
    $sync->shouldReceive('types')->once()->andReturn(['runtimes']);
    $sync->shouldReceive('sync')->once()->with('runtimes', false, false)->andReturn([
        'skipped' => true,
        'reason'  => 'runtimes 同类型推送正在执行',
    ]);
    app()->instance(CloudSync::class, $sync);

    $this->post('/scaffold/cloud/push')->assertRedirect()->assertSessionHas('flash_error');

    expect((string) session('flash_error'))->toContain('运行时错误：runtimes 同类型推送正在执行')
        ->and((string) session('flash_error'))->not->toContain('分类型开关');
});

it('open 空但 resolved 桶有待推 → 缓冲卡显示待推,不谎报"缓冲为空"', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);

    // 只造 resolved 存量(open 空):推送会推 open+resolved 两桶,dry-run pending=1
    $dir = storage_path('moo-monitor/runtimes/resolved');
    @mkdir($dir, 0755, true);
    file_put_contents($dir . '/abcdef123456.yaml', "hash: abcdef123456\nstatus: resolved\nlast_seen: '2026-06-10T08:00:00+08:00'\ncount: 1\n");

    $html = $this->get('/scaffold/cloud')->assertOk()->getContent();

    expect($html)->toContain('待推 · pending');                     // bug 版本:走空态分支,pending 块被吞
    expect($html)->not->toContain('没有待汇聚的运行时错误');        // 不再谎报为空
    expect($html)->toContain('没有待汇聚的慢 SQL');                 // 真空的另一卡仍是空态
});

it('S-Cloud 页展示运行环境与心跳版本信息', function () {
    $html = $this->get('/scaffold/cloud')->assertOk()->getContent();

    expect($html)->toContain('运行环境');
    expect($html)->toContain('随心跳上报');
    expect($html)->toContain('心跳开关');
    expect($html)->toContain('运行配置');
    expect($html)->toContain('Scaffold');
    expect($html)->toContain('moo-monitor-laravel');
    expect($html)->toContain('Laravel');
    expect($html)->toContain('PHP');
    expect($html)->toContain('Runtime 采集');
    expect($html)->toContain('数据库');
});

it('local 可清理 Cloud 未解决噪音 + 本地 pending，并失效 summary 缓存', function () {
    app()->instance('env', 'local');
    $sync = Mockery::mock(CloudSync::class);
    $sync->shouldReceive('types')->once()->andReturn(['runtimes', 'slow_sql']);
    $sync->shouldReceive('discardLocalNoise')->once()->with('runtimes')->andReturn([
        'skipped' => false, 'ok' => true, 'cloud_deleted' => 3, 'local_discarded' => 2,
    ]);
    $sync->shouldReceive('discardLocalNoise')->once()->with('slow_sql')->andReturn([
        'skipped' => false, 'ok' => true, 'cloud_deleted' => 4, 'local_discarded' => 1,
    ]);
    app()->instance(CloudSync::class, $sync);
    cache()->put(ScaffoldController::CLOUD_SUMMARY_CACHE_KEY, ['stale' => true], 60);

    $this->post('/scaffold/cloud/discard')->assertRedirect()->assertSessionHas('flash_message');

    expect((string) session('flash_message'))
        ->toContain('Cloud 已删除 7 条未解决记录')
        ->toContain('本地已丢弃 3 条待推记录')
        ->toContain('已解决记录保持不动')
        ->and(cache()->has(ScaffoldController::CLOUD_SUMMARY_CACHE_KEY))->toBeFalse();
});

it('非 local 环境拒绝清理且不调用 Monitor', function () {
    app()->instance('env', 'staging');
    $sync = Mockery::mock(CloudSync::class);
    $sync->shouldNotReceive('discardLocalNoise');
    app()->instance(CloudSync::class, $sync);

    $this->post('/scaffold/cloud/discard')->assertRedirect()->assertSessionHas('flash_error');

    expect((string) session('flash_error'))->toContain('仅 local 开发环境');
});

it('清理按钮只在 local + 可用 Monitor + 已接入 Cloud 时显示', function () {
    $sync = Mockery::mock(CloudSync::class);
    $sync->shouldReceive('cursors')->twice()->andReturn([]);
    $sync->shouldReceive('sync')->times(4)->andReturn([
        'ok' => true, 'skipped' => false, 'changed' => 0,
    ]);
    $sync->shouldReceive('discardLocalNoise')->zeroOrMoreTimes();
    app()->instance(CloudSync::class, $sync);

    app()->instance('env', 'local');
    $localHtml = $this->get('/scaffold/cloud')->assertOk()->getContent();
    app()->instance('env', 'staging');
    $stagingHtml = $this->get('/scaffold/cloud')->assertOk()->getContent();

    expect($localHtml)->toContain('清理开发噪音')
        ->and($localHtml)->toContain('data-confirm="将清理当前项目的 local 开发噪音')
        ->and($localHtml)->not->toContain('data-challenge=')
        ->and($stagingHtml)->not->toContain('data-confirm="将清理当前项目的 local 开发噪音');
});

/**
 * 统一 JSON 信封（`{ok:true,data}` / `{ok:false,error:{code,msg,detail}}`）。
 *
 * 本页两个按钮都是**原生表单** POST，所以旧 `back()` 的 JSON 分支此前在 PHP 测试与
 * e2e 里都是**零覆盖** —— 形状曾经是 `{ok:bool, message:string}`（第三种形态：
 * 有 ok 布尔却把文案放顶层）。下面 8 条把这层锁住：形状恒定 + 表单分支文案逐字不变。
 */
it('JSON 失败回执：{ok:false,error:{code,msg,detail}} + 422，不再是顶层 message', function () {
    config(['moo-monitor.cloud.enabled' => false]);

    $r = $this->postJson('/scaffold/cloud/push');
    $r->assertStatus(422);

    $body = $r->json();
    expect(array_keys($body))->toBe(['ok', 'error'])          // 顶层恰好两键，旧的 message 不许再漏出来
        ->and($body['ok'])->toBeFalse()
        ->and($body['error']['code'])->toBe('CLOUD_NOT_CONFIGURED')
        ->and($body['error']['detail'])->toBe([])
        ->and(array_keys($body['error']))->toBe(['code', 'msg', 'detail']);
});

it('JSON 与表单两个分支共用同一份文案：error.msg 与 flash_error 逐字一致', function () {
    config(['moo-monitor.cloud.enabled' => false]);

    $this->post('/scaffold/cloud/push')->assertRedirect();
    $flash = (string) session('flash_error');

    $json = $this->postJson('/scaffold/cloud/push');

    expect($json->json('error.msg'))->toBe($flash)
        ->and($flash)->toContain('cloud 未启用');              // 别把 msg 换成泛化文案导致排障信息丢失
});

it('JSON 成功回执：{ok:true,data:{message}} + 200（顶层 message 收进 data）', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    $sync = Mockery::mock(CloudSync::class);
    $sync->shouldReceive('types')->once()->andReturn(['runtimes']);
    $sync->shouldReceive('sync')->once()->with('runtimes', false, false)->andReturn([
        'skipped' => false, 'ok' => true, 'error' => null, 'pushed' => 1, 'rejected' => 0,
    ]);
    $sync->shouldReceive('pruneLocal')->once()->with('runtimes', Mockery::type('int'))->andReturn([
        'purged' => 0, 'prunedOpen' => 0,
    ]);
    app()->instance(CloudSync::class, $sync);

    $r = $this->postJson('/scaffold/cloud/push');
    $r->assertOk();

    $body = $r->json();
    expect(array_keys($body))->toBe(['ok', 'data'])           // 成功信封顶层恰好两键
        ->and($body['ok'])->toBeTrue()
        ->and($body['data']['message'])->toBe('已确认 1 条');
});

it('JSON 推送部分完成 → PUSH_INCOMPLETE，且已确认事实留在 msg 里（不因失败而丢）', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    $sync = Mockery::mock(CloudSync::class);
    $sync->shouldReceive('types')->once()->andReturn(['runtimes']);
    $sync->shouldReceive('sync')->once()->with('runtimes', false, false)->andReturn([
        'skipped'  => false,
        'ok'       => false,
        'error'    => '1 条记录等待重试；其余记录已确认，不会重复上报',
        'pushed'   => 1,
        'rejected' => 0,
    ]);
    app()->instance(CloudSync::class, $sync);

    $r = $this->postJson('/scaffold/cloud/push');
    $r->assertStatus(422);

    expect($r->json('error.code'))->toBe('PUSH_INCOMPLETE')
        ->and($r->json('error.msg'))->toContain('已确认 1 条')
        ->and($r->json('error.msg'))->toContain('运行时错误 推送未完成');
});

it('JSON 推送全跳过 → PUSH_SKIPPED（与「部分失败」是两个不同的机器码）', function () {
    Http::fake(['*' => Http::response(['ok' => true])]);
    config(['moo-monitor.cloud.push.runtimes' => false, 'moo-monitor.cloud.push.slow_sql' => false]);

    $r = $this->postJson('/scaffold/cloud/push');
    $r->assertStatus(422);

    expect($r->json('error.code'))->toBe('PUSH_SKIPPED')
        ->and($r->json('error.msg'))->toContain('推送未执行');
});

it('JSON 清理非 local → NOT_LOCAL_ONLY，且不触达 Monitor', function () {
    app()->instance('env', 'staging');
    $sync = Mockery::mock(CloudSync::class);
    $sync->shouldNotReceive('discardLocalNoise');
    app()->instance(CloudSync::class, $sync);

    $r = $this->postJson('/scaffold/cloud/discard');
    $r->assertStatus(422);

    expect($r->json('error.code'))->toBe('NOT_LOCAL_ONLY')
        ->and($r->json('error.msg'))->toContain('仅 local 开发环境');
});

it('JSON 清理成功 → 成功信封（data.message 带上 Cloud 删除 / 本地丢弃两条计数）', function () {
    app()->instance('env', 'local');
    $sync = Mockery::mock(CloudSync::class);
    $sync->shouldReceive('types')->once()->andReturn(['runtimes', 'slow_sql']);
    $sync->shouldReceive('discardLocalNoise')->once()->with('runtimes')->andReturn([
        'skipped' => false, 'ok' => true, 'cloud_deleted' => 3, 'local_discarded' => 2,
    ]);
    $sync->shouldReceive('discardLocalNoise')->once()->with('slow_sql')->andReturn([
        'skipped' => false, 'ok' => true, 'cloud_deleted' => 4, 'local_discarded' => 1,
    ]);
    app()->instance(CloudSync::class, $sync);

    $r = $this->postJson('/scaffold/cloud/discard');
    $r->assertOk();

    $body = $r->json();
    expect(array_keys($body))->toBe(['ok', 'data'])
        ->and($body['data']['message'])->toContain('Cloud 已删除 7 条未解决记录')
        ->and($body['data']['message'])->toContain('本地已丢弃 3 条待推记录');
});

it('JSON 清理部分失败 → DISCARD_INCOMPLETE（与 PUSH_INCOMPLETE 分开命名）', function () {
    app()->instance('env', 'local');
    $sync = Mockery::mock(CloudSync::class);
    $sync->shouldReceive('types')->once()->andReturn(['runtimes']);
    $sync->shouldReceive('discardLocalNoise')->once()->with('runtimes')->andReturn([
        'skipped' => false, 'ok' => false, 'error' => '云端炸了',
    ]);
    app()->instance(CloudSync::class, $sync);

    $r = $this->postJson('/scaffold/cloud/discard');
    $r->assertStatus(422);

    expect($r->json('error.code'))->toBe('DISCARD_INCOMPLETE')
        ->and($r->json('error.msg'))->toContain('云端炸了');
});
