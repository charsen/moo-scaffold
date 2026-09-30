<?php

declare(strict_types=1);

it('当前安装与升级文档不再引用已删除的 provider', function () {
    $root     = dirname(__DIR__, 3);
    $files    = [$root . '/README.md'];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/docs', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'md') {
            $files[] = $file->getPathname();
        }
    }
    foreach ($files as $file) {
        expect(file_get_contents($file))->not->toContain('Scaffold' . 'Provider', $file);
    }
});
