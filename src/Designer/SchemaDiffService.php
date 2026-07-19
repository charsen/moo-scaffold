<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Designer;

use Illuminate\Support\Facades\Schema;

/**
 * Compute YAML diff between working-tree YAML and last-captured snapshot.
 *
 * Public surface: diff() returns the designer migration preview payload.
 *
 * Plan 36:baseline 改用「上次成功生成 migration 时的 yaml 快照」(.snapshots/{Schema}.yaml)。
 * 取代 plan 30 的「扫 migration 反推」路线 — 快照 100% 准、不需要 AST 解析、无 warning。
 * 以快照文件作为 migration diff 的稳定基线。
 */
class SchemaDiffService
{
    public function __construct(
        private readonly SchemaLoader $loader,
        private readonly SnapshotStore $snapshot,
    ) {}

    /**
     * @param array $renameHints ['<table>.<old_field>' => '<new_field>']
     *
     * @throws SchemaLoadException
     */
    public function diff(string $schema, array $renameHints = []): array
    {
        $current  = $this->loader->loadNormalized($schema);
        $baseline = $this->loadBaseline($schema);
        // Round 2 P2:expose baseline 文件是否存在,UI 可显示"首次 migrate 后建立 baseline"提示
        $baselineMissing = ! $this->snapshot->exists($schema);

        $baselineTables = $baseline['tables'] ?? [];
        $currentTables  = $current['tables']  ?? [];

        $tablesDiff       = [];
        $suspectedRenames = [];
        $isEmpty          = true;

        $allKeys = array_unique([...array_keys($baselineTables), ...array_keys($currentTables)]);
        foreach ($allKeys as $tableKey) {
            $b = $baselineTables[$tableKey] ?? null;
            $c = $currentTables[$tableKey]  ?? null;

            if ($b === null && $c !== null) {
                // 2026-05-21 防呆:baseline 缺 + DB 已有该表 → user 误删 snapshot 的 drift 状态,
                // 不能当"新建"emit create_table(会跟 prod 已 ran 的 create 冲突,migrate 报
                // duplicate column / table)。返 baseline_drift status,MigrationWriter 跳过 emit,
                // UI 显警告引导 user 手动恢复 baseline。
                if ($this->dbHasTable($tableKey)) {
                    $tablesDiff[$tableKey] = $this->baselineDriftDiff($c, $tableKey);
                    $isEmpty               = false;

                    continue;
                }
                $tablesDiff[$tableKey] = $this->createdTableDiff($c);
                $isEmpty               = false;

                continue;
            }
            if ($b !== null && $c === null) {
                $tablesDiff[$tableKey] = $this->droppedTableDiff($b);
                $isEmpty               = false;

                continue;
            }

            // both present → diff
            $tableHints   = $this->extractTableHints($tableKey, $renameHints);
            $fieldChanges = $this->fieldDiff($b['fields'], $c['fields'], $tableHints);
            $indexChanges = $this->indexDiff($b['index'], $c['index']);

            $tableSuspects    = $this->detectSuspectedRenames($tableKey, $fieldChanges, $tableHints);
            $suspectedRenames = array_merge($suspectedRenames, $tableSuspects);

            $status = (count($fieldChanges) === 0 && count($indexChanges) === 0) ? 'unchanged' : 'updated';
            if ($status === 'updated') {
                $isEmpty = false;
            }

            $tablesDiff[$tableKey] = [
                'status'              => $status,
                'baseline_definition' => $b,
                'current_definition'  => $c,
                'field_changes'       => $fieldChanges,
                'index_changes'       => $indexChanges,
                'warnings'            => $this->warningCheck($fieldChanges, $indexChanges, $b['fields']),
            ];
        }

        return [
            'schema'            => $schema,
            'is_empty'          => $isEmpty,
            'baseline_missing'  => $baselineMissing,
            'tables'            => $tablesDiff,
            'suspected_renames' => $suspectedRenames,
        ];
    }

