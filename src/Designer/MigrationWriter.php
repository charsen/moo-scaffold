<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Designer;

use Illuminate\Filesystem\Filesystem;

/**
 * Render & write Laravel migrations from a SchemaDiffService::diff() result.
 */
class MigrationWriter
{
    private const TYPE_TEMPLATES = [
        'char'       => "\$table->char('{name}', {size})",
        'varchar'    => "\$table->string('{name}', {size})",
        'tinytext'   => "\$table->tinyText('{name}')",
        'text'       => "\$table->text('{name}')",
        'mediumtext' => "\$table->mediumText('{name}')",
        'longtext'   => "\$table->longText('{name}')",
        'int'        => "\$table->integer('{name}')",
        'tinyint'    => "\$table->tinyInteger('{name}')",
        'smallint'   => "\$table->smallInteger('{name}')",
        'mediumint'  => "\$table->mediumInteger('{name}')",
        'bigint'     => "\$table->bigInteger('{name}')",
        'date'       => "\$table->date('{name}')",
        'datetime'   => "\$table->dateTime('{name}')",
        'timestamp'  => "\$table->timestamp('{name}')",
        'time'       => "\$table->time('{name}')",
        'boolean'    => "\$table->boolean('{name}')",
        'binary'     => "\$table->binary('{name}')",
        'json'       => "\$table->json('{name}')",
        'jsonb'      => "\$table->jsonb('{name}')",
        'decimal'    => "\$table->decimal('{name}', {size}, {precision})",
        // Laravel 12:double($column) 单参、float($column, $precision=53) 双参 —— 旧的 3 参(total,places)
        // 已废。多塞参 PHP 不报错但语义错(float 把 size 当 precision、double 全忽略)。精确小数用 decimal。
        'double' => "\$table->double('{name}')",
        'float'  => "\$table->float('{name}')",
    ];

    public function __construct(
        private readonly SchemaLoader $loader,
        private readonly GitInspector $git,
        private readonly Filesystem $fs,
        private readonly SnapshotStore $snapshot,
    ) {}

    // ---------------------------------------------------------------
    // Public API
    // ---------------------------------------------------------------

    /**
     * @return array<string, array{filename:string, php_source:string}>
     */
    public function render(array $diff): array
    {
        $out = [];
        foreach ($diff['tables'] as $table => $tableDiff) {
            if ($tableDiff['status'] === 'unchanged') {
                continue;
            }
            // 2026-05-21:baseline 缺失但 DB 已有该表 → 不 emit migration(避免跟 prod 冲突),
            // SchemaDiffService 已塞 warnings,UI / preview 端会显示给 user。
            if ($tableDiff['status'] === 'baseline_drift') {
                continue;
            }
            $out[$table] = [
                'filename'   => $this->pickFilename((string) $table, $tableDiff['status'], (string) $diff['schema']),
                'php_source' => $this->renderMigration((string) $table, $tableDiff),
            ];
        }

        return $out;
    }

    /**
     * Plan 39:GUI 不再做 git commit — 只生成 migration 文件 + 推进 baseline 快照。
     * git add / commit 由开发者手动组合 yaml + migration 一起提交。
     *
     * @return array{files_written:string[]}
     */
    public function write(array $diff): array
    {
        if (! empty($diff['is_empty'])) {
            throw new EmptyDiffException('无变更，拒绝生成空 migration');
        }

        $rendered = $this->render($diff);
        if ($rendered === []) {
            throw new EmptyDiffException('无可写入的 migration');
        }

        $schema = (string) $diff['schema'];
        // 写权硬线(plan-53):包 schema 的 migration 落包目录,vcs 拷贝包拒写
        $this->loader->assertOriginWritable($schema);
        $migrationDir = $this->migrationPath($schema);
        if (! $this->fs->isDirectory($migrationDir)) {
            $this->fs->makeDirectory($migrationDir, 0755, true);
        }

        $filesWritten = [];
        foreach ($rendered as $table => $r) {
            // plan-40 §三 R-6:TOCTOU fix — pickFilename + put 之间有 race window,
            // 同分钟 web+CLI 并发会撞同一 seq filename → 后写者覆盖前写者。
            // 改用 `fopen('xb')` atomic claim,失败时重 pickFilename 直到拿到独占文件名。
            $status         = $diff['tables'][$table]['status'];
            $abs            = $this->atomicWriteWithRetry($migrationDir, $r['filename'], $r['php_source'], $table, $status, $schema);
            $filesWritten[] = $this->relPath($abs);
        }

        // Plan 36 Round 2:只把本次写过 migration 的表更新进 baseline,
        // 避免 only_table 时把其它表未 migrate 的修改吃进 baseline。
        $this->snapshot->captureTables((string) $diff['schema'], array_keys($rendered));

        return [
            'files_written' => $filesWritten,
        ];
    }

