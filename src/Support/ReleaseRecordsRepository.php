<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

/**
 * 只读发版记录；配置目录（`scaffold.release_records.path`）是唯一读取边界，不跟随目录外的软链。
 *
 * 扫描 / 过滤骨架见 {@see MarkdownFileRepository}，本类只声明范围、字段与排序口径。
 */
final class ReleaseRecordsRepository extends MarkdownFileRepository
{
    protected function scope(): string
    {
        return 'release_records';
    }

    /** 日期只从 slug 的 `YYYY-MMDD` 前缀认（不认 frontmatter、也不看修改时间）；没认出来即「未标注日期」。 */
    protected function makeRecord(string $slug, string $raw): array
    {
        $date = '';
        if (preg_match('/^(\d{4})-(\d{2})-?(\d{2})(?:\D|$)/', $slug, $parts)
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            $date = $parts[1] . '-' . $parts[2] . '-' . $parts[3];
        }

        return compact('slug', 'date') + $this->markdown->parse($raw, $slug, $date ?: '未标注日期');
    }

    /**
     * 日期倒序，同日先按 frontmatter order，再以路径稳定倒序；不以修改时间推断发布日期。
     *
     * @param list<array{slug:string,title:string,date:string,group:string,order:int,tags:list<string>,body:string,error:?string}> $records
     *
     * @return list<array{slug:string,title:string,date:string,group:string,order:int,tags:list<string>,body:string,error:?string}>
     */
    protected function sortRecords(array $records): array
    {
        usort($records, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']) ?: ($a['order'] <=> $b['order']) ?: strnatcasecmp($b['slug'], $a['slug']));

        return $records;
    }
}