    /**
     * 把 diff 收窄到单张表 key(供 moo:migration / moo:free 的 -t 单表模式用):
     * 只留该表的 tables 项 + 该表的 suspected_renames,并按该表 status 重算 is_empty
     * (只有 created/updated/dropped 有 migration 可写,unchanged/baseline_drift 不写)。
     * 表 key 不在 diff(baseline 与 current 中都没有)→ 返回 null,由调用方报错。
     */
    public static function filterToTable(array $diff, string $table): ?array
    {
        if (! isset($diff['tables'][$table])) {
            return null;
        }

        $tableDiff                 = $diff['tables'][$table];
        $diff['tables']            = [$table => $tableDiff];
        $diff['suspected_renames'] = array_values(array_filter(
            $diff['suspected_renames'] ?? [],
            static fn ($r) => ($r['table'] ?? null) === $table,
        ));
        $diff['is_empty'] = ! in_array($tableDiff['status'] ?? 'unchanged', ['created', 'updated', 'dropped'], true);

        return $diff;
    }

    // ---------------------------------------------------------------
    // Baseline loader — Plan 36:读 .snapshots/{Schema}.yaml
    // 无快照 → 返回空 baseline(冷启动 / 新 schema → 全 create migration)
    // ---------------------------------------------------------------

    private function loadBaseline(string $schema): array
    {
        $raw = $this->snapshot->load($schema);
        if ($raw === null) {
            return ['tables' => []];
        }
        try {
            return $this->loader->loadFromString($raw, $schema);
        } catch (SchemaLoadException) {
            // 快照文件坏了(yq/编辑器误改格式)→ 当冷启动处理
            return ['tables' => []];
        }
    }

    // ---------------------------------------------------------------
    // Field diff — §2.4
    // ---------------------------------------------------------------

    /**
     * Compare two fields arrays (keyed by field name). Returns list of changes.
     * Order: modify → drop → add → rename (final emit ordering done in writer).
     */
    private function fieldDiff(array $before, array $after, array $tableHints): array
    {
        $changes = [];

        // Apply rename hints first: matched pairs are recorded as 'rename'
        // and removed from add/drop consideration.
        $renamed = [];   // old => new
        foreach ($tableHints as $oldField => $newField) {
            if (isset($before[$oldField]) && isset($after[$newField])) {
                $renamed[$oldField] = $newField;
                $changes[]          = ['op' => 'rename', 'from' => $oldField, 'to' => $newField];

                // Also check if attrs differ → emit modify for the renamed field.
                $modify = $this->compareField($before[$oldField], $after[$newField]);
                if ($modify !== null) {
                    $changes[] = [
                        'op'            => 'modify',
                        'field'         => $newField,           // refer to new name; writer maps to old via oldNameForModify
                        'before'        => $before[$oldField],
                        'after'         => $after[$newField],
                        'changed_attrs' => $modify,
                    ];
                }
            }
        }

        // Build effective sets (exclude renamed pairs)
        $effectiveBefore = $before;
        foreach ($renamed as $old => $_) {
            unset($effectiveBefore[$old]);
        }
        $effectiveAfter = $after;
        foreach ($renamed as $_ => $new) {
            unset($effectiveAfter[$new]);
        }

        // 2026-05-21 bug fix:add op 追踪 yaml 中前一个字段 key 当 after,让 migration 生成 ->after('xxx')
        // 保字段在表中的物理位置跟 yaml 一致(否则新字段永远追在表末尾)。
        // 用完整 yaml after 顺序(含 system fields)算 prev,避免 system field 占位被跳过导致 after 错位。
        $orderedAfterKeys = array_keys($after);

        $keys = array_unique([...array_keys($effectiveBefore), ...array_keys($effectiveAfter)]);
        foreach ($keys as $key) {
            $b = $effectiveBefore[$key] ?? null;
            $a = $effectiveAfter[$key]  ?? null;

            if (in_array($key, ['id', 'deleted_at', 'created_at', 'updated_at'], true)) {
                continue;  // system fields — skip
            }

            if ($b === null && $a !== null) {
                // 找 $key 在完整 yaml after 顺序里的前一个 key,作为 ->after(prev) 的参数
                $idx        = array_search($key, $orderedAfterKeys, true);
                $afterField = ($idx !== false && $idx > 0) ? $orderedAfterKeys[$idx - 1] : null;
                $changes[]  = ['op' => 'add', 'field' => $key, 'definition' => $a, 'after_field' => $afterField];

                continue;
            }
            if ($b !== null && $a === null) {
                $changes[] = ['op' => 'drop', 'field' => $key, 'definition' => $b];

                continue;
            }

            // modify?
            $modify = $this->compareField($b, $a);
            if ($modify !== null) {
                $changes[] = [
                    'op'            => 'modify',
                    'field'         => $key,
                    'before'        => $b,
                    'after'         => $a,
                    'changed_attrs' => $modify,
                ];
            }
        }

        return $changes;
    }

