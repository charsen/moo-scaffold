<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Designer;

use Symfony\Component\Yaml\Yaml;

/**
 * scaffold 共享的 yaml dump + 注释保留 helper(plan 19 v3 §E)。
 *
 * 所有写 yaml 的路径(SchemaLoader::saveModule / Designer createTable / SnapshotStore)走同一个 dumper,
 * 保证 GUI 操作落盘风格永远跟手写约定一致,杜绝"GUI save 破坏 yaml"的情况。
 *
 * 使用:
 *   $normalized = YamlFormatter::dump($data);
 *   $final      = YamlFormatter::reattachComments($originalText, $normalized);
 *
 * 或一步走:
 *   $final = YamlFormatter::dumpPreservingComments($data, $originalText);
 */
final class YamlFormatter
{
    /**
     * inline=4 对齐 scaffold yaml 嵌套深度,让 field attrs 落进 inline-flow,
     * 避免 inline=6+ 把 fields 展开成 5 行 block 导致 50% 膨胀
     */
    public const DUMP_INLINE = 4;

    public const DUMP_INDENT = 4;

    public const DUMP_FLAGS = Yaml::DUMP_OBJECT_AS_MAP;

    /**
     * 表级 key canonical 顺序(2026-05-23 plan-49 后续 polish:跟 System.yaml 老样本对齐)
     * model 在前(读 yaml 第一眼知道类名)→ controller → attrs(元数据)→ index(读字段前先看索引)
     * → fields(主体)→ enums(末尾,字段语义补充)
     */
    private const TABLE_KEY_ORDER = ['model', 'controller', 'attrs', 'index', 'fields', 'enums'];

    /**
     * attrs sub-key canonical 顺序:中文名 + 描述靠前,prefix 中段,审计 timestamp 末尾
     */
    private const ATTRS_KEY_ORDER = ['name', 'desc', 'remark', 'prefix', 'created_by', 'created_at', 'updated_by', 'updated_at'];

    /**
     * 纯 Yaml::dump,但先归一表内 key 顺序 + dump 后插入 tables 间空行。
     * 单点改动 → 所有写盘路径(saveModule / createTable / SnapshotStore)自动统一格式。
     */
    public static function dump(array $data): string
    {
        $canonical = self::canonicalizeShape($data);
        $yaml      = Yaml::dump($canonical, self::DUMP_INLINE, self::DUMP_INDENT, self::DUMP_FLAGS);

        return self::insertBlankLinesBetweenTables($yaml);
    }

    /**
     * 把 tables 内每张表的 key 归一到 canonical 顺序,attrs sub-key 同样归一。
     * 未知 key 落到 canonical 之后,保留原相对顺序(不丢)。
     */
    private static function canonicalizeShape(array $data): array
    {
        if (! isset($data['tables']) || ! is_array($data['tables'])) {
            return $data;
        }
        foreach ($data['tables'] as $tk => &$t) {
            if (! is_array($t)) {
                continue;
            }
            if (isset($t['attrs']) && is_array($t['attrs'])) {
                $t['attrs'] = self::sortByKey($t['attrs'], self::ATTRS_KEY_ORDER);
            }
            $t = self::sortByKey($t, self::TABLE_KEY_ORDER);
        }
        unset($t);

        return $data;
    }

    /**
     * 按指定顺序排 array 的 key,未列出的 key 落到 known list 之后,相对顺序不变。
     */
    private static function sortByKey(array $arr, array $order): array
    {
        $out = [];
        foreach ($order as $k) {
            if (array_key_exists($k, $arr)) {
                $out[$k] = $arr[$k];
                unset($arr[$k]);
            }
        }
        foreach ($arr as $k => $v) {
            $out[$k] = $v;
        }

        return $out;
    }

    /**
     * 在 `tables:` 下每张相邻表之间插一行空行(跟 System.yaml 老样本对齐)。
     * 检测规则:层级 4-space indent 的 key 行(`^    [a-z_][a-z0-9_]*:`)且上一行非空 → 上方插空行。
     * 第一张表不插(紧贴 `tables:`)。
     */
    private static function insertBlankLinesBetweenTables(string $yaml): string
    {
        $lines          = explode("\n", $yaml);
        $out            = [];
        $insideTables   = false;
        $tableKeyRe     = '/^    [a-z_][a-z0-9_]*:\s*$/';
        $tablesHeaderRe = '/^tables:\s*$/';

        foreach ($lines as $i => $line) {
            if (preg_match($tablesHeaderRe, $line)) {
                $insideTables = true;
                $out[]        = $line;

                continue;
            }
            // tables 块结束:遇到顶层 key(非空非缩进 + 冒号)
            if ($insideTables && preg_match('/^[a-z_][a-z0-9_]*:/', $line)) {
                $insideTables = false;
            }
            if ($insideTables && preg_match($tableKeyRe, $line)) {
                $prev = end($out);
                if ($prev !== false && trim($prev) !== '') {
                    // 上一行非空且不是 `tables:` 标题 → 加空行
                    if (! preg_match($tablesHeaderRe, $prev)) {
                        $out[] = '';
                    }
                }
            }
            $out[] = $line;
        }

        return implode("\n", $out);
    }

    /**
     * dump + 把 $original 中的 # 注释挂回。$original 为空时等价于 dump()。
     */
    public static function dumpPreservingComments(array $data, string $original): string
    {
        $normalized = self::dump($data);
        if ($original === '') {
            return $normalized;
        }

        return self::reattachComments($original, $normalized);
    }

    /**
     * 把原文中的 # 注释挂回 normalized yaml:
     *   - A 类(头部连续 # 块):整段保留,粘到 normalized 开头
     *   - B 类(段间前置注释):用"紧接的下一行 yaml 内容"作 anchor,在 normalized 里找到同样行,插在前面
     *   - C 类(行内 `x: y # 注释`):Yaml::parse 已解析丢失,无法恢复
     *
     * 已知边界:anchor 是 yaml 行 text(rtrim 后),如果同一行内容在 yaml 里出现多次(如多张表的孤立 `id: {  }`),
     * 只能挂回第一次出现的位置——极罕见的边界 case。
     */
    public static function reattachComments(string $src, string $normalized): string
    {
        $srcLines = explode("\n", $src);
        $header   = [];                // 头部块
        $pending  = [];                // 累积中的注释行
        $attached = [];                // anchor_text => [comment_lines]
        $inHeader = true;

        foreach ($srcLines as $line) {
            $rtrimmed  = rtrim($line);
            $isComment = (bool) preg_match('/^\s*#/', $rtrimmed);
            $isBlank   = ($rtrimmed === '');

            if ($isComment) {
                $pending[] = $rtrimmed;

                continue;
            }
            if ($isBlank) {
                if (! empty($pending)) {
                    $pending[] = '';
                }

                continue;
            }

            if ($inHeader) {
                $header   = $pending;
                $inHeader = false;
            } elseif (! empty($pending)) {
                $anchor = $rtrimmed;
                if (! isset($attached[$anchor])) {
                    $attached[$anchor] = $pending;
                }
            }
            $pending = [];
        }

        $normLines = explode("\n", $normalized);
        $out       = [];

        foreach ($header as $h) {
            $out[] = $h;
        }

        foreach ($normLines as $line) {
            $rtrimmed = rtrim($line);
            if (isset($attached[$rtrimmed])) {
                foreach ($attached[$rtrimmed] as $c) {
                    $out[] = $c;
                }
                unset($attached[$rtrimmed]);
            }
            $out[] = $line;
        }

        return implode("\n", $out);
    }
}
