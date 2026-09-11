<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

/**
 * 字段类型分组常量 —— 字段类型字面（`['int', 'bigint', ...]`）的单一口径。
 *
 * 第一阶段（ship-checklist #7）只在 Designer 侧收口了**数值**分组：
 * SchemaLoader / SchemaDiffService / MigrationWriter 十余处字面统一到本类。
 * 第二阶段（2026-09-11）补齐 **codegen 侧**（Generator）此前仍在各文件内联的分组，
 * 并把本类从 `Designer\` 挪到 `Support\` —— 它同时被 Designer 与 Generator 消费，
 * 留在 Designer 下会让 `Generator` 反向依赖 `Designer`。挪动前已确认无仓外消费者。
 *
 * 为什么值得收口：本仓已**复发过两次**同型事故 —— 有人写了更窄的 inline 列表，整类列
 * 静默丢掉校验/生成（见 `CreateControllerGenerator` 里 2026-06-11 的两条修复注释：
 * 漏 smallint/mediumint/decimal/float/double → Request 数值列零校验；漏 text 系列 →
 * Request 文本字段缺 `'string'`）。成员只有一处定义，才谈得上"改一处不漏四处"。
 *
 * 约定：成员保持与收口前各处字面**完全一致**（`in_array` 与 order 无关），纯去重不改行为；
 * 确实该更窄的分组另立常量并在注释里挑明"故意窄"，不要让差异隐在散落字面里。
 */
final class FieldTypes
{
    /** 整数类型(MySQL 整型族) */
    public const INT = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'];

    /**
     * 整数族但**不含 bigint**。
     *
     * 给"bigint 需要独立映射"的场景用：如 TS 模型里 bigint → `bigint | string`（JS number
     * 装不下 64 位），其余整型 → `number`。所以那里不是漏写 bigint，是刻意拆开。
     */
    public const INT_NO_BIGINT = ['tinyint', 'smallint', 'mediumint', 'int'];

    /** 浮点 / 定点类型 */
    public const FLOAT = ['decimal', 'float', 'double'];

    /** 全部数值类型(INT + FLOAT)—— "是否数值"判定:unsigned 是否允许 / precision 门控等 */
    public const NUMERIC = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double'];

    /** 布尔类型 */
    public const BOOL = ['bool', 'boolean'];

    /** 字符串族(char / varchar + text 全系列)—— "是否字符串"判定、LIKE 搜索 scope 等 */
    public const STRING = ['varchar', 'char', 'tinytext', 'text', 'mediumtext', 'longtext'];

    /** 带 size / min_size 语义的字符串(仅 char / varchar;text 系列无 size) */
    public const STRING_SIZE = ['varchar', 'char'];

    /**
     * 大文本(列表查询排除用)。
     * **故意不含 tinytext** —— tinytext 够短，留在列表查询里无妨。
     */
    public const TEXT_LARGE = ['text', 'mediumtext', 'longtext'];

    /**
     * 日期时间类型（codegen 侧的 date 校验 / Carbon 转换 / whereDate scope）。
     *
     * **故意不含 `time`**：designer 把 `time` 列为可选类型（`DesignerController` 的
     * `designer_type_options`），但 `time` 没有 date / Carbon 语义 —— 给它加 `date` 校验或
     * `Carbon|null` 转换都是错的。所以本仓现状是"designer 可建、codegen 不特殊处理"，落到
     * 生成器末尾的 string 兜底。要改这个现状得先定时间列在生成产物里该长什么样，属于语义决策，
     * 不在类型收口范围内。
     */
    public const DATE = ['date', 'datetime', 'timestamp'];

    /**
     * 仅"时刻"型(不含纯 date):只该响应 datetime / timestamp 的场景,如
     * `updated_at` 判定、时间戳精度处理。
     */
    public const DATETIME = ['datetime', 'timestamp'];

    /**
     * codegen(FreshStorageGenerator::getSize)自动补 unsigned 默认值的类型。
     * 故意比 NUMERIC 窄(不含 smallint / mediumint / double):仅常见无符号场景默认开 unsigned,
     * 其余 numeric 类型 unsigned 仍可在 designer 手动勾选,只是不自动 default。
     */
    public const UNSIGNED_DEFAULT = ['tinyint', 'int', 'bigint', 'decimal', 'float'];
}