    /**
     * @return string[]|null list of changed-attr names, or null if equal
     */
    private function compareField(array $before, array $after): ?array
    {
        $diffs = [];
        $type  = $after['type'] ?? $before['type'] ?? 'varchar';

        // type
        $tb = $this->normalizeType((string) ($before['type'] ?? ''));
        $ta = $this->normalizeType((string) ($after['type'] ?? ''));
        if ($tb !== $ta) {
            $diffs[] = 'type';
        }

        // size
        $sb = $this->normalizeSize($before['size'] ?? null);
        $sa = $this->normalizeSize($after['size'] ?? null);
        if ($sb !== $sa) {
            $diffs[] = 'size';
        }

        // precision (decimal/double/float)
        if (in_array($ta, FieldTypes::FLOAT, true)) {
            $pb = $before['precision'] ?? null;
            $pa = $after['precision']  ?? null;
            if ($pb !== $pa) {
                $diffs[] = 'precision';
            }
        }

        // nullable (= !required)
        $nb = ! (bool) ($before['required'] ?? true);
        $na = ! (bool) ($after['required'] ?? true);
        if ($nb !== $na) {
            $diffs[] = 'nullable';
        }

        // default (type-aware normalize)
        if (! $this->defaultsEqual($before['default'] ?? null, $after['default'] ?? null, $ta)) {
            $diffs[] = 'default';
        }

        // unsigned
        $ub = (bool) ($before['unsigned'] ?? false);
        $ua = (bool) ($after['unsigned'] ?? false);
        if ($ub !== $ua) {
            $diffs[] = 'unsigned';
        }

        // name (Chinese comment)
        $cb = (string) ($before['name'] ?? '');
        $ca = (string) ($after['name'] ?? '');
        if ($cb !== $ca) {
            $diffs[] = 'name';
        }

        return $diffs === [] ? null : $diffs;
    }

    private function normalizeType(string $t): string
    {
        $t = strtolower($t);

        return match ($t) {
            'bool', 'boolean' => 'boolean',
            default           => $t,
        };
    }

    private function normalizeSize(mixed $s): ?int
    {
        if ($s === null || $s === '') {
            return null;
        }
        if (is_int($s)) {
            return $s;
        }
        if (is_string($s) && ctype_digit($s)) {
            return (int) $s;
        }

        return null;
    }

