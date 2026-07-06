<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Http;
use Mooeen\Scaffold\Http\Middleware\EnforceScaffoldWritable;
use Mooeen\Scaffold\Http\Middleware\ScaffoldAuthenticate;

/**
 * 首页(dashboard)三个问题的回归锁(2026-06-10 修):
 *   1. 「控制器」统计按模块求和 —— 不再走 getControllers(true) 的短类名扁平合并
 *      (跨模块同名互相覆盖 → 少计)。
 *   2. 云端面板 recent 行对外部数据形状免疫 —— 缺 key / 坏时间串不再 500
 *      (坏 payload 会被缓存 60s → 一次形状漂移首页连环炸)。
 *   3. 发布历史懒加载重构后渲染不回归 —— action 明细只为当前页构建、签名缓存
 *      在新发布后立即失效、死数据 publish_history 不再传视图。
 */
beforeEach(function () {
    $this->withoutMiddleware([
        ScaffoldAuthenticate::class,
        VerifyCsrfToken::class,
        EnforceScaffoldWritable::class,
    ]);
});

// ─── 1. 控制器统计:跨模块同名不丢 ─────────────────────────────────────────

it('首页「控制器」统计按模块求和,跨模块同名(CategoryController)不互相覆盖', function () {
    $fs   = app(Filesystem::class);
    $orig = app()->storagePath();
    app()->useStoragePath(sys_get_temp_dir() . '/dash_stat_' . uniqid());
    $fs->ensureDirectoryExists(storage_path('scaffold'));
    // 两个模块各有 CategoryController:扁平合并(短类名作 key)只剩 2,按模块求和是 3
    $fs->put(storage_path('scaffold/controllers.php'), '<?php return ' . var_export([
        'Solution' => ['CategoryController' => [], 'TrendController' => []],
        'WorkTask' => ['CategoryController' => []],
    ], true) . ';');
    // 其余统计与本测试无关,指向空目录/空 apps 让它们走兜底
    config(['scaffold.controller' => [], 'scaffold.api.history' => 'dash_stat_no_hist']);

    try {
        $r = $this->get('/scaffold');
        $r->assertOk();
        $r->assertViewHas('stats', function (array $stats): bool {
            $ctrl = collect($stats)->firstWhere('label', '控制器');

            return ($ctrl['value'] ?? null) === 3;
        });
    } finally {
        $fs->deleteDirectory(storage_path());
        app()->useStoragePath($orig);
    }
});

// ─── 2. 云端面板:外部形状漂移不炸首页 ─────────────────────────────────────

it('云端 summary recent 行缺 key / 坏时间串 → 首页仍 200(形状漂移防护)', function () {
    config([
        'moo-monitor.cloud.enabled'  => true,
        'moo-monitor.cloud.base_url' => 'https://cloud.test',
        'moo-monitor.cloud.token'    => 'tok-' . str_repeat('a1', 20),
    ]);
    Http::fake([
        'cloud.test/api/v1/summary' => Http::response([
            'ok'      => true,
            'project' => ['slug' => 'wc'],
            'stats'   => [],
            'recent'  => [
                // runtime 行只有 hash:exc_class / exc_message / status / count / last_seen 全缺
                'runtimes'     => [['hash' => 'abc123abc123']],
                'slow_queries' => [],
                // todo 行缺 title / priority / status,created_at 是坏时间串
                'todos' => [['id' => '01', 'created_at' => 'not-a-date']],
            ],
            'generated_at' => '2026-06-10T10:00:00+00:00',
        ], 200),
    ]);

    // 修复前:Blade 裸取 $r['exc_message'] / $t['priority'] → ErrorException → 500
    $this->get('/scaffold')->assertOk()->assertSee('S-Cloud 云端汇聚');
});

// ─── 3. 发布历史:懒加载渲染 + 签名缓存失效 + 死数据移除 ─────────────────────

function dashHist_writeYaml(string $dir, string $name, string $actionName, string $publishedAt): void
{
    file_put_contents($dir . '/' . $name, <<<YAML
meta:
  app: admin
  namespace: Light
  author: tester
  controller_count: 1
  action_count: 1
  published_at: '{$publishedAt}'
actions:
  -
    name: '{$actionName}'
    controller: MemoController
    action_key: index_get
    method: get
    uri: 'light/memos'
    operation: create
    debug:
      app: admin
      folder: Light
      controller: Memo
      action: index_get
YAML);
}

