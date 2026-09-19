<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Mooeen\Scaffold\Http\Middleware\ScaffoldAuthenticate;

/**
 * plan-52 文档中心 HTTP Feature 测试:路由面冒烟 + 写锁。
 * sandbox:docs 落 base_path 下临时 rel 目录,跑完删。bypass 鉴权 / CSRF;
 * EnforceScaffoldWritable 保留在位(test 环境非 prod、readonly=false 默认放行),
 * 只读锁那条单独 config readonly=true 触发 403。
 */
beforeEach(function () {
    $this->rel     = 'docs_http_' . uniqid();
    $this->docsDir = base_path($this->rel);
    $this->fs      = app(Filesystem::class);
    $this->fs->ensureDirectoryExists($this->docsDir);
    config(['scaffold.docs.path' => $this->rel, 'scaffold.config_ui.readonly' => false]);
    $this->withoutMiddleware([ScaffoldAuthenticate::class, VerifyCsrfToken::class]);
});

afterEach(function () {
    $this->fs->deleteDirectory($this->docsDir);
});

it('GET /scaffold/docs(裸)→ 目录主页', function () {
    file_put_contents($this->docsDir . '/intro.md', "---\ntitle: 介绍\ngroup: 指南\n---\n# 介绍正文\n");
    $this->get('/scaffold/docs')
        ->assertOk()
        ->assertViewIs('scaffold::docs.home')
        ->assertViewHas('total', 1)
        ->assertSee('文档目录')
        ->assertSee('介绍');
});

it('GET /scaffold/docs?doc=slug 渲染单篇', function () {
    file_put_contents($this->docsDir . '/intro.md', "---\ntitle: 介绍\ngroup: 指南\n---\n# 介绍正文\n");
    $this->get('/scaffold/docs?doc=intro')
        ->assertOk()
        ->assertViewHas('current_key', 'intro')
        ->assertSee('介绍');
});

it('GET /scaffold/docs?doc=不存在 → 200 + not_found', function () {
    file_put_contents($this->docsDir . '/a.md', "# A\n");
    $this->get('/scaffold/docs?doc=nope')
        ->assertOk()
        ->assertViewHas('not_found', true);
});

it('GET /scaffold/docs/edit 无 doc → 新建模板', function () {
    $this->get('/scaffold/docs/edit')
        ->assertOk()
        ->assertViewHas('is_new', true);
});

it('POST /scaffold/docs/save 落盘(含中文嵌套路径)+ 返回 redirect', function () {
    $r = $this->postJson('/scaffold/docs/save', [
        'slug'    => '设计/流程',
        'content' => "---\ntitle: 流程\n---\n正文",
    ]);
    $r->assertOk()->assertJson(['ok' => true, 'data' => ['slug' => '设计/流程']]);
    expect(is_file($this->docsDir . '/设计/流程.md'))->toBeTrue();
});

it('POST /scaffold/docs/save 非法 slug(穿越)→ 422', function () {
    $this->postJson('/scaffold/docs/save', ['slug' => '../evil', 'content' => 'x'])
        ->assertStatus(422);
    expect(is_file(base_path('evil.md')))->toBeFalse();
});

it('POST /scaffold/docs/preview 渲染 shortcode chip(复用 DocMarkdownRenderer)', function () {
    $r = $this->postJson('/scaffold/docs/preview', ['content' => "# 标题\n\n[[db: Market]]"]);
    $r->assertOk();
    expect($r->json('data.html'))->toContain('doc-shortcode--db');
});

it('POST /scaffold/docs/delete 删除文件', function () {
    file_put_contents($this->docsDir . '/tmp.md', "x\n");
    $r = $this->postJson('/scaffold/docs/delete', ['slug' => 'tmp'])->assertOk()->assertJson(['ok' => true]);
    expect($r->json('data.redirect'))->toContain('/scaffold/docs');
    expect(is_file($this->docsDir . '/tmp.md'))->toBeFalse();
});

it('POST /scaffold/docs/reorder 全局编号写回 + 顺序生效', function () {
    file_put_contents($this->docsDir . '/a.md', "---\ntitle: A\ngroup: 指南\norder: 1\n---\n");
    file_put_contents($this->docsDir . '/b.md', "---\ntitle: B\ngroup: 指南\norder: 2\n---\n");

    $this->postJson('/scaffold/docs/reorder', ['slugs' => ['b', 'a']])
        ->assertOk()->assertJson(['ok' => true, 'data' => ['changed' => 2]]);
    expect(file_get_contents($this->docsDir . '/b.md'))->toContain('order: 10');
    expect(file_get_contents($this->docsDir . '/a.md'))->toContain('order: 20');
});

