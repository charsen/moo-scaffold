<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Mooeen\Scaffold\Http\Middleware\EnforceScaffoldWritable;
use Mooeen\Scaffold\Http\Middleware\ScaffoldAuthenticate;
use Mooeen\Scaffold\Support\LocalMarkdownEditor;
use Mooeen\Scaffold\Support\PlansRepository;
use Mooeen\Scaffold\Support\RecordMarkdownDocument;
use Mooeen\Scaffold\Support\ReleaseRecordsRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;

dataset('record sections', [
    ['plans', 'plans', 'doc'],
    ['release-records', 'release_records', 'record'],
]);

beforeEach(function () {
    $this->directory = sys_get_temp_dir() . '/scaffold_record_edit_' . uniqid();
    mkdir($this->directory);
    mkdir($this->directory . '/nested');
    $this->slug = 'nested/中文.md';
    $this->raw  = "\n# 原文\n\n<!-- editor metadata -->\n\n尾部空白  \n\n";
    file_put_contents($this->directory . '/' . $this->slug, $this->raw);
    config(['scaffold.plans.path' => $this->directory, 'scaffold.release_records.path' => $this->directory, 'scaffold.config_ui.readonly' => false]);
    app()->instance('env', 'local');
    $this->withoutMiddleware(ScaffoldAuthenticate::class);
    $this->withSession(['_token' => 'local-markdown-test-token']);
    $this->withHeader('X-CSRF-TOKEN', 'local-markdown-test-token');
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->directory);
});

it('edits raw Markdown and saves exact content through Host normalization middleware', function ($route, $scope, $query) {
    app(\Illuminate\Contracts\Http\Kernel::class)->pushMiddleware(TrimStrings::class);
    app(\Illuminate\Contracts\Http\Kernel::class)->pushMiddleware(ConvertEmptyStringsToNull::class);
    $this->get('/scaffold/' . $route)->assertOk()->assertSee(route($route . '.edit', ['slug' => $this->slug]), false);
    $this->get('/scaffold/' . $route . '/edit?' . http_build_query(['slug' => $this->slug]))
        ->assertOk()->assertViewHas('raw', $this->raw)->assertDontSee('id="doc_delete"', false);
    $content  = "---\r\ntitle: Frontmatter title\r\ngroup: 自定义分组\r\norder: 2\r\ntags: [回归, 本地]\r\ncustom: keep\r\n---\r\n\r\n# 新正文\r\n\r\n<!-- preserved -->\r\n\r\n  ";
    $response = $this->postJson('/scaffold/' . $route . '/save', ['slug' => $this->slug, 'content' => $content, 'version' => hash('sha256', $this->raw)])
        ->assertOk()->assertJsonPath('version', hash('sha256', $content));
    expect(file_get_contents($this->directory . '/' . $this->slug))->toBe($content);
    $this->get('/scaffold/' . $route . '?' . http_build_query([$query => $this->slug]))
        ->assertOk()->assertSee('Frontmatter title')->assertSee('自定义分组')->assertSee('回归')
        ->assertDontSee('custom: keep')->assertDontSee('preserved');
    $this->postJson('/scaffold/' . $route . '/save', ['slug' => $this->slug, 'content' => '', 'version' => $response->json('version')])->assertOk();
    expect(file_get_contents($this->directory . '/' . $this->slug))->toBe('');
})->with('record sections');

it('previews frontmatter safely without writing and keeps plan relative links', function ($route, $scope, $query) {
    $content = "---\ntitle: Preview metadata\n---\n\n# Preview body\n\n<!-- hidden -->\n\n<script>alert(1)</script>\n\n[Self](中文.md)";
    $html    = $this->postJson('/scaffold/' . $route . '/preview', ['slug' => $this->slug, 'content' => $content])
        ->assertOk()->json('html');
    expect($html)->toContain('Preview body')->not->toContain('Preview metadata', 'hidden', '<script>alert(1)</script>');
    if ($scope === 'plans') {
        expect($html)->toContain(route('plans.index', ['doc' => $this->slug]));
    }
    expect(file_get_contents($this->directory . '/' . $this->slug))->toBe($this->raw);
})->with('record sections');