    /**
     * 2026-07-04 表 key 改名闭环:直接写一个 `Schema::rename(old, new)` migration(不走 diff ——
     * diff 会把改名看成 drop+create)。调用方(DesignerController::renameTable)负责在 yaml 改名后
     * 接力调本方法 + snapshot captureTables 迁 baseline。返回相对路径。
     *
     * 文件名 `..._rename_{old}_to_{new}_table.php` 尾缀命中 latestMigrationFor 的
     * `_{new}_table.php` 匹配 → 改名后新 key 的 locked 状态自然延续。
     */
    public function writeRename(string $schema, string $oldKey, string $newKey): string
    {
        $this->loader->assertOriginWritable($schema);
        $migrationDir = $this->migrationPath($schema);
        if (! $this->fs->isDirectory($migrationDir)) {
            $this->fs->makeDirectory($migrationDir, 0755, true);
        }

        $source = $this->fillStub('migration_rename_table', [
            'author' => $this->author(),
            'date'   => date('Y-m-d H:i:s'),
            'from'   => $oldKey,
            'to'     => $newKey,
        ]);

        // 同 pickFilename 的秒级时间戳 + 撞名秒数后移;fopen('xb') 原子占名(对齐 atomicWriteWithRetry)
        $existing = $this->listMigrationFilenames($schema);
        $ts       = time();
        for ($offset = 0; $offset < 60; $offset++) {
            $stamp    = date('Y_m_d_His', $ts + $offset);
            $filename = "{$stamp}_rename_{$oldKey}_to_{$newKey}_table.php";
            if (in_array($filename, $existing, true)) {
                continue;
            }
            $fh = @fopen($migrationDir . '/' . $filename, 'xb');
            if ($fh !== false) {
                fwrite($fh, $source);
                fclose($fh);

                return $this->relPath($migrationDir . '/' . $filename);
            }
        }
        throw new \RuntimeException("无法创建 rename migration(撞名重试 60 次失败):{$oldKey} → {$newKey}");
    }

    // ---------------------------------------------------------------
    // Render
    // ---------------------------------------------------------------

    private function renderMigration(string $table, array $tableDiff): string
    {
        return match ($tableDiff['status']) {
            'created' => $this->renderCreate($table, $tableDiff),
            'dropped' => $this->renderDrop($table, $tableDiff),
            'updated' => $this->renderUpdate($table, $tableDiff),
            default   => throw new \LogicException("unknown status: {$tableDiff['status']}"),
        };
    }

    private function renderCreate(string $table, array $tableDiff): string
    {
        $def = $tableDiff['current_definition'];
        // CREATE 的 up() 行与 dropped 的 down() 重建表共用同一套构造(buildIdLine + 字段 + soft/ts + index)。
        $upLines = $this->createLinesFromDefinition($def);

        return $this->fillStub('migration_create', [
            'author'       => $this->author(),
            'date'         => date('Y-m-d H:i:s'),
            'migrant_name' => $def['name'] ?? $table,
            'table'        => $table,
            'schema_up'    => $this->indent(implode("\n", $upLines), 12),
        ]);
    }

