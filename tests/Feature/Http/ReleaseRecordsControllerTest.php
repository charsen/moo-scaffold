<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Http\Middleware\ScaffoldAuthenticate;
use Mooeen\Scaffold\Support\ReleaseRecordsRepository;

beforeEach(function () {
    $this->directory = sys_get_temp_dir() . '/scaffold_releases_' . uniqid();
    mkdir($this->directory);
    config(['scaffold.release_records.path' => $this->directory]);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->directory);
});

function writeReleaseRecord(string $root, string $path, string $body): void
{
    (new Filesystem)->ensureDirectoryExists(dirname($root . '/' . $path));
    file_put_contents($root . '/' . $path, $body);
}

it('sorts by directory date and preserves separate same-day records', function () {
    writeReleaseRecord($this->directory, '2026-0907-b/tag.md', "# v2.0.3 发版记录\n\nNew");
    writeReleaseRecord($this->directory, '2026-0907-a/tag.md', '# v2.0.2');
    writeReleaseRecord($this->directory, '2026-0730-a/tag.md', '# v2.0.1');
    writeReleaseRecord($this->directory, 'undated.md', 'No heading');
    touch($this->directory . '/2026-0730-a/tag.md', time() + 100);
    $records = app(ReleaseRecordsRepository::class)->all();
    expect(array_column($records, 'title'))->toBe(['v2.0.3 发版记录', 'v2.0.2', 'v2.0.1', 'undated.md']);
    expect($records[0]['date'])->toBe('2026-09-07');
});

it('renders the newest record and deep links without editing controls', function () {
    $this->withoutMiddleware(ScaffoldAuthenticate::class);
    writeReleaseRecord($this->directory, '2026-0907-test/tag.md', "# Release\n\n<!-- private editor metadata -->\n\n<script>alert(1)</script>\n\n| A | B |\n| --- | --- |\n| 1 | 2 |");
    $this->get('/scaffold/release-records')->assertOk()->assertSee('发版日志')
        ->assertSee('<table>', false)->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('private editor metadata')->assertDontSee('docs/edit');
    config(['scaffold.config_ui.readonly' => true]);
    $this->get('/scaffold/release-records?record=2026-0907-test%2Ftag.md')->assertOk()->assertSee('Release');
    $this->post('/scaffold/release-records')->assertNotFound();
});

it('rejects arbitrary paths, external symlinks, and invalid input', function () {
    $this->withoutMiddleware(ScaffoldAuthenticate::class);
    writeReleaseRecord($this->directory, 'outside.md', '# Outside secret');
    mkdir($this->directory . '/records');
    symlink($this->directory . '/outside.md', $this->directory . '/records/leak.md');
    config(['scaffold.release_records.path' => $this->directory . '/records']);
    expect(app(ReleaseRecordsRepository::class)->all())->toBe([]);
    foreach (['../outside.md', 'leak.md', '/etc/passwd', 'missing.md'] as $slug) {
        $this->get('/scaffold/release-records?' . http_build_query(['record' => $slug]))->assertNotFound();
    }
    $this->getJson('/scaffold/release-records?record[]=x')->assertUnprocessable();
});

it('handles a missing directory without creating it', function () {
    $this->withoutMiddleware(ScaffoldAuthenticate::class);
    config(['scaffold.release_records.path' => $this->directory . '/missing']);
    $this->get('/scaffold/release-records')->assertOk()->assertSee('暂无发版日志');
    expect(is_dir($this->directory . '/missing'))->toBeFalse();
});

it('requires scaffold authentication', function () {
    config(['scaffold.auth.enabled' => true]);
    $this->getJson('/scaffold/release-records')->assertUnauthorized();
});

it('resolves a relative directory outside the Laravel engine root and ignores non-record files', function () {
    $original = base_path();
    mkdir($this->directory . '/engine');
    writeReleaseRecord($this->directory, 'records/2026-09-07-test/tag.md', '# Relative release');
    writeReleaseRecord($this->directory, 'records/.hidden.md', '# Hidden');
    writeReleaseRecord($this->directory, 'records/file.txt', 'Ignored');
    app()->setBasePath($this->directory . '/engine');
    config(['scaffold.release_records.path' => '../records']);
    try {
        $records = app(ReleaseRecordsRepository::class)->all();
        expect(array_column($records, 'title'))->toBe(['Relative release']);
        expect($records[0]['date'])->toBe('2026-09-07');
    } finally {
        app()->setBasePath($original);
    }
});
