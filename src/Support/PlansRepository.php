<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use Illuminate\Filesystem\Filesystem;

/** 只读研发计划；配置目录是唯一读取边界，不跟随目录外的软链。 */
final class PlansRepository
{
    public function __construct(private readonly Filesystem $files) {}

    /** @return list<array{slug:string,title:string,group:string,body:string}> */
    public function all(): array
    {
        $path = (string) config('scaffold.plans.path', 'plans');
        if ($path === '') {
            return [];
        }
        $base = realpath(str_starts_with($path, '/') ? $path : base_path($path));
        if ($base === false || ! is_dir($base)) {
            return [];
        }

        $records = [];
        foreach ($this->files->allFiles($base) as $file) {
            $real = $file->getRealPath();
            $slug = str_replace('\\', '/', $file->getRelativePathname());
            if (strtolower($file->getExtension()) !== 'md'
                || $real === false || ! str_starts_with($real, $base . DIRECTORY_SEPARATOR)
                || preg_match('~(^|/)[._]~', $slug)) {
                continue;
            }
            $body = $this->files->get($real);
            // 历史记录可能带编辑器 HTML 注释；阅读时隐藏，文件保持原样。
            $body  = preg_replace('/<!--.*?-->/s', '', $body) ?? $body;
            $title = preg_match('/^#[ \t]+(.+?)[ \t]*#*[ \t]*$/m', $body, $heading)
                ? trim($heading[1]) : $slug;
            $group     = dirname($slug) === '.' ? '根目录' : dirname($slug);
            $records[] = compact('slug', 'title', 'group', 'body');
        }
        usort($records, static function (array $a, array $b): int {
            if ($a['slug'] === 'README.md' || $b['slug'] === 'README.md') {
                return $a['slug'] === 'README.md' ? -1 : 1;
            }
            $aDir = dirname($a['slug']);
            $bDir = dirname($b['slug']);

            return strnatcasecmp($aDir, $bDir) ?: strnatcasecmp(basename($a['slug']), basename($b['slug']));
        });

        return $records;
    }
}