    private function renderDrop(string $table, array $tableDiff): string
    {
        $baseline = $tableDiff['baseline_definition'];
        // down() should rebuild table from baseline definition
        $downLines = $this->createLinesFromDefinition($baseline);

        return $this->fillStub('migration_drop', [
            'author'       => $this->author(),
            'date'         => date('Y-m-d H:i:s'),
            'migrant_name' => $baseline['name'] ?? $table,
            'table'        => $table,
            'schema_down'  => $this->indent(implode("\n", $downLines), 12),
            'schema_up'    => '',  // unused, drop stub fixed
        ]);
    }

    private function renderUpdate(string $table, array $tableDiff): string
    {
        $up   = $this->emitUp($tableDiff);
        $down = $this->emitDown($tableDiff);

        return $this->fillStub('migration_update', [
            'author'       => $this->author(),
            'date'         => date('Y-m-d H:i:s'),
            'migrant_name' => $tableDiff['current_definition']['name'] ?? $table,
            'table'        => $table,
            'schema_up'    => $this->indent(implode("\n", $up), 12),
            'schema_down'  => $this->indent(implode("\n", $down), 12),
        ]);
    }

    // ---------------------------------------------------------------
    // emitUp / emitDown — §3.7
    // ---------------------------------------------------------------

    /** @return string[] */
    private function emitUp(array $tableDiff): array
    {
        $fc    = $tableDiff['field_changes'];
        $ic    = $tableDiff['index_changes'];
        $enums = (array) ($tableDiff['current_definition']['enums'] ?? []);     // plan-40 §六 enum-aware default
        $lines = [];

        // 1. modify (use old name if also being renamed)
        foreach ($fc as $ch) {
            if ($ch['op'] !== 'modify') {
                continue;
            }
            $oldName = $this->oldNameForModify($ch, $fc);
            $lines[] = $this->buildColumnLine($oldName, $ch['after'], forChange: true, enums: $enums);
        }
        // 2. rename
        foreach ($fc as $ch) {
            if ($ch['op'] === 'rename') {
                $lines[] = "\$table->renameColumn('{$ch['from']}', '{$ch['to']}');";
            }
        }
        // 3. drop
        foreach ($fc as $ch) {
            if ($ch['op'] === 'drop') {
                $lines[] = "\$table->dropColumn('{$ch['field']}');";
            }
        }
        // 4. add(传 after_field 让 buildColumnLine 追加 ->after('xxx') 保物理位置跟 yaml 一致)
        foreach ($fc as $ch) {
            if ($ch['op'] === 'add') {
                $lines[] = $this->buildColumnLine($ch['field'], $ch['definition'], enums: $enums, afterField: $ch['after_field'] ?? null);
            }
        }
        // 5. index — drop first, then add (modify => drop+add)
        foreach ($ic as $idx) {
            if ($idx['op'] === 'drop' || $idx['op'] === 'modify') {
                $before  = $idx['before'] ?? $idx['baseline'] ?? ['type' => 'index'];
                $lines[] = $this->buildDropIndexLine($idx['name'], $before['type'] ?? 'index');
            }
        }
        foreach ($ic as $idx) {
            if ($idx['op'] === 'add' || $idx['op'] === 'modify') {
                $target  = $idx['after'] ?? $idx;
                $lines[] = $this->buildAddIndexLine([
                    'name'   => $idx['name'],
                    'type'   => $target['type']   ?? 'index',
                    'fields' => $target['fields'] ?? $idx['name'],
                ]);
            }
        }

        return $lines;
    }