it('发布历史懒加载:当前页 action 明细带 debug 链接;新发布立即可见(签名失效);publish_history 死数据不再传视图', function () {
    $rel = 'dash_hist_' . uniqid();
    $dir = base_path($rel);
    @mkdir($dir, 0777, true);
    config(['scaffold.api.history' => $rel]);

    try {
        dashHist_writeYaml($dir, 'publish_a.yaml', '备忘录列表A', '2026-06-10 10:00:00');

        $r1 = $this->get('/scaffold');
        $r1->assertOk()
            ->assertSee('备忘录列表A')                       // 懒加载后的 action 明细仍渲染
            ->assertSee('light/memos')
            ->assertSee('api/request?app=admin', false)      // debug_url 链接照常构建
            ->assertViewMissing('publish_history');          // 整包死数据已移除

        // 第二次发布:文件集合变化 → 签名缓存立即失效,无 TTL 等待
        dashHist_writeYaml($dir, 'publish_b.yaml', '备忘录列表B', '2026-06-10 11:00:00');

        $this->get('/scaffold')->assertOk()->assertSee('备忘录列表B');
    } finally {
        app(Filesystem::class)->deleteDirectory($dir);
    }
});

// ─── 4. 数组查询参数不炸页(?history_app[]=x)──────────────────────────────

it('?history_app[]=x 数组参数 → 回落默认 tab 仍 200(原 (string) 强转数组 500)', function () {
    $rel = 'dash_hist_' . uniqid();
    $dir = base_path($rel);
    @mkdir($dir, 0777, true);
    config(['scaffold.api.history' => $rel]);

    try {
        dashHist_writeYaml($dir, 'publish_a.yaml', '备忘录列表A', '2026-06-10 10:00:00');

        // 数组形态的两个分页参数都不允许炸页
        $this->get('/scaffold?history_app[]=x')->assertOk()->assertSee('备忘录列表A');
        $this->get('/scaffold?history_app=admin&history_page[]=2')->assertOk();
    } finally {
        app(Filesystem::class)->deleteDirectory($dir);
    }
});

// ─── 5. 云端 project.slug 形状漂移(PHP 侧)不炸页 ──────────────────────────

it('云端 summary project.slug 是数组 → 控制台入口回落 /app 仍 200(补 PHP 侧缺口)', function () {
    config([
        'moo-monitor.cloud.enabled'  => true,
        'moo-monitor.cloud.base_url' => 'https://cloud.test',
        'moo-monitor.cloud.token'    => 'tok-' . str_repeat('a1', 20),
    ]);
    Http::fake([
        'cloud.test/api/v1/summary' => Http::response([
            'ok'           => true,
            'project'      => ['slug' => ['weird' => 'array']],   // 形状漂移:slug 不是字符串
            'stats'        => [],
            'recent'       => ['runtimes' => [], 'slow_queries' => [], 'todos' => []],
            'generated_at' => '2026-06-10T10:00:00+00:00',
        ], 200),
    ]);

    // 修复前:cloudConsoleUrl 把数组插进字符串 → Array-to-string ErrorException 500
    $this->get('/scaffold')->assertOk()->assertSee('https://cloud.test/app'); // 回落 /app,不深链
});

// ─── 6. 接口统计:deprecated 排除 + 签名缓存失效 ───────────────────────────

it('「接口」统计排除 deprecated;schema 变更后签名缓存立即失效', function () {
    $fs  = app(Filesystem::class);
    $rel = 'dash_api_' . uniqid();
    $dir = base_path($rel) . '/admin/Light';
    $fs->ensureDirectoryExists($dir);
    config([
        'scaffold.api.schema'  => $rel,
        'scaffold.api.history' => $rel . '_no_hist',
        'scaffold.controller'  => ['admin' => ['name' => ['zh-CN' => '后台', 'en' => 'Admin']]],
    ]);

    $apiStat = fn ($r) => collect($r->viewData('stats'))->firstWhere('label', '接口')['value'] ?? null;

    try {
        // 2 个 action,其一 deprecated → 活接口 = 1
        file_put_contents($dir . '/Memo.yaml', "controller:\n  class: MemoController\nactions:\n  index_get:\n    name: 列表\n  old_get:\n    name: 旧接口\n    deprecated: true\n");

        $r1 = $this->get('/scaffold');
        $r1->assertOk();
        expect($apiStat($r1))->toBe(1);

        // 追加一个活 action(mtime/内容变化)→ 签名失效,立即看到 2
        file_put_contents($dir . '/Memo.yaml', "controller:\n  class: MemoController\nactions:\n  index_get:\n    name: 列表\n  show_get:\n    name: 详情\n  old_get:\n    name: 旧接口\n    deprecated: true\n");
        touch($dir . '/Memo.yaml', time() + 2); // 同秒内重写,mtime 显式前移保证签名变化

        $r2 = $this->get('/scaffold');
        expect($apiStat($r2))->toBe(2);
    } finally {
        $fs->deleteDirectory(base_path($rel));
    }
});
