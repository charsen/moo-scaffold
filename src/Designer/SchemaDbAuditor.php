<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Designer;

use Illuminate\Support\Facades\DB;

/**
 * yaml ↔ 活 DB 的只读对账(plan-36 baseline 的补强)。
 *
 * 背景:`moo:snapshot:init` 的前置假设是「当前 yaml 跟 migrations + DB 一致」,
 * `SnapshotStore::capture()` 只 verbatim 拷 yaml 当 baseline,**不反查 DB**。
 * 一旦 yaml 先漂了(典型:旧 designer round-trip 把 field `unique` sugar promote 进
 * index 块、或字段 type/size 手改没回灌 migration),snapshot:init 会把漂移**静默洗成基线**,
 * 之后 designer 的 yaml↔snapshot diff 两边一样错 → 报 clean,漂移被永久掩盖。
 * (2026-05-30 宿主项目 platform_residences.residence_name unique / property_type
 *  varchar↔longtext 漂移即此根因。)
 *
 * 本服务在 init 落基线前反查 `information_schema`,把 yaml 声明跟实际 DB 列/索引对一遍,
 * 高信号、低误报地报出三类不符。**只读、不改任何东西**——不属于被劝退的
 * "快照历史 / 操作审计 / 多步撤销" 那类复杂度,只是一道让漂移**不再静默**的校验。
 *
 * 范围(刻意收窄,避免噪音):
 *   - 列类型族不符(varchar↔longtext / int↔varchar …)
 *   - varchar / char 的 DB 长度不符(size 取 'm,n' 解析后的 max)
 *   - 单列 DB-level unique 索引不符(index 块 type:unique vs DB NON_UNIQUE)
 * 不查(低信号 / 易误报):nullable / unsigned / default / 多列索引 / DB 多出的列 /
 *   field 级 app-level `unique`(plan-51 语义:软唯一校验,本就不进 DB)。
 *
 * MySQL 专属(scaffold 目标库)。非 mysql 连接 / DB 不通 → isSupported() 回 false,调用方跳过。
 */
class SchemaDbAuditor
{
    /** yaml canonical type → information_schema.COLUMNS.DATA_TYPE(只列跟自身不同名的) */
    private const TYPE_TO_DB = [
        'boolean' => 'tinyint',     // MySQL boolean 存为 tinyint(1)
        'jsonb'   => 'json',        // jsonb 是 PG 词汇,MySQL 落 json
    ];

    public function __construct(
        private readonly SchemaLoader $loader,
    ) {}

