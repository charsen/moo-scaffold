<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Support\DocsRepository;
use Mooeen\Scaffold\Utility;

beforeEach(function () {
    $this->directory = sys_get_temp_dir() . '/docs_atomic_' . uniqid();
    mkdir($this->directory . '/docs', 0755, true);
    $this->originalBase = base_path();
    app()->setBasePath($this->directory);
    app()->instance('env', 'local');
    config(['scaffold.docs.path' => 'docs', 'scaffold.config_ui.readonly' => false]);
    $this->repository = new DocsRepository(new Filesystem, new Utility);
});

afterEach(function () {
    app()->setBasePath($this->originalBase);
    (new Filesystem)->deleteDirectory($this->directory);
});

it('save and reorder leave existing readers on the complete old document', function (string $action) {
    $path = $this->directory . '/docs/entry.md';
    $old  = "---\ntitle: 原文\norder: 90\n---\n\n完整旧正文\n";
    file_put_contents($path, $old);
    chmod($path, 0640);
    $reader = fopen($path, 'r');

    try {
        if ($action === 'save') {
            $this->repository->save('entry', "完整新正文\n");
        } else {
            $this->repository->reorder(['entry']);
        }

        expect(stream_get_contents($reader))->toBe($old);
        expect(file_get_contents($path))->toContain($action === 'save' ? '完整新正文' : 'order: 10');
        clearstatcache(true, $path);
        expect(fileperms($path) & 07777)->toBe(0640);
        expect(glob($path . '.tmp.*'))->toBe([]);
    } finally {
        fclose($reader);
    }
})->with(['save', 'reorder']);

it('saving an internal symlink updates its target and preserves the link and permissions', function () {
    $target = $this->directory . '/docs/target.md';
    $link   = $this->directory . '/docs/alias.md';
    file_put_contents($target, "原文\n");
    chmod($target, 0600);
    symlink('target.md', $link);
    $reader = fopen($target, 'r');

    try {
        $this->repository->save('alias', "新正文\n");
        expect(is_link($link))->toBeTrue();
        expect(stream_get_contents($reader))->toBe("原文\n");
        expect(file_get_contents($target))->toBe("新正文\n");
        clearstatcache(true, $target);
        expect(fileperms($target) & 07777)->toBe(0600);
    } finally {
        fclose($reader);
    }
});

it('an atomic save failure preserves the document and reports the existing error message', function () {
    $path = $this->directory . '/docs/entry.md';
    file_put_contents($path, "原文\n");
    chmod(dirname($path), 0555);
    clearstatcache();

    try {
        expect(fn () => $this->repository->save('entry', "新正文\n"))
            ->toThrow(RuntimeException::class, '写入失败，文档未变更：entry');
        expect(file_get_contents($path))->toBe("原文\n");
        expect(glob($path . '.tmp.*'))->toBe([]);
    } finally {
        chmod(dirname($path), 0755);
    }
})->skip(fn () => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root 不受目录权限约束');

it('a later reorder failure preserves that document and exposes earlier successful changes', function () {
    $old = "---\norder: 90\n---\n\n原文\n";
    foreach (['a', 'b'] as $slug) {
        file_put_contents($this->directory . '/docs/' . $slug . '.md', $old);
    }
    $repository = new class(new Filesystem, new Utility) extends DocsRepository
    {
        private int $writes = 0;

        protected function writeFileAtomically(string $path, string $content, ?int $mode = null): void
        {
            if (++$this->writes === 2) {
                throw new RuntimeException('模拟第二篇写入失败');
            }
            parent::writeFileAtomically($path, $content, $mode);
        }
    };

    $repository->all();
    expect(fn () => $repository->reorder(['a', 'b']))
        ->toThrow(RuntimeException::class, 'b 未能编号，已改 1 篇');
    expect(file_get_contents($this->directory . '/docs/b.md'))->toBe($old);
    expect(array_column($repository->all(), 'order', 'slug'))->toBe(['a' => 10, 'b' => 90]);
});

it('a read-only document cannot be replaced through a writable directory', function () {
    $path = $this->directory . '/docs/entry.md';
    file_put_contents($path, "原文\n");
    chmod($path, 0400);
    clearstatcache();
    expect(fn () => $this->repository->save('entry', "新正文\n"))
        ->toThrow(RuntimeException::class, '写入失败，文档未变更');
    expect(file_get_contents($path))->toBe("原文\n");
})->skip(fn () => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root 不受文件权限约束');