it('POST /scaffold/docs/reorder 集合不匹配 → 422(防并发错位)', function () {
    file_put_contents($this->docsDir . '/a.md', "A\n");
    file_put_contents($this->docsDir . '/b.md', "B\n");
    $this->postJson('/scaffold/docs/reorder', ['slugs' => ['a']])->assertStatus(422);
});

it('只读模式 POST /scaffold/docs/reorder → 403(EnforceScaffoldWritable 锁 docs/*)', function () {
    config(['scaffold.config_ui.readonly' => true]);
    $this->postJson('/scaffold/docs/reorder', ['slugs' => ['a']])->assertStatus(403);
});

it('GET /scaffold/docs?doc= 阅读页带 prev/next(全局阅读顺序相邻)', function () {
    file_put_contents($this->docsDir . '/a.md', "---\ntitle: A\ngroup: 指南\norder: 10\n---\n");
    file_put_contents($this->docsDir . '/b.md', "---\ntitle: B\ngroup: 指南\norder: 20\n---\n");
    file_put_contents($this->docsDir . '/c.md', "---\ntitle: C\ngroup: 指南\norder: 30\n---\n");

    $r = $this->get('/scaffold/docs?doc=b')->assertOk();
    expect($r->viewData('prev')['slug'])->toBe('a');
    expect($r->viewData('next')['slug'])->toBe('c');
    // 首篇无 prev、末篇无 next
    expect($this->get('/scaffold/docs?doc=a')->viewData('prev'))->toBeNull();
    expect($this->get('/scaffold/docs?doc=c')->viewData('next'))->toBeNull();
});

it('GET /scaffold/docs/search 命中标题与正文,返回 href;q 太短 → 422', function () {
    file_put_contents($this->docsDir . '/x.md', "---\ntitle: 网关设计\ngroup: 指南\n---\n正文讲网关限流。\n");
    file_put_contents($this->docsDir . '/y.md', "---\ntitle: 无关\ngroup: 指南\n---\n不相关。\n");

    $r = $this->getJson('/scaffold/docs/search?q=' . urlencode('网关'))->assertOk();
    expect($r->json('data.results'))->toHaveCount(1);
    expect($r->json('data.results.0.slug'))->toBe('x');
    expect($r->json('data.results.0.href'))->toContain('doc=');
    expect($r->json('data.truncated'))->toBeFalse();

    $this->getJson('/scaffold/docs/search?q=x')->assertStatus(422);
});

it('GET /scaffold/docs/edit 新建模板不再写死 order(缺省沉组尾显「–」)', function () {
    $raw = $this->get('/scaffold/docs/edit')->viewData('raw');
    expect($raw)->toContain('title: 新文档');
    expect(str_contains($raw, 'order:'))->toBeFalse();
});

it('GET /scaffold/docs/picker → JSON 含 endpoints + tables 数组', function () {
    $r = $this->get('/scaffold/docs/picker');
    $r->assertOk();
    expect($r->json('data.endpoints'))->toBeArray();
    expect($r->json('data.tables'))->toBeArray();
});

it('只读模式 POST /scaffold/docs/save → 403(EnforceScaffoldWritable 锁 docs/*)', function () {
    config(['scaffold.config_ui.readonly' => true]);
    $this->postJson('/scaffold/docs/save', ['slug' => 'x', 'content' => 'y'])
        ->assertStatus(403);
});

// ─── plan-53 出身:src 参数 ───