it('blocks stale saves and keeps the newer file intact', function ($route, $scope) {
    file_put_contents($this->directory . '/' . $this->slug, '# Changed elsewhere');
    $this->postJson('/scaffold/' . $route . '/save', ['slug' => $this->slug, 'content' => '# Stale', 'version' => hash('sha256', $this->raw)])->assertConflict();
    expect(file_get_contents($this->directory . '/' . $this->slug))->toBe('# Changed elsewhere');
})->with('record sections');

it('rejects edit and writes outside local or with forced readonly even without middleware', function ($route, $scope) {
    foreach ([['production', false], ['staging', false], ['testing', false], ['local', true]] as [$env, $readonly]) {
        app()->instance('env', $env);
        config(['scaffold.config_ui.readonly' => $readonly]);
        $this->get('/scaffold/' . $route)->assertOk()->assertSee('只读')->assertDontSee('/' . $route . '/edit');
        $this->getJson('/scaffold/' . $route . '/edit?' . http_build_query(['slug' => $this->slug]))->assertForbidden();
        foreach (['save', 'preview'] as $action) {
            $this->postJson('/scaffold/' . $route . '/' . $action, ['slug' => $this->slug, 'content' => 'blocked', 'version' => hash('sha256', $this->raw)])->assertForbidden();
        }
        expect(fn () => app(LocalMarkdownEditor::class)->save($scope, $this->slug, 'blocked', hash('sha256', $this->raw)))
            ->toThrow(HttpException::class);
    }
    $this->withoutMiddleware(EnforceScaffoldWritable::class);
    $this->postJson('/scaffold/' . $route . '/save', ['slug' => $this->slug, 'content' => 'blocked', 'version' => hash('sha256', $this->raw)])->assertForbidden();
    expect(file_get_contents($this->directory . '/' . $this->slug))->toBe($this->raw);
})->with('record sections');

it('rejects missing files traversal hidden files and symlink targets without creating anything', function ($route, $scope) {
    file_put_contents($this->directory . '/.hidden.md', '# Hidden');
    file_put_contents($this->directory . '/_draft.md', '# Draft');
    file_put_contents($this->directory . '/file.txt', 'Private');
    symlink($this->directory . '/' . $this->slug, $this->directory . '/alias.md');
    symlink($this->directory . '/nested', $this->directory . '/alias');
    symlink(dirname($this->directory), $this->directory . '/outside');
    foreach (['../outside.md', '/etc/passwd', 'nested/../.hidden.md', '.hidden.md', '_draft.md', 'file.txt', 'alias.md', 'alias/中文.md', 'outside/secret.md', 'missing.md', 'missing/file.md', 'nested\\中文.md'] as $slug) {
        $this->getJson('/scaffold/' . $route . '/edit?' . http_build_query(['slug' => $slug]))->assertNotFound();
        $this->postJson('/scaffold/' . $route . '/save', ['slug' => $slug, 'content' => 'bad', 'version' => hash('sha256', $this->raw)])->assertNotFound();
    }
    expect(is_dir($this->directory . '/missing'))->toBeFalse();
    expect(file_get_contents($this->directory . '/' . $this->slug))->toBe($this->raw);
})->with('record sections');

it('validates inputs and requires authentication for every editor endpoint', function ($route) {
    foreach ([[], ['slug' => $this->slug, 'content' => ['bad'], 'version' => str_repeat('a', 64)], ['slug' => $this->slug, 'content' => 'x', 'version' => 'bad']] as $data) {
        $this->postJson('/scaffold/' . $route . '/save', $data)->assertUnprocessable();
    }
    $this->post('/scaffold/' . $route . '/save', ['slug' => $this->slug, 'content' => 'form'])->assertStatus(415);
    $this->withMiddleware(ScaffoldAuthenticate::class);
    config(['scaffold.auth.enabled' => true]);
    $this->getJson('/scaffold/' . $route . '/edit?slug=x.md')->assertUnauthorized();
    foreach (['save', 'preview'] as $action) {
        $this->postJson('/scaffold/' . $route . '/' . $action, [])->assertUnauthorized();
    }
})->with('record sections');