    /**
     * §2.5
     */
    private function normalizeDefault(mixed $raw, string $type): int|string|bool|null
    {
        if ($raw === null) {
            return null;
        }

        return match (true) {
            // 非数值字面(enum key,如 int 列 `default: high`,migration 端 resolveEnumDefault 映射成
            // 实际 int)必须原样保留比较 —— 原 `(int)'high'`=0 把所有 enum-key 默认值塌成 0,导致
            // 改默认值(high→low,实际 1→0)被 diff 判为"无变化"、不生成 migration,DB 默认值留旧
            // (2026-06-10 修)。数值字面仍按类型强转,'5' 与 5 视为相等。
            in_array($type, FieldTypes::INT, true)                                                   => is_numeric($raw) ? (int) $raw : (string) $raw,
            in_array($type, FieldTypes::FLOAT, true)                                                 => is_numeric($raw) ? (string) (float) $raw : (string) $raw,
            in_array($type, ['boolean'], true)                                                       => (bool) (is_string($raw) ? (int) $raw : $raw),
            in_array($type, ['varchar', 'char', 'text', 'tinytext', 'mediumtext', 'longtext'], true) => (string) $raw,
            in_array($type, ['timestamp', 'datetime', 'date', 'time'], true)                         => strtoupper(trim((string) $raw)),
            in_array($type, ['json', 'jsonb'], true)                                                 => json_encode(json_decode((string) $raw, true), JSON_UNESCAPED_UNICODE) ?: (string) $raw,
            default                                                                                  => (string) $raw,
        };
    }

    private function defaultsEqual(mixed $a, mixed $b, string $type): bool
    {
        return $this->normalizeDefault($a, $type) === $this->normalizeDefault($b, $type);
    }

    // ---------------------------------------------------------------
    // Index diff — §2.6
    // ---------------------------------------------------------------

    private function indexDiff(array $before, array $after): array
    {
        // filter id primary
        $strip = static function (array $idx) {
            unset($idx['id']);

            return $idx;
        };
        $before = $strip($before);
        $after  = $strip($after);

        $changes = [];
        $keys    = array_unique([...array_keys($before), ...array_keys($after)]);
        foreach ($keys as $key) {
            $b = $before[$key] ?? null;
            $a = $after[$key]  ?? null;

            if ($b === null && $a !== null) {
                $changes[] = ['op' => 'add', 'name' => (string) $key, 'type' => $a['type'] ?? 'index', 'fields' => $a['fields'] ?? (string) $key];

                continue;
            }
            if ($b !== null && $a === null) {
                $changes[] = ['op' => 'drop', 'name' => (string) $key, 'baseline' => $b];

                continue;
            }
            if (($b['type'] ?? null) !== ($a['type'] ?? null) || ($b['fields'] ?? null) !== ($a['fields'] ?? null)) {
                $changes[] = [
                    'op'     => 'modify',
                    'name'   => (string) $key,
                    'before' => $b,
                    'after'  => $a,
                ];
            }
        }

        return $changes;
    }

    // ---------------------------------------------------------------
    // Warnings — deliverables list spec
    // ---------------------------------------------------------------