it('POST save 带 src=包 → 落包 docs/,host 不落;redirect 带 src', function () {
    // 假包放系统临时目录:Testbench 的 base_path 在本包 vendor/ 内,放 base_path 下会被写权硬线判成 vendor 拷贝
    $pkgRoot = sys_get_temp_dir() . '/scaffold_docs_http_pkg_' . uniqid();
    @mkdir($pkgRoot . '/scaffold/database', 0755, true);
    @mkdir($pkgRoot . '/docs', 0755, true);
    file_put_contents($pkgRoot . '/composer.json', json_encode(['name' => 'acme/dpkg', 'autoload' => ['psr-4' => ['Acme\\D\\' => 'src/']]]));
    app()->instance(\Mooeen\Scaffold\Support\PackageRegistry::class, new \Mooeen\Scaffold\Support\PackageRegistry([$pkgRoot]));

    $r = $this->postJson('/scaffold/docs/save', ['slug' => 'pkg篇', 'content' => '正文', 'src' => 'dpkg']);
    $r->assertOk()->assertJson(['ok' => true, 'data' => ['slug' => 'pkg篇']]);
    expect($r->json('data.redirect'))->toContain('src=dpkg');
    expect(is_file($pkgRoot . '/docs/pkg篇.md'))->toBeTrue();
    expect(is_file($this->docsDir . '/pkg篇.md'))->toBeFalse();

    $this->fs->deleteDirectory($pkgRoot);
});

it('POST save 未知 src → 422(写操作不静默回退 host)', function () {
    $this->postJson('/scaffold/docs/save', ['slug' => 'x', 'content' => 'y', 'src' => 'ghost-pkg'])
        ->assertStatus(422);
    expect(is_file($this->docsDir . '/x.md'))->toBeFalse();
});

// ─── 统一 JSON 信封（第 2 项 · 阶段 2） ───

it('失败端点的信封：{ok:false,error:{code,msg,detail}} + 真 HTTP 码', function () {
    // `UNKNOWN_SOURCE` 是「写操作不静默回退 host」那条守卫的机器可读码，三个写端点共用同一段
    // 判断 ⇒ 三处必须完全同形。前端从「把 error 当字符串 toast」改成读 `error.msg`，
    // 所以这里同时反证 `error` 不再是字符串（否则前端会 toast 出 [object Object]）。
    foreach (['save', 'reorder', 'delete'] as $endpoint) {
        $payload = $endpoint === 'reorder'
            ? ['slugs' => ['a'], 'src' => 'ghost-pkg']
            : ['slug' => 'x', 'content' => 'y', 'src' => 'ghost-pkg'];

        $res  = $this->postJson('/scaffold/docs/' . $endpoint, $payload)->assertStatus(422);
        $body = json_decode($res->getContent(), true);

        expect($body['ok'])->toBeFalse()
            ->and($body['error']['code'])->toBe('UNKNOWN_SOURCE')
            ->and($body['error']['msg'])->toContain('ghost-pkg')
            ->and($body['error']['detail'])->toBeArray()   // 缺省也必须是数组：形状恒定，前端才敢直接取
            ->and($body['error'])->not->toBeString();
    }
    expect(is_file($this->docsDir . '/x.md'))->toBeFalse();
});

it('成功端点的信封：payload 一律在 data 下，顶层只有 ok / data', function () {
    file_put_contents($this->docsDir . '/x.md', "---\ntitle: 网关\ngroup: 指南\n---\n网关正文。\n");
    file_put_contents($this->docsDir . '/y.md', "y\n");

    $body = fn ($res) => json_decode($res->getContent(), true);

    $bodies = [
        'preview' => $body($this->postJson('/scaffold/docs/preview', ['content' => '# 标题'])->assertOk()),
        'reorder' => $body($this->postJson('/scaffold/docs/reorder', ['slugs' => ['x', 'y']])->assertOk()),
        'picker'  => $body($this->get('/scaffold/docs/picker')->assertOk()),
        'search'  => $body($this->get('/scaffold/docs/search?q=' . urlencode('网关'))->assertOk()),
    ];

    foreach ($bodies as $b) {
        // 顶层只能有 ok / data 两个键 —— 反证没有哪个载荷键(html/changed/endpoints/…)
        // 漏在顶层。这条是「全量统一」这个决定的守卫：旧形态就是载荷直接铺在顶层。
        expect(array_keys($b))->toBe(['ok', 'data'])
            ->and($b['ok'])->toBeTrue();
    }

    // 逐端点钉住「载荷在 data 的哪个键上」—— 前端解包后读的就是这些
    expect($bodies['preview']['data'])->toHaveKey('html')
        ->and($bodies['reorder']['data'])->toHaveKey('changed')
        ->and($bodies['picker']['data'])->toHaveKeys(['endpoints', 'tables'])
        ->and($bodies['search']['data'])->toHaveKeys(['q', 'results', 'truncated']);
});
