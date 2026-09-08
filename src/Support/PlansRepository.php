<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use Illuminate\Filesystem\Filesystem;

/** 只读研发计划；配置目录是唯一读取边界，不跟随目录外的软链。 */
final class PlansRepository
{
    public function __construct(private readonly Filesystem $files, private readonly RecordMarkdownDocument $markdown) {}

    /** @return list<array{slug:string,title:string,group:string,order:int,tags:list<string>,body:string}> */
    public function all(): array
    {
        $path = (string) config('scaffold.plans.path');
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
            $group     = dirname($slug) === '.' ? '根目录' : dirname($slug);
            $records[] = ['slug' => $slug] + $this->markdown->parse($this->files->get($real), $slug, $group);
        }
        $groupOrder = [];
        foreach ($records as $record) {
            $groupOrder[$record['group']] = min($groupOrder[$record['group']] ?? $record['order'], $record['order']);
        }
        usort($records, static function (array $a, array $b) use ($groupOrder): int {
            if ($a['slug'] === 'README.md' || $b['slug'] === 'README.md') {
                return $a['slug'] === 'README.md' ? -1 : 1;
            }

            return ($groupOrder[$a['group']] <=> $groupOrder[$b['group']])
                ?: strnatcasecmp($a['group'] === '根目录' ? '.' : $a['group'], $b['group'] === '根目录' ? '.' : $b['group'])
                ?: ($a['order'] <=> $b['order'])
                ?: strnatcasecmp($a['slug'], $b['slug']);
        });

        return $records;
    }
}