it('applies plan metadata while retaining README priority and natural fallback order', function () {
    file_put_contents($this->directory . '/README.md', "---\ntitle: Index title\norder: 900\n---\n# Index body");
    file_put_contents($this->directory . '/10.md', "---\ntitle: First\ngroup: Sprint\norder: 1\ntags: alpha, beta，gamma\n---\n# Ten");
    file_put_contents($this->directory . '/2.md', "---\ngroup: Sprint\norder: 2\n---\n# Two");
    $records = app(PlansRepository::class)->all();
    expect(array_column($records, 'slug'))->toBe(['README.md', '10.md', '2.md', $this->slug]);
    expect($records[0]['title'])->toBe('Index title');
    expect($records[1]['tags'])->toBe(['alpha', 'beta', 'gamma']);
});

it('keeps release date precedence and applies order within the same date', function () {
    foreach (['2026-0908-new.md' => 99, '2026-0907-a.md' => 1, '2026-0907-z.md' => 2] as $slug => $order) {
        file_put_contents($this->directory . '/' . $slug, "---\ntitle: $slug\norder: $order\n---\n# Body");
    }
    expect(array_column(app(ReleaseRecordsRepository::class)->all(), 'slug'))
        ->toBe(['2026-0908-new.md', '2026-0907-a.md', '2026-0907-z.md', $this->slug]);
});

it('tolerates invalid YAML and metadata types without losing the raw file', function () {
    $parser = app(RecordMarkdownDocument::class);
    foreach (["---\ntitle: [\n---\n# Fallback", "---\nscalar\n---\n# Fallback", "---\ntitle: [bad]\ngroup: {bad: value}\norder: [bad]\ntags: [ok, {bad: value}]\n---\n# Fallback"] as $raw) {
        $parsed = $parser->parse($raw, 'test.md', 'Default');
        expect($parsed['title'])->toBe('Fallback')->and($parsed['group'])->toBe('Default')->and($parsed['order'])->toBe(999);
        expect($parsed['body'])->toBe('# Fallback');
    }
});

it('requires a valid CSRF token for saving', function ($route) {
    $this->withHeader('X-CSRF-TOKEN', 'invalid');
    $this->postJson('/scaffold/' . $route . '/save', ['slug' => $this->slug, 'content' => 'bad', 'version' => hash('sha256', $this->raw)])->assertStatus(419);
    expect(file_get_contents($this->directory . '/' . $this->slug))->toBe($this->raw);
})->with('record sections');

it('enforces the local write gate with a custom route prefix', function () {
    config(['scaffold.route.prefix' => 'dev-tools']);
    app()->instance('env', 'staging');
    foreach (['plans', 'release-records'] as $route) {
        $request = \Illuminate\Http\Request::create('/dev-tools/' . $route . '/save', 'POST');
        $request->headers->set('Accept', 'application/json');
        $response = app(EnforceScaffoldWritable::class)->handle($request, fn () => response('unexpected'));
        expect($response->getStatusCode())->toBe(403);
    }
});

it('uses actual group minima even when all orders exceed the default', function () {
    file_put_contents($this->directory . '/a.md', "---\ngroup: A\norder: 2000\n---\n# A");
    file_put_contents($this->directory . '/z.md', "---\ngroup: Z\norder: 1000\n---\n# Z");
    expect(array_column(app(PlansRepository::class)->all(), 'slug'))->toBe([$this->slug, 'z.md', 'a.md']);
});