    private function warningCheck(array $fieldChanges, array $indexChanges, array $beforeFields): array
    {
        $warnings = [];

        // plan-40 §四 A-2 性能优化:drop / rename 字段集中起来一次 batch grep,
        // 替代每字段一次 shell_exec(原 N 次 → 1 次,几百 ms × N 改为 ~几百 ms)
        $depFieldNames = [];
        foreach ($fieldChanges as $ch) {
            if ($ch['op'] === 'drop') {
                $depFieldNames[] = (string) $ch['field'];
            }
            if ($ch['op'] === 'rename') {
                $depFieldNames[] = (string) $ch['from'];
            }
        }
        $depMap = ! empty($depFieldNames) ? $this->findReverseDepsBatch($depFieldNames) : [];

        foreach ($fieldChanges as $ch) {
            if ($ch['op'] === 'drop') {
                $warnings[] = [
                    'level' => 'high',
                    'code'  => 'DROP_COLUMN',
                    'msg'   => "drop column {$ch['field']} 会丢数据",
                ];
                // plan-40 §四 C-6 + Round 2 P1 #1:drop 字段时 grep 反向依赖,带行号
                // designer 不做主动清理(误删自定义 rule 风险),只列命中点让 user 手动核对
                // plan-41 §三 A:hit 按 Trait.php 命名分 auto(下次 moo:fresh 重写)/ manual
                foreach ($depMap[(string) $ch['field']] ?? [] as $hit) {
                    $warnings[] = [
                        'level'     => 'medium',
                        'code'      => 'REVERSE_DEP_DROP',
                        'msg'       => "字段 {$ch['field']} 仍被引用（请手动核对）：{$hit}",
                        'dep_kind'  => $this->classifyDepHit($hit),
                        'dep_field' => (string) $ch['field'],
                        'dep_hit'   => $hit,
                    ];
                }

                continue;
            }
            if ($ch['op'] === 'rename') {
                // plan-40 §四 C-5 + Round 2 P1 #1:rename 字段时 grep 旧名反向依赖,带行号
                foreach ($depMap[(string) $ch['from']] ?? [] as $hit) {
                    $warnings[] = [
                        'level'     => 'medium',
                        'code'      => 'REVERSE_DEP_RENAME',
                        'msg'       => "原名 {$ch['from']} 仍被引用，改名后请手动同步：{$hit}",
                        'dep_kind'  => $this->classifyDepHit($hit),
                        'dep_field' => (string) $ch['from'],
                        'dep_hit'   => $hit,
                    ];
                }

                continue;
            }
            if ($ch['op'] !== 'modify') {
                continue;
            }
            $changed = $ch['changed_attrs'] ?? [];
            $field   = $ch['field'];
            $before  = $ch['before'];
            $after   = $ch['after'];

            if (in_array('size', $changed, true)) {
                $sb = $this->normalizeSize($before['size'] ?? null);
                $sa = $this->normalizeSize($after['size'] ?? null);
                if ($sb !== null && $sa !== null && $sa < $sb) {
                    $warnings[] = [
                        'level' => 'medium',
                        'code'  => 'SIZE_SHRINK',
                        'msg'   => "{$field} {$before['type']} {$sb}→{$sa} 会截断",
                    ];
                }
            }
            if (in_array('type', $changed, true)) {
                if ($this->isTypeNarrowing((string) ($before['type'] ?? ''), (string) ($after['type'] ?? ''))) {
                    $warnings[] = [
                        'level' => 'medium',
                        'code'  => 'TYPE_NARROWING',
                        'msg'   => "{$field} {$before['type']}→{$after['type']} 范围收窄",
                    ];
                }
            }
            if (in_array('default', $changed, true)) {
                if (($before['default'] ?? null) !== null && ($after['default'] ?? null) === null) {
                    $warnings[] = [
                        'level' => 'low',
                        'code'  => 'DEFAULT_REMOVED',
                        'msg'   => "{$field} 移除 default 值，下次插入需提供",
                    ];
                }
            }
        }
        unset($indexChanges, $beforeFields);  // unused for MVP warnings

        return $warnings;
    }

