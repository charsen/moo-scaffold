<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

/**
 * 只读研发计划；配置目录（`scaffold.plans.path`）是唯一读取边界，不跟随目录外的软链。
 *
 * 扫描 / 过滤骨架见 {@see MarkdownFileRepository}，本类只声明范围、字段与排序口径。
 */
final class PlansRepository extends MarkdownFileRepository
{
    protected function scope(): string
    {
        return 'plans';
    }

    /** 嵌套目录即分组；根目录的文件归「根目录」。 */
    protected function makeRecord(string $slug, string $raw): array
    {
        $group = dirname($slug) === '.' ? '根目录' : dirname($slug);

        return ['slug' => $slug] + $this->markdown->parse($raw, $slug, $group);
    }

    /**
     * README 最前；其余按「分组序（组内最小 order）→ 组名 → order → slug 自然序」。
     *
     * @param list<array{slug:string,title:string,group:string,order:int,tags:list<string>,body:string,error:?string}> $records
     *
     * @return list<array{slug:string,title:string,group:string,order:int,tags:list<string>,body:string,error:?string}>
     */
    protected function sortRecords(array $records): array
    {
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
