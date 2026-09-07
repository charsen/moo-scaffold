<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use Illuminate\Filesystem\Filesystem;

/** 只读发版记录；配置目录是唯一读取边界，不跟随目录外的软链。 */
final class ReleaseRecordsRepository
{
    public function __construct(private readonly Filesystem $files) {}

    /** @return list<array{slug:string,title:string,date:string,body:string}> */
    public function all(): array
    {
        $path = (string) config('scaffold.release_records.path');
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
            $date = '';
            if (preg_match('/^(\d{4})-(\d{2})-?(\d{2})(?:\D|$)/', $slug, $parts)
                && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                $date = $parts[1] . '-' . $parts[2] . '-' . $parts[3];
            }
            $records[] = compact('slug', 'title', 'date', 'body');
        }
        // 同日以路径稳定倒序，不能把文件修改时间当成发布日期。
        usort($records, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']) ?: strnatcasecmp($b['slug'], $a['slug']));

        return $records;
    }
}
