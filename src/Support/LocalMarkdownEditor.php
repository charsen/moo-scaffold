<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** 编辑既有计划和发版记录，配置目录是唯一文件边界。 */
final class LocalMarkdownEditor
{
    public function __construct(private readonly Filesystem $files, private readonly RecordMarkdownDocument $markdown) {}

    public function writable(): bool
    {
        return app()->environment('local') && ! config('scaffold.config_ui.readonly', false);
    }

    public function assertWritable(): void
    {
        abort_unless($this->writable(), 403, '仅本地且未开启强制只读时允许编辑。');
    }

    public function canEdit(string $scope, string $slug): bool
    {
        if (! $this->writable()) {
            return false;
        }
        try {
            $path = $this->path($scope, $slug);

            return is_readable($path) && is_writable($path) && is_writable(dirname($path));
        } catch (HttpException $e) {
            if ($e->getStatusCode() !== 404) {
                throw $e;
            }

            return false;
        }
    }

    public function read(string $scope, string $slug): string
    {
        $this->assertWritable();

        return $this->files->get($this->path($scope, $slug), true);
    }

    public function save(string $scope, string $slug, string $content, string $version): string
    {
        $this->assertWritable();
        $path = $this->path($scope, $slug);
        abort_unless(is_writable(dirname($path)), 422, '文件所在目录不可写。');
        $this->markdown->assertValid($content);
        $file = @fopen($path, 'r+');
        abort_if($file === false, 422, '文件不可写。');

        try {
            abort_unless(flock($file, LOCK_EX), 422, '无法锁定文件，请重试。');
            clearstatcache(true, $path);
            // 其他保存可能已原子替换文件；旧句柄不能覆盖新的文件版本。
            $current = stat($this->path($scope, $slug));
            $opened  = fstat($file);
            abort_if($current['ino'] !== $opened['ino'] || $current['dev'] !== $opened['dev'], 409, '文件已被修改，请重新打开后再编辑。');
            $raw = stream_get_contents($file);
            abort_if($raw === false, 422, '无法读取文件。');
            // 响应丢失后的同内容重试直接确认成功，也避免无修改保存改变 inode / mtime。
            if ($raw === $content) {
                return hash('sha256', $raw);
            }
            abort_unless(hash_equals(hash('sha256', $raw), $version), 409, '文件已被修改，请重新打开后再编辑。');
            // replace 先写临时文件再 rename，避免写入失败留下半份 Markdown。
            $this->files->replace($path, $content, $opened['mode'] & 0777);
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }

        return hash('sha256', $content);
    }

    private function path(string $scope, string $slug): string
    {
        abort_unless(in_array($scope, ['plans', 'release_records'], true), 404);
        abort_if($slug === '' || str_starts_with($slug, '/')
                              || preg_match('/[\\\\\x00-\x1f\x7f]/', $slug)
                              || preg_match('~(^|/)[._]|//~', $slug)
                              || strtolower(pathinfo($slug, PATHINFO_EXTENSION)) !== 'md', 404);

        $configured = (string) config('scaffold.' . $scope . '.path');
        abort_if($configured === '', 404);
        $base = realpath(str_starts_with($configured, '/') ? $configured : base_path($configured));
        abort_if($base === false || ! is_dir($base), 404);
        $path = $base;
        foreach (explode('/', $slug) as $segment) {
            $path .= DIRECTORY_SEPARATOR . $segment;
            abort_if(is_link($path), 404);
        }
        $real = realpath($path);
        abort_if($real === false || ! is_file($real) || ! str_starts_with($real, $base . DIRECTORY_SEPARATOR), 404);

        return $real;
    }
}
