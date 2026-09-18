<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use Illuminate\Filesystem\Filesystem;

/**
 * 只读 Markdown 记录目录的公共骨架（模板方法）。
 *
 * `PlansRepository` 与 `ReleaseRecordsRepository` 此前是逐行同构的复制体：同一份配置目录
 * 解析、同一套 `allFiles()` 遍历、同一条过滤规则（只收 `.md`、不收目录外软链目标、不收
 * 点 / 下划线前缀段），差别只在「记录字段」与「排序口径」。2026-09-18 把骨架收在这里，
 * 子类退化成三个声明；公共的目录边界口径见 {@see RecordScope}。
 *
 * 约定：配置目录是唯一读取边界，不跟随目录外的软链（见 {@see RecordScope::contains()}）。
 */
abstract class MarkdownFileRepository
{
    public function __construct(protected readonly Filesystem $files, protected readonly RecordMarkdownDocument $markdown) {}

    /**
     * 该范围目录下的全部记录，已按子类规则排序。
     *
     * 每条记录的字段由子类 {@see self::makeRecord()} 决定；目录未配置 / 不存在 / 不是目录
     * 时返回空数组（不创建目录）。
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $base = RecordScope::directory($this->scope());
        if ($base === null) {
            return [];
        }

        $records = [];
        foreach ($this->files->allFiles($base) as $file) {
            $real = $file->getRealPath();
            $slug = str_replace('\\', '/', $file->getRelativePathname());
            if (strtolower($file->getExtension()) !== 'md'
                || $real === false || ! RecordScope::contains($base, $real)
                || preg_match('~(^|/)[._]~', $slug)) {
                continue;
            }
            $records[] = $this->makeRecord($slug, $this->files->get($real));
        }

        return $this->sortRecords($records);
    }

    /** 本仓库的记录范围，须是 {@see RecordScope::SCOPES} 之一（决定读哪个配置项）。 */
    abstract protected function scope(): string;

    /**
     * 由扫描到的文件构造一条记录。
     *
     * 入参只有相对 slug 与原文，**没有**文件路径 / 修改时间 —— 记录字段只能来自
     * slug 与 frontmatter，不允许用文件系统元数据倒推（如发版日期）。
     *
     * @return array<string,mixed>
     */
    abstract protected function makeRecord(string $slug, string $raw): array;

    /**
     * 排序口径，返回排好序的新数组（不改入参）。
     *
     * @param list<array<string,mixed>> $records
     *
     * @return list<array<string,mixed>>
     */
    abstract protected function sortRecords(array $records): array;
}
