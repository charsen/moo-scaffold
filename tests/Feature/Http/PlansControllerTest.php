<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Http\Middleware\ScaffoldAuthenticate;
use Mooeen\Scaffold\Support\PlansRepository;

beforeEach(function () {
    $this->directory = sys_get_temp_dir() . '/scaffold_plans_' . uniqid();
    mkdir($this->directory);
    mkdir($this->directory . '/archive');
    config(['scaffold.plans.path' => $this->directory]);
    file_put_contents($this->directory . '/README.md', "# 索引\n\n[方案](./2-plan.md#中文-章节)\n\n[归档](archive/旧稿.md)\n\n[越界](../secret.md)\n\n[报告](report.csv)\n\n[外站](https://example.com)");
    file_put_contents($this->directory . '/2-plan.md', "# 方案\n\n## 中文 章节\n\n## 中文 章节\n\n<script>alert(1)</script>");
    file_put_contents($this->directory . '/10-plan.md', '# 后续');
    file_put_contents($this->directory . '/archive/旧稿.md', "# 旧稿\n\n[返回](../README.md)");
});
afterEach(function () {
    (new Filesystem)->deleteDirectory($this->directory);
});

it('prioritizes the README and naturally sorts files within directories', function () {
    expect(array_column(app(PlansRepository::class)->all(), 'slug'))->toBe(['README.md', '2-plan.md', '10-plan.md', 'archive/旧稿.md']);
});

it('renders a read-only index with safe internal links and archive navigation', function () {
    $this->withoutMiddleware(ScaffoldAuthenticate::class);
    config(['scaffold.config_ui.readonly' => true]);
    $response = $this->get('/scaffold/plans')->assertOk()->assertSee('研发计划');
    $response->assertSee(route('plans.index', ['doc' => '2-plan.md']) . '#' . rawurlencode('中文-章节'), false)
        ->assertSee(route('plans.index', ['doc' => 'archive/旧稿.md']), false)
        ->assertSee('href="https://example.com"', false)
        ->assertDontSee('href="../secret.md"', false)->assertDontSee('href="report.csv"', false)
        ->assertDontSee('docs/edit');
    $this->get('/scaffold/plans?' . http_build_query(['doc' => 'archive/旧稿.md']))
        ->assertOk()->assertSee(route('plans.index', ['doc' => 'README.md']), false);
    $this->post('/scaffold/plans')->assertNotFound();
});

it('provides stable unique anchors and escapes unsafe HTML', function () {
    $this->withoutMiddleware(ScaffoldAuthenticate::class);
    $this->get('/scaffold/plans?doc=2-plan.md')->assertOk()
        ->assertSee('id="中文-章节"', false)->assertSee('id="中文-章节-1"', false)
        ->assertDontSee('<script>alert(1)</script>', false);
});

it('rejects traversal and excludes hidden, non-Markdown and external symlink files', function () {
    $this->withoutMiddleware(ScaffoldAuthenticate::class);
    file_put_contents($this->directory . '/.hidden.md', 'hidden');
    file_put_contents($this->directory . '/report.csv', 'private');
    symlink($this->directory . '/README.md', $this->directory . '/archive/leak.md');
    config(['scaffold.plans.path' => $this->directory . '/archive']);
    expect(array_column(app(PlansRepository::class)->all(), 'slug'))->toBe(['旧稿.md']);
    foreach (['../README.md', 'leak.md', '/etc/passwd', 'missing.md'] as $slug) {
        $this->get('/scaffold/plans?' . http_build_query(['doc' => $slug]))->assertNotFound();
    }
    $this->getJson('/scaffold/plans?doc[]=x')->assertUnprocessable();
});

it('requires authentication and shows an empty state for missing directories', function () {
    config(['scaffold.auth.enabled' => true]);
    $this->getJson('/scaffold/plans')->assertUnauthorized();
    $this->withoutMiddleware(ScaffoldAuthenticate::class);
    config(['scaffold.plans.path' => $this->directory . '/missing']);
    $this->get('/scaffold/plans')->assertOk()->assertSee('暂无研发计划');
    expect(is_dir($this->directory . '/missing'))->toBeFalse();
});

it('reads the configured sibling directory and falls back when README is absent', function () {
    $original = base_path();
    mkdir($this->directory . '/engine');
    app()->setBasePath($this->directory . '/engine');
    config(['scaffold.plans.path' => '../archive']);
    try {
        expect(array_column(app(PlansRepository::class)->all(), 'slug'))->toBe(['旧稿.md']);
    } finally {
        app()->setBasePath($original);
    }
});