    /** @return string[] */
    private function emitDown(array $tableDiff): array
    {
        $fc = $tableDiff['field_changes'];
        $ic = $tableDiff['index_changes'];
        // down() 用 baseline.enums:rebuild drop / reverse modify 走 before 状态 → 还要用历史 enum 映射
        $enums = (array) ($tableDiff['baseline_definition']['enums'] ?? []);
        $lines = [];

        // reverse index: drop the added, re-add the dropped (modify → drop new then add old)
        foreach ($ic as $idx) {
            if ($idx['op'] === 'add' || $idx['op'] === 'modify') {
                $target  = $idx['after'] ?? $idx;
                $lines[] = $this->buildDropIndexLine($idx['name'], $target['type'] ?? 'index');
            }
        }
        foreach ($ic as $idx) {
            if ($idx['op'] === 'drop' || $idx['op'] === 'modify') {
                $before  = $idx['before'] ?? $idx['baseline'] ?? ['type' => 'index', 'fields' => $idx['name']];
                $lines[] = $this->buildAddIndexLine([
                    'name'   => $idx['name'],
                    'type'   => $before['type']   ?? 'index',
                    'fields' => $before['fields'] ?? $idx['name'],
                ]);
            }
        }
        // reverse add → drop
        foreach ($fc as $ch) {
            if ($ch['op'] === 'add') {
                $lines[] = "\$table->dropColumn('{$ch['field']}');";
            }
        }
        // reverse drop → rebuild (use baseline def + baseline enums)
        foreach ($fc as $ch) {
            if ($ch['op'] === 'drop') {
                $lines[] = $this->buildColumnLine($ch['field'], $ch['definition'], enums: $enums);
            }
        }
        // reverse rename
        foreach ($fc as $ch) {
            if ($ch['op'] === 'rename') {
                $lines[] = "\$table->renameColumn('{$ch['to']}', '{$ch['from']}');";
            }
        }
        // reverse modify (use old name + before def + baseline enums)
        foreach ($fc as $ch) {
            if ($ch['op'] !== 'modify') {
                continue;
            }
            $oldName = $this->oldNameForModify($ch, $fc);
            $lines[] = $this->buildColumnLine($oldName, $ch['before'], forChange: true, enums: $enums);
        }

        return $lines;
    }

    private function oldNameForModify(array $modifyChange, array $allChanges): string
    {
        foreach ($allChanges as $ch) {
            if (($ch['op'] ?? null) === 'rename' && ($ch['to'] ?? null) === ($modifyChange['field'] ?? null)) {
                return (string) $ch['from'];
            }
        }

        return (string) $modifyChange['field'];
    }

    // ---------------------------------------------------------------
    // Column line builders
    // ---------------------------------------------------------------

    private function buildIdLine(array $idAttr): string
    {
        $type      = $idAttr['type'] ?? 'bigint';
        $size      = $idAttr['size'] ?? null;
        $name      = $idAttr['name'] ?? null;
        $increment = array_key_exists('increment', $idAttr)
            ? (bool) $idAttr['increment']
            : (array_key_exists('auto_increment', $idAttr)
                ? (bool) $idAttr['auto_increment']
                : ! (bool) config('scaffold.snow_flake_id', false));
        $unsigned = array_key_exists('unsigned', $idAttr) ? (bool) $idAttr['unsigned'] : true;

        $line = match ($type) {
            'bigint' => $unsigned
                ? "\$table->unsignedBigInteger('id'" . ($increment ? ', true' : '') . ')->primary()'
                : "\$table->bigInteger('id'" . ($increment ? ', true' : '') . ')->primary()',
            'int' => $unsigned
                ? "\$table->unsignedInteger('id'" . ($increment ? ', true' : '') . ')->primary()'
                : "\$table->integer('id'" . ($increment ? ', true' : '') . ')->primary()',
            'varchar' => sprintf("\$table->string('id', %d)->primary()", $size ?? 255),
            'char'    => sprintf("\$table->char('id', %d)->primary()", $size ?? 36),     // UUID 36 default(plan 19 §5.4)
            default   => throw new \InvalidArgumentException("unsupported id type: {$type}"),
        };

        if (! empty($name)) {
            $line .= "->comment('" . $this->escape($name) . "')";
        }

        return $line . ';';
    }