    /**
     * 漂移类别的人类可读标签(命令行 / UI 共用,避免各处自拼)。
     */
    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'type'           => '类型',
            'size'           => 'size',
            'unique-index'   => '唯一索引',
            'missing-column' => 'DB 缺列',
            default          => $kind,
        };
    }

    /**
     * 当前默认连接是否支持对账(必须 mysql 且能连上)。
     */
    public function isSupported(): bool
    {
        try {
            return DB::connection()->getDriverName() === 'mysql';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 对一个 schema 的所有「DB 已建」表做 yaml↔DB 对账。
     *
     * @return list<array{table:string,column:string,kind:string,yaml:string,db:string}>
     */
    public function audit(string $schema): array
    {
        $normalized = $this->loader->loadNormalized($schema);
        $rows       = [];

        foreach ($normalized['tables'] ?? [] as $tableKey => $table) {
            $tableKey = (string) $tableKey;
            $dbCols   = $this->dbColumns($tableKey);
            if ($dbCols === []) {
                continue;     // 表在 DB 不存在(未迁移)→ 不是漂移,交给 diff 当 create 处理
            }
            $dbUnique = $this->dbSingleColUniqueColumns($tableKey);

            $this->auditColumns($tableKey, $table, $dbCols, $rows);
            $this->auditUniqueIndexes($tableKey, $table, $dbCols, $dbUnique, $rows);
        }

        return $rows;
    }

    /**
     * @param array<string,array{type:string,len:?int}>                                 $dbCols
     * @param list<array{table:string,column:string,kind:string,yaml:string,db:string}> $rows
     */
    private function auditColumns(string $tableKey, array $table, array $dbCols, array &$rows): void
    {
        foreach ($table['fields'] ?? [] as $fieldName => $field) {
            $fieldName = (string) $fieldName;
            if ($fieldName === 'id' || ! empty($field['_system'])) {
                continue;     // id / 系统时间戳字段类型派生,跳过减噪
            }

            if (! isset($dbCols[$fieldName])) {
                $rows[] = [
                    'table' => $tableKey, 'column' => $fieldName, 'kind' => 'missing-column',
                    'yaml'  => (string) ($field['type'] ?? '?'), 'db' => '（列不存在）',
                ];

                continue;
            }

            $yamlType = (string) ($field['type'] ?? '');
            $expected = self::TYPE_TO_DB[$yamlType] ?? $yamlType;
            $dbType   = $dbCols[$fieldName]['type'];
            if ($expected !== $dbType) {
                $rows[] = [
                    'table' => $tableKey, 'column' => $fieldName, 'kind' => 'type',
                    'yaml'  => $yamlType, 'db' => $dbType,
                ];

                continue;     // 类型都不符,size 比较无意义
            }

            if (in_array($yamlType, ['varchar', 'char'], true)) {
                $yamlSize = $field['size'] ?? null;
                $dbLen    = $dbCols[$fieldName]['len'];
                if ($yamlSize !== null && $dbLen !== null && (int) $yamlSize !== $dbLen) {
                    $rows[] = [
                        'table' => $tableKey, 'column' => $fieldName, 'kind' => 'size',
                        'yaml'  => (string) $yamlSize, 'db' => (string) $dbLen,
                    ];
                }
            }
        }
    }

    /**
     * @param array<string,array{type:string,len:?int}>                                 $dbCols
     * @param array<string,true>                                                        $dbUnique
     * @param list<array{table:string,column:string,kind:string,yaml:string,db:string}> $rows
     */
    private function auditUniqueIndexes(string $tableKey, array $table, array $dbCols, array $dbUnique, array &$rows): void
    {
        foreach ($table['index'] ?? [] as $idxName => $entry) {
            $type = (string) ($entry['type'] ?? 'index');
            if ($type === 'primary') {
                continue;
            }
            $col = $this->singleColumn($entry['fields'] ?? $idxName);
            if ($col === null || ! isset($dbCols[$col])) {
                continue;     // 多列索引 / 列不存在(已在 auditColumns 报)→ 跳过
            }

            $yamlUnique = $type === 'unique';
            $dbIsUnique = isset($dbUnique[$col]);
            if ($yamlUnique && ! $dbIsUnique) {
                $rows[] = [
                    'table' => $tableKey, 'column' => $col, 'kind' => 'unique-index',
                    'yaml'  => 'unique', 'db' => '普通索引',
                ];
            } elseif (! $yamlUnique && $dbIsUnique) {
                $rows[] = [
                    'table' => $tableKey, 'column' => $col, 'kind' => 'unique-index',
                    'yaml'  => $type, 'db' => 'unique',
                ];
            }
        }
    }

    /**
     * index 块的 fields 取单列名;多列 / 空 → null(不参与单列对账)。
     */
    private function singleColumn(mixed $fields): ?string
    {
        if (is_string($fields) && $fields !== '') {
            return $fields;
        }
        if (is_array($fields) && count($fields) === 1) {
            return (string) reset($fields);
        }

        return null;
    }

    /**
     * 反查一张表的列:[colName => ['type'=>data_type, 'len'=>char_max_len|null]]。
     * 表不存在 / DB 不通 → []。
     *
     * @return array<string,array{type:string,len:?int}>
     */
    protected function dbColumns(string $table): array // protected：测试可注入假 DB 列对账比对逻辑
    {
        try {
            $rows = DB::select(
                'SELECT COLUMN_NAME AS c, DATA_TYPE AS t, CHARACTER_MAXIMUM_LENGTH AS l '
                . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [DB::connection()->getDatabaseName(), $table],
            );
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->c] = [
                'type' => strtolower((string) $r->t),
                'len'  => $r->l === null ? null : (int) $r->l,
            ];
        }

        return $out;
    }

    /**
     * 一张表里「以单列构成的 unique 索引」覆盖到的列(含 PRIMARY)。
     * 多列复合 unique 不计入(本服务只对单列对账)。
     *
     * @return array<string,true>
     */
    protected function dbSingleColUniqueColumns(string $table): array // protected：测试可注入假唯一索引
    {
        try {
            $rows = DB::select(
                'SELECT INDEX_NAME AS n, NON_UNIQUE AS nu, COLUMN_NAME AS c '
                . 'FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? '
                . 'ORDER BY INDEX_NAME, SEQ_IN_INDEX',
                [DB::connection()->getDatabaseName(), $table],
            );
        } catch (\Throwable) {
            return [];
        }

        // 先按索引名归集列 + 是否 unique
        $byIndex = [];
        foreach ($rows as $r) {
            $name                     = (string) $r->n;
            $byIndex[$name]['unique'] = ((int) $r->nu) === 0;
            $byIndex[$name]['cols'][] = (string) $r->c;
        }

        $out = [];
        foreach ($byIndex as $info) {
            if (($info['unique'] ?? false) && count($info['cols']) === 1) {
                $out[$info['cols'][0]] = true;
            }
        }

        return $out;
    }
}
