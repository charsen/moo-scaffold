<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Designer;

/**
 * 字段数值类型分组常量 —— 收口原本散落在 SchemaLoader / SchemaDiffService / MigrationWriter
 * 十余处的 `['int','bigint',...]` 字面(成员一致、order 偶异),消除"口径漂移"(ship-checklist #7)。
 *
 * 成员保持与收口前各处字面**完全一致**(in_array 与 order 无关),纯去重不改行为。
 * 关键:UNSIGNED_DEFAULT 故意比 NUMERIC 窄 —— 这层"分叉"原本隐在散落字面里难辨,这里命名 + 注释挑明。
 */
final class FieldTypes
{
    /** 整数类型(MySQL 整型族) */
    public const INT = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'];

    /** 浮点 / 定点类型 */
    public const FLOAT = ['decimal', 'float', 'double'];

    /** 全部数值类型(INT + FLOAT)—— "是否数值"判定:unsigned 是否允许 / precision 门控等 */
    public const NUMERIC = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'float', 'double'];

    /**
     * codegen(FreshStorageGenerator::getSize)自动补 unsigned 默认值的类型。
     * 故意比 NUMERIC 窄(不含 smallint / mediumint / double):仅常见无符号场景默认开 unsigned,
     * 其余 numeric 类型 unsigned 仍可在 designer 手动勾选,只是不自动 default。
     */
    public const UNSIGNED_DEFAULT = ['tinyint', 'int', 'bigint', 'decimal', 'float'];
}