    /**
     * @param array $enums table.enums 块({field: {enum_key: [int_value, label_en, label_zh], ...}})
     *                     用于 enum-aware default:yaml 写 `default: highlight`(human-readable enum key)
     *                     → migration `->default(1)`(int value)。
     */
    private function buildColumnLine(string $field, array $def, bool $forChange = false, array $enums = [], ?string $afterField = null): string
    {
        $type = $this->resolveType((string) ($def['type'] ?? 'varchar'));
        if (! isset(self::TYPE_TEMPLATES[$type])) {
            throw new \InvalidArgumentException("unsupported migration type [{$type}] for field [{$field}]");
        }
        $tpl = self::TYPE_TEMPLATES[$type];
        $tpl = str_replace('{name}', $field, $tpl);
        // 2026-05-24 audit P2:size / precision 必须是有效整数(防 yaml 写 "abc" / 数组导致 migration SQL 无效)
        // clamp 到 MySQL 合理范围(varchar size 1-65535,decimal precision 0-30)
        $tpl = str_replace('{size}', (string) max(1, min(65535, (int) ($def['size'] ?? 255))), $tpl);
        $tpl = str_replace('{precision}', (string) max(0, min(30, (int) ($def['precision'] ?? 2))), $tpl);

        $line = $tpl;
        if (! empty($def['unsigned'])) {
            $line .= '->unsigned()';
        }
        $required = array_key_exists('required', $def) ? (bool) $def['required'] : true;
        if (! $required) {
            $line .= '->nullable()';
        }
        if (array_key_exists('default', $def) && $def['default'] !== null && $def['default'] !== '') {
            $line .= $this->renderDefault($field, $def['default'], $type, $enums);
        }
        if (! empty($def['name'])) {
            $line .= "->comment('" . $this->escape((string) $def['name']) . "')";
        }
        // 2026-05-21 bug fix:add column 时追加 ->after('xxx'),保字段在表中的物理位置跟 yaml 一致(否则永远追到表末尾)
        // 仅 add 路径用(forChange=true 是 ->change() 修改字段,不动位置)
        if ($afterField !== null && $afterField !== '' && ! $forChange) {
            $line .= "->after('" . $this->escape($afterField) . "')";
        }
        if ($forChange) {
            $line .= '->change()';
        }

        return $line . ';';
    }

    private function renderDefault(string $field, mixed $value, string $type, array $enums = []): string
    {
        // plan-40 §六 P1 #1:enum-aware default。yaml 写 enum key(human readable)→ 解析到 int value
        // 仅 int 类型(tinyint/int/bigint/smallint/mediumint)+ field 有 enums 块时生效
        $value = $this->resolveEnumDefault($field, $value, $enums);

        if (in_array($type, FieldTypes::INT, true)) {
            return '->default(' . (int) $value . ')';
        }
        if (in_array($type, ['boolean'], true)) {
            return '->default(' . ((bool) $value ? 'true' : 'false') . ')';
        }
        if (in_array($type, FieldTypes::FLOAT, true)) {
            return '->default(' . (float) $value . ')';
        }
        // timestamp/datetime 的 CURRENT_TIMESTAMP:用 ->useCurrent() —— 写成 ->default('current')
        // MySQL 拒绝(TIMESTAMP 不接受字符串默认),migrate 报 1067。
        if (in_array($type, ['timestamp', 'datetime'], true)
            && in_array(strtolower(trim((string) $value)), ['current', 'current_timestamp', 'current_timestamp()', 'now()'], true)) {
            return '->useCurrent()';
        }

        return "->default('" . $this->escape((string) $value) . "')";
    }

    /**
     * yaml `default: highlight` + enums.note_type.highlight=[1,...] → 1。
     * 不匹配时原样返回(下游 cast 会处理 / warn)。
     */
    private function resolveEnumDefault(string $field, mixed $default, array $enums): mixed
    {
        if (! is_string($default) || $default === '') {
            return $default;
        }
        if (! isset($enums[$field][$default])) {
            return $default;
        }
        $entry = $enums[$field][$default];

        return is_array($entry) && isset($entry[0]) ? $entry[0] : $default;
    }