    /**
     * plan-40 §四 C-5/C-6 + Round 2 P1 #1 升级:全仓 grep 反向依赖。
     * plan-40 §四 A-2 优化:批量 — 多字段一次 grep -E '(a|b|c)',N 次 shell_exec → 1 次。
     *
     * scaffold 是单 dev 工具,grep 一次 base_path() 几百 ms 可接受。
     * 仅扫 app/ + scaffold/(排除 vendor / storage / public 等 generated/binary)。
     * 每字段 cap 8 hits 防 message 爆炸。失败 silent(best effort)。
     *
     * Round 2 P1 #1:grep -n 带行号 + 内容片段,user 拿到 warning 直接跳到具体行。
     * 不做"主动 AST 清理"(改动面大 + 注释/格式风险 + 误删自定义 rule),只升级 warning 工艺。
     *
     * @param list<string> $fieldNames
     *
     * @return array<string, list<string>> field => ["path:line:snippet", ...](每字段最多 8 条)
     */
    private function findReverseDepsBatch(array $fieldNames): array
    {
        // 过滤短名 + 非法 ident + dedup
        $valid = array_values(array_unique(array_filter(
            $fieldNames,
            fn ($n) => is_string($n) && preg_match('/^[a-z][a-z0-9_]*$/', $n) && strlen($n) >= 3,
        )));
        if (empty($valid)) {
            return [];
        }

        $base = base_path();
        if (! is_dir($base . '/app') && ! is_dir($base . '/scaffold')) {
            return array_fill_keys($valid, []);
        }

        // grep -E '('foo'|'bar'|'baz')' — 单引号包裹防 word-prefix 误命中(filter_user vs user)
        // 字段名经入口严校 `^[a-z][a-z0-9_]*$`,无 regex meta 需要 escape,直接拼 alt
        $alt     = implode('|', array_map(fn ($n) => "'" . $n . "'", $valid));
        $pattern = '(' . $alt . ')';

        // 总 cap = N * 8 + 一点冗余;过 head 之前 grep 已 stream,head 截断够用
        $cap = count($valid) * 8 + 16;
        $cmd = sprintf(
            '(grep -rEHn --include="*.php" --include="*.yaml" --exclude-dir=".snapshots" %s %s %s 2>/dev/null) | head -%d',
            escapeshellarg($pattern),
            is_dir($base . '/app') ? escapeshellarg($base . '/app') : '',
            is_dir($base . '/scaffold') ? escapeshellarg($base . '/scaffold') : '',
            $cap,
        );
        $out     = @shell_exec($cmd);
        $byField = array_fill_keys($valid, []);
        if (! is_string($out) || trim($out) === '') {
            return $byField;
        }

        foreach (preg_split("/\r?\n/", trim($out)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // grep -EHn 输出:/abs/path:LINE_NO:snippet
            $path    = '';
            $lineNo  = '';
            $snippet = '';
            if (preg_match('/^(.+?):(\d+):(.*)$/', $line, $m)) {
                $path    = $m[1];
                $lineNo  = $m[2];
                $snippet = trim($m[3]);
                if (str_starts_with($path, $base . '/')) {
                    $path = substr($path, strlen($base) + 1);
                }
                if (strlen($snippet) > 80) {
                    $snippet = substr($snippet, 0, 77) . '...';
                }
                $hit = "{$path}:{$lineNo}  {$snippet}";
            } else {
                if (str_starts_with($line, $base . '/')) {
                    $line = substr($line, strlen($base) + 1);
                }
                $hit     = $line;
                $snippet = $line;
            }
            // 分桶:每个 fieldName 在 snippet 里 substr 命中即归属(用 'fieldname' 单引号形态,跟 needle 一致)
            // 一行可能命中多字段(rare),都计 — 但每字段 cap 8 防爆
            foreach ($valid as $fn) {
                if (count($byField[$fn]) >= 8) {
                    continue;
                }
                if (str_contains($snippet, "'" . $fn . "'")) {
                    $byField[$fn][] = $hit;
                }
            }
        }

        return $byField;
    }

    /**
     * plan-41 §三 A:reverse dep hit 按文件后缀分类 — `*Trait.php` 是 codegen 每次重写,
     * 下次 `moo:fresh` 自动清旧字段引用 → auto;其余(*Model.php / *Filter.php / Request /
     * lang / api yaml / Seeder / 业务 view)需手清 → manual。
     *
     * hit 字符串形如 "path:line  snippet" — 看 path 段是否以 Trait.php 结尾。
     */
    private function classifyDepHit(string $hit): string
    {
        // 提 path 段:hit format "path:line  snippet",path 不含冒号或 (path 含冒号但末段是 :line)
        // 用 regex 抓首段 path
        if (preg_match('/^([^:]+\.php)/', $hit, $m) || preg_match('/^([^:]+\.yaml)/', $hit, $m)) {
            $path = $m[1];
            if (str_ends_with($path, 'Trait.php')) {
                return 'auto';
            }
            // app/Models/<Model>/<Field>Enum.php 也是 codegen 每次重写(buildEnum 整文件覆盖)
            if (preg_match('#/Enums/[A-Z][A-Za-z0-9]+\.php$#', $path)) {
                return 'auto';
            }
        }

        return 'manual';
    }

    private function isTypeNarrowing(string $from, string $to): bool
    {
        $rank = ['tinyint' => 1, 'smallint' => 2, 'mediumint' => 3, 'int' => 4, 'bigint' => 5];
        if (isset($rank[$from], $rank[$to])) {
            return $rank[$to] < $rank[$from];
        }
        // text family
        $textRank = ['tinytext' => 1, 'text' => 2, 'mediumtext' => 3, 'longtext' => 4];
        if (isset($textRank[$from], $textRank[$to])) {
            return $textRank[$to] < $textRank[$from];
        }

        return false;
    }

    // ---------------------------------------------------------------
    // Suspected renames — MVP: exactly 1 add + 1 drop per table
    // ---------------------------------------------------------------

    private function detectSuspectedRenames(string $table, array $fieldChanges, array $tableHints): array
    {
        if ($tableHints !== []) {
            return [];
        }   // user already supplied → don't pester
        $drops = [];
        $adds  = [];
        foreach ($fieldChanges as $ch) {
            if ($ch['op'] === 'drop') {
                $drops[] = $ch['field'];
            } elseif ($ch['op'] === 'add') {
                $adds[] = $ch['field'];
            }
        }
        if (count($drops) === 1 && count($adds) === 1) {
            return [[
                'table' => $table,
                'drop'  => $drops[0],
                'add'   => $adds[0],
            ]];
        }

        return [];
    }

    /** @return array<string,string> [old=>new] */
    private function extractTableHints(string $table, array $renameHints): array
    {
        $out = [];
        foreach ($renameHints as $key => $newName) {
            // accept '<table>.<old>' format
            if (str_contains((string) $key, '.')) {
                [$tbl, $old] = explode('.', (string) $key, 2);
                if ($tbl === $table) {
                    $out[$old] = (string) $newName;
                }
            }
        }

        return $out;
    }

    /**
     * 2026-05-21:baseline 缺失但 DB 已有该表 → "drift" 状态,不 emit migration code,只给 user 警告。
     */
    private function baselineDriftDiff(array $current, string $tableKey): array
    {
        return [
            'status'              => 'baseline_drift',
            'baseline_definition' => null,
            'current_definition'  => $current,
            'field_changes'       => [],
            'index_changes'       => [],
            'warnings'            => [[
                'level' => 'high',
                // 2026-05-21 fix:跟其他 warning 项统一用 'msg' 字段(frontend openPreview 取 w.msg)
                //   原 'label' 漏对齐导致 baseline_drift warning 在 preview drawer 显空白
                'msg' => "baseline 缺失但 DB 已存在表 {$tableKey}，拒生成 create_table migration（会跟 prod 已 ran 冲突）。请手动恢复 .snapshots/{Schema}.yaml 的 {$tableKey} 段，或从 git 还原 snapshot。",
            ]],
        ];
    }

    /**
     * 检 DB 是否已有该表。catch 所有 throw — DB 不可达 / 测试环境 → 当 false 走默认 created 路径。
     */
    private function dbHasTable(string $tableKey): bool
    {
        try {
            return Schema::hasTable($tableKey);
        } catch (\Throwable) {
            return false;
        }
    }

    private function createdTableDiff(array $current): array
    {
        $fields = [];
        foreach ($current['fields'] as $name => $def) {
            if (in_array($name, ['id', 'deleted_at', 'created_at', 'updated_at'], true)) {
                continue;
            }
            $fields[] = ['op' => 'add', 'field' => (string) $name, 'definition' => $def];
        }

        return [
            'status'              => 'created',
            'baseline_definition' => null,
            'current_definition'  => $current,
            'field_changes'       => $fields,
            'index_changes'       => [],
            'warnings'            => [],
        ];
    }

    private function droppedTableDiff(array $baseline): array
    {
        return [
            'status'              => 'dropped',
            'baseline_definition' => $baseline,
            'current_definition'  => null,
            'field_changes'       => [],
            'index_changes'       => [],
            'warnings'            => [['level' => 'high', 'code' => 'DROP_TABLE', 'msg' => '删除整张表会丢全部数据']],
        ];
    }
}
