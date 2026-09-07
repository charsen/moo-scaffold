<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Http\Middleware\ScaffoldAuthenticate;

it('reads both document directories from the repository root even when engine copies exist', function () {
    $original = base_path();
    app()->getNamespace();
    $root  = sys_get_temp_dir() . '/scaffold_root_docs_' . uniqid();
    $files = new Filesystem;
    foreach (['plans', 'release-records', 'engine/plans', 'engine/release-records'] as $directory) {
        $files->ensureDirectoryExists($root . '/' . $directory);
        file_put_contents($root . '/' . $directory . '/README.md', str_starts_with($directory, 'engine/') ? '# Stale engine copy' : '# Repository document');
    }
    $this->withoutMiddleware(ScaffoldAuthenticate::class);
    try {
        app()->setBasePath($root . '/engine');
        $defaults = require dirname(__DIR__, 3) . '/config/config.php';
        foreach (['plans' => 'plans', 'release_records' => 'release-records'] as $key => $directory) {
            expect($defaults[$key]['path'])->toBe('../' . $directory);
            config(['scaffold.' . $key => $defaults[$key]]);
            $this->get('/scaffold/' . $directory)->assertOk()
                ->assertSee('Repository document')->assertDontSee('Stale engine copy');
            // 根目录记录缺失时也不能回退到 engine 中的旧副本。
            $files->deleteDirectory($root . '/' . $directory);
            $this->get('/scaffold/' . $directory)->assertOk()->assertViewHas('total', 0)
                ->assertDontSee('Stale engine copy');
        }
    } finally {
        app()->setBasePath($original);
        $files->deleteDirectory($root);
    }
});