    private function resolveType(string $type): string
    {
        $t = strtolower($type);

        return match ($t) {
            'bool', 'boolean' => 'boolean',
            default           => $t,
        };
    }

    private function buildAddIndexLine(array $idx): string
    {
        $name   = $idx['name'];
        $fields = $idx['fields'] ?? $name;
        $cols   = $this->indexColumnsExpr($fields);

        return match ($idx['type'] ?? 'index') {
            'primary' => "\$table->primary({$cols}, '" . $this->escape($name) . "');",
            'unique'  => "\$table->unique({$cols}, '" . $this->escape($name) . "');",
            default   => "\$table->index({$cols}, '" . $this->escape($name) . "');",
        };
    }

    private function buildDropIndexLine(string $name, string $type): string
    {
        return match ($type) {
            'primary' => "\$table->dropPrimary('" . $this->escape($name) . "');",
            'unique'  => "\$table->dropUnique('" . $this->escape($name) . "');",
            default   => "\$table->dropIndex('" . $this->escape($name) . "');",
        };
    }

    private function indexColumnsExpr(string|array $fields): string
    {
        // GUI 多字段索引（F30）以数组形态写 yaml（fields: [a, b]）；手写 yaml 用逗号串。
        // 两种都先归一为逗号串再切分，strict_types 下不再因数组撞 string 形参抛 TypeError。
        if (is_array($fields)) {
            $fields = implode(',', array_map(static fn ($f) => trim((string) $f), $fields));
        }
        if (! str_contains($fields, ',')) {
            return "'" . $this->escape(trim($fields)) . "'";
        }
        $parts = array_map(fn ($f) => "'" . $this->escape(trim($f)) . "'", explode(',', $fields));

        return '[' . implode(', ', $parts) . ']';
    }

    private function createLinesFromDefinition(array $def): array
    {
        $fields = $def['fields'] ?? [];
        $index  = $def['index']  ?? [];
        $enums  = (array) ($def['enums'] ?? []);     // plan-40 §六 enum-aware default
        $lines  = [];
        if (isset($fields['id'])) {
            $lines[] = $this->buildIdLine($fields['id']);
            unset($fields['id']);
        }
        $hasSoft = isset($fields['deleted_at']);
        unset($fields['deleted_at']);
        // 只有 created_at + updated_at 都在才折成 $table->timestamps();只定义了单个的(罕见但合法)
        // 不 unset、留给下方字段循环按普通 timestamp 列 emit,避免那一列被静默丢弃。
        $hasTs = isset($fields['created_at'], $fields['updated_at']);
        if ($hasTs) {
            unset($fields['created_at'], $fields['updated_at']);
        }
        foreach ($fields as $name => $attr) {
            $lines[] = $this->buildColumnLine((string) $name, $attr, enums: $enums);
        }
        if ($hasSoft) {
            $lines[] = '$table->softDeletes();';
        }
        if ($hasTs) {
            $lines[] = '$table->timestamps();';
        }

        foreach ($index as $idxName => $idxDef) {
            if ($idxName === 'id') {
                continue;
            }
            $lines[] = $this->buildAddIndexLine([
                'name'   => (string) $idxName,
                'type'   => $idxDef['type']   ?? 'index',
                'fields' => $idxDef['fields'] ?? (string) $idxName,
            ]);
        }

        return $lines;
    }

    // ---------------------------------------------------------------
    // File names — §6
    // ---------------------------------------------------------------