it('reports invalid frontmatter in preview and rejects saving without changing the file', function ($route) {
    foreach (["---\ntitle: [\n---\n# Body", "---\norder: true\n---\n# Body", "---\ntitle: Incomplete\n# Body"] as $content) {
        $preview = $this->postJson('/scaffold/' . $route . '/preview', ['slug' => $this->slug, 'content' => $content])->assertOk();
        expect($preview->json('error'))->toContain('Frontmatter');
        $this->postJson('/scaffold/' . $route . '/save', ['slug' => $this->slug, 'content' => $content, 'version' => hash('sha256', $this->raw)])
            ->assertUnprocessable()->assertJsonValidationErrors('content');
        expect(file_get_contents($this->directory . '/' . $this->slug))->toBe($this->raw);
    }
})->with('record sections');

it('preserves BOM and empty frontmatter through a real save and hides the header when reading', function ($route, $scope, $query) {
    $content = "\xEF\xBB\xBF---\r\n---\r\n# Empty header\r\n";
    $this->postJson('/scaffold/' . $route . '/save', ['slug' => $this->slug, 'content' => $content, 'version' => hash('sha256', $this->raw)])->assertOk();
    expect(file_get_contents($this->directory . '/' . $this->slug))->toBe($content);
    $this->get('/scaffold/' . $route . '?' . http_build_query([$query => $this->slug]))->assertOk()->assertViewHas('html', fn ($html) => ! str_contains($html, '<hr'));
})->with('record sections');

it('hides editing for readable symlinks and unwritable files', function ($route, $scope, $query) {
    symlink($this->directory . '/' . $this->slug, $this->directory . '/alias.md');
    $this->get('/scaffold/' . $route . '?' . http_build_query([$query => 'alias.md']))
        ->assertOk()->assertSee('原文')->assertSee('只读')->assertDontSee('/' . $route . '/edit');
    expect(app(LocalMarkdownEditor::class)->canEdit($scope, 'alias.md'))->toBeFalse();
    $path = $this->directory . '/' . $this->slug;
    chmod($path, 0444);
    clearstatcache(true, $path);
    try {
        if (! is_writable($path)) {
            $this->get('/scaffold/' . $route . '?' . http_build_query([$query => $this->slug]))
                ->assertOk()->assertSee('只读')->assertDontSee('/' . $route . '/edit');
        }
    } finally {
        chmod($path, 0644);
    }
})->with('record sections');

it('acknowledges same-content retries with an old version without replacing the file again', function ($route) {
    $payload = ['slug' => $this->slug, 'content' => '# Saved', 'version' => hash('sha256', $this->raw)];
    $this->postJson('/scaffold/' . $route . '/save', $payload)->assertOk();
    $path = $this->directory . '/' . $this->slug;
    clearstatcache(true, $path);
    $before = stat($path);
    $this->postJson('/scaffold/' . $route . '/save', $payload)->assertOk()->assertJsonPath('version', hash('sha256', '# Saved'));
    clearstatcache(true, $path);
    $after = stat($path);
    expect($after['ino'])->toBe($before['ino'])->and($after['mtime'])->toBe($before['mtime']);
    $this->postJson('/scaffold/' . $route . '/save', array_replace($payload, ['content' => '# Different stale change']))->assertConflict();
    expect(file_get_contents($path))->toBe('# Saved');
})->with('record sections');

it('releases the file lock after a writer failure so a retry can succeed', function () {
    $files = Mockery::mock(Filesystem::class)->makePartial();
    $files->shouldReceive('replace')->once()->andThrow(new RuntimeException('simulated writer failure'));
    $editor = new LocalMarkdownEditor($files, app(RecordMarkdownDocument::class));
    expect(fn () => $editor->save('plans', $this->slug, '# Updated', hash('sha256', $this->raw)))
        ->toThrow(RuntimeException::class, 'simulated writer failure');
    expect(file_get_contents($this->directory . '/' . $this->slug))->toBe($this->raw);
    $handle = fopen($this->directory . '/' . $this->slug, 'r+');
    try {
        expect(flock($handle, LOCK_EX | LOCK_NB))->toBeTrue();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    expect(app(LocalMarkdownEditor::class)->save('plans', $this->slug, '# Updated', hash('sha256', $this->raw)))
        ->toBe(hash('sha256', '# Updated'));
});