    /**
     * plan-40 §三 R-6:atomic claim + retry — fopen('xb') 模式如果文件已存在直接 fail,
     * 保证两个并发(web + CLI 或 multi-tab)不会拿到同 filename。
     *
     * 撞名时(其它 worker 已占)→ pickFilename 重新选 seq(loop 直到 atomic 成功)。
     * 最多重试 N 次(scaffold 单 dev 工具 race 实际很少;高于此次数说明系统异常)。
     */
    private function atomicWriteWithRetry(string $dir, string $candidate, string $content, string $table, string $status, string $schema, int $maxRetry = 10): string
    {
        $tries    = 0;
        $filename = $candidate;
        while ($tries++ < $maxRetry) {
            $abs = $dir . '/' . $filename;
            $fh  = @fopen($abs, 'xb');     // x = exclusive create,文件存在直接 fail(原子)
            if ($fh !== false) {
                fwrite($fh, $content);
                fclose($fh);

                return $abs;
            }
            // 撞名 — 让 pickFilename 重新算 seq(它内部 glob 包括已占用文件)
            $filename = $this->pickFilename($table, $status, $schema);
        }
        throw new \RuntimeException(
            "无法 atomic 创建 migration 文件,撞名重试 {$maxRetry} 次失败:{$candidate}",
        );
    }

    private function pickFilename(string $table, string $status, string $schema): string
    {
        $verb = match ($status) {
            'created' => 'create',
            'updated' => 'update',
            'dropped' => 'drop',
            default   => throw new \LogicException("非法 status: {$status}"),
        };

        // 2026-05-23:标准 Laravel migration 时间戳 Y_m_d_His(完整到秒,跟手写 migration
        // 风格一致)。旧 Y_m_d_H + %04d seq 全分钟/秒填 0 → 满屏 `_080000` `_100000` 失真。
        // 同秒并发碰撞极罕见 — 秒数 +1 重试,最多 60 次(若 1 分钟内同表生成 60+ 次,运维问题)。
        $existing = $this->listMigrationFilenames($schema);
        $ts       = time();
        for ($offset = 0; $offset < 60; $offset++) {
            $stamp    = date('Y_m_d_His', $ts + $offset);
            $filename = "{$stamp}_{$verb}_{$table}_table.php";
            if (! in_array($filename, $existing, true)) {
                return $filename;
            }
        }
        throw new \RuntimeException("同一分钟内连续生成 {$verb} {$table} 60 次以上，请等一分钟再试");
    }

    private function listMigrationFilenames(string $schema): array
    {
        $dir = $this->migrationPath($schema);
        if (! is_dir($dir)) {
            return [];
        }

        return array_map(fn ($p) => basename($p), glob($dir . '/*.php') ?: []);
    }

    private function migrationPath(string $schema): string
    {
        // plan-53:按 schema 出身解析 — host = database_path('migrations'),包 = {包根}/database/migrations
        return $this->loader->migrationDirFor($schema);
    }

    private function relPath(string $abs): string
    {
        try {
            $root = $this->git->repoRoot();
        } catch (NotInGitRepoException) {
            $root = base_path();
        }
        if (str_starts_with($abs, $root . '/')) {
            return ltrim(substr($abs, strlen($root)), '/');
        }

        return $abs;
    }

    // ---------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------

    private function readStub(string $name): ?string
    {
        $path = __DIR__ . '/../../stubs/' . $name . '.stub';
        if (! is_file($path)) {
            return null;
        }

        return file_get_contents($path) ?: null;
    }

    private function fillStub(string $name, array $vars): string
    {
        $content = $this->readStub($name);
        if ($content === null) {
            throw new \RuntimeException("stub not found: {$name}");
        }
        foreach ($vars as $k => $v) {
            $content = str_replace('{{' . $k . '}}', (string) $v, $content);
        }

        return $content;
    }

    private function indent(string $text, int $spaces): string
    {
        $pad   = str_repeat(' ', $spaces);
        $lines = explode("\n", $text);

        return implode("\n", array_map(fn ($l) => $l === '' ? '' : $pad . $l, $lines));
    }

    private function author(): string
    {
        return (string) config('scaffold.author', 'Scaffold');
    }

    private function escape(string $s): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $s);
    }
}
