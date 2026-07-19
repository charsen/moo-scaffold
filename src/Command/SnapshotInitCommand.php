<?php declare(strict_types=1);

/*
 * @Description: Plan 36 — 给现有 schema 一次性落初始 baseline 快照
 *
 * 用法:
 *   moo:snapshot:init                  # 处理所有 schema
 *   moo:snapshot:init --schema=Order   # 只处理 Order
 *   moo:snapshot:init --dry-run        # 只列文件,不写
 *   moo:snapshot:init --force          # 覆盖已存在快照(默认 skip)
 *   moo:snapshot:init --no-db-check    # 跳过 yaml↔DB 对账(无 DB / 非 mysql 时)
 *
 * 前置假设:当前 yaml 跟 migrations + DB 一致(都按 designer / moo:migration 走过)。
 * 初始化后,后续 designer diff 会以「当前 yaml」为 baseline。
 * 另落基线前做一道纯文件系统预警:yaml 有表但无 `create_<table>_table.php` 的,会被静默吸进
 * baseline → 之后永远判「无变化」(2026-06-18 market_services 事故)。这类表会逐条 ⚠ 列出 +
 * 末尾汇总,提醒先 `moo:migration <Schema> -t <表>` 补 create 再 --force 重跑。
 * 为防「yaml 先漂了再 init → 漂移被静默洗成基线、骗过 designer diff」,落基线前会用
 * SchemaDbAuditor 反查活 DB(information_schema)对账列类型 / varchar size / 单列 unique 索引,
 * 不符就在该 schema 行下报 ⚠ drift + 末尾汇总(只读告警,baseline 仍照 yaml 落 — 提醒先修 yaml)。
 * 详见数据库设计器 baseline 快照机制说明。
 */

namespace Mooeen\Scaffold\Command;

use Illuminate\Support\Facades\Schema;
use Mooeen\Scaffold\Designer\SchemaDbAuditor;
use Mooeen\Scaffold\Designer\SnapshotStore;
use Symfony\Component\Yaml\Yaml;

class SnapshotInitCommand extends Command
{
    protected bool $requiresLocalEnvironment = true;

    protected string $title = 'Init Designer Baseline Snapshots';

    protected $name = 'moo:snapshot:init';

    protected $description = 'Write initial baseline snapshots for existing schemas (designer diff baseline)';

    protected $signature = 'moo:snapshot:init
        {--schema= : Only process the given schema (e.g. Order); all if omitted}
        {--dry-run : List snapshots that would be written, without writing}
        {--force : Overwrite existing snapshots (skipped by default)}
        {--no-db-check : Skip the YAML/DB reconciliation (no DB / non-MySQL)}';

    public function handle(SnapshotStore $store, SchemaDbAuditor $auditor): int
    {
        $this->showTitle();

        $only   = (string) ($this->option('schema') ?? '');
        $dryRun = (bool) $this->option('dry-run');
        $force  = (bool) $this->option('force');

        $schemas = $this->collectSchemas($only);
        if ($schemas === []) {
            $this->warn($only !== '' ? "没找到 schema：{$only}" : '没找到任何 schema yaml 文件');

            return self::FAILURE;
        }

        // baseline 落盘前反查活 DB,把 yaml↔DB 漂移报出来 — 不让漂移被静默洗成基线。
        // 非 mysql / DB 不可达 / 显式 --no-db-check → 跳过,行为同旧版。
        $auditable = ! (bool) $this->option('no-db-check') && $auditor->isSupported();
        if (! $auditable && ! (bool) $this->option('no-db-check')) {
            $this->line('  <fg=gray>（跳过 yaml↔DB 对账：非 mysql 连接或 DB 不可达）</>');
        }

        $written    = 0;
        $skipped    = 0;
        $errors     = [];
        $driftRows  = [];
        $missingAll = [];     // schema => list<table>:yaml 有表但无 create migration(会被吸进 baseline 后卡「无变化」)

        foreach ($schemas as $schema) {
            $existed = $store->exists($schema);
            $path    = $store->snapshotPath($schema);
            $relPath = $this->relPath($path);

            if ($existed && ! $force) {
                // plan-37 P1-3:dry-run 模式下 skip 行也带 [dry-run] 标识,跟非 dry-run 区分
                $prefix = $dryRun ? '<fg=gray>[dry-run]</> ' : '';
                $this->line("  {$prefix}<fg=gray>skip</>     {$schema}  → {$relPath}（已存在，加 --force 覆盖）");
                $skipped++;

                continue;
            }

            // 落基线的 schema 才对账(skip 的不在本次重新洗基线,无需打扰)
            $rows      = $auditable ? $auditor->audit($schema) : [];
            $driftRows = array_merge($driftRows, $rows);

            // yaml 有表但没对应 create migration → 吸进 baseline 后会永久卡「无变化」
            // (2026-06-18 market_services 事故)。DB 可达时再收窄到「且 DB 也没这表」,滤掉
            // Laravel 框架表(sessions/cache 等,走框架迁移、已在 DB)这类噪声。
            $missing = $this->tablesMissingCreateMigration($schema, $auditable);
            if ($missing !== []) {
                $missingAll[$schema] = $missing;
            }

            if ($dryRun) {
                $tag = $existed ? '<fg=yellow>would overwrite</>' : '<fg=cyan>would create</>';
                $this->line("  {$tag}  {$schema}  → {$relPath}");
                $this->printDrift($rows);
                $this->printMissing($missing);

                continue;
            }

            try {
                $store->capture($schema);
                $tag = $existed ? '<fg=yellow>overwritten</>' : '<fg=green>created</>';
                $this->line("  {$tag}  {$schema}  → {$relPath}");
                $this->printDrift($rows);
                $this->printMissing($missing);
                $written++;
            } catch (\Throwable $e) {
                $errors[] = [$schema, $e->getMessage()];
                $this->line("  <fg=red>error</>    {$schema}  → {$e->getMessage()}");
            }
        }

        $this->line('');
        if ($dryRun) {
            $this->printDriftSummary($driftRows);
            $this->printMissingSummary($missingAll);
            $this->line('<fg=gray>dry-run 不写盘。去掉 --dry-run 实际执行。</>');

            return self::SUCCESS;
        }
        $this->info("快照初始化完成：写 {$written} 个，skip {$skipped} 个，error " . count($errors) . ' 个');
        $this->printDriftSummary($driftRows);
        $this->printMissingSummary($missingAll);
        if (count($errors) > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * 把一个 schema 的 yaml↔DB 漂移逐条缩进打印在它的状态行下面。
     *
     * @param list<array{table:string,column:string,kind:string,yaml:string,db:string}> $rows
     */
    private function printDrift(array $rows): void
    {
        foreach ($rows as $r) {
            $kind = SchemaDbAuditor::kindLabel($r['kind']);
            $this->line(
                "      <fg=yellow>⚠ drift</> {$r['table']}.{$r['column']}  "
                . "<fg=gray>{$kind}</> yaml=<fg=cyan>{$r['yaml']}</> db=<fg=magenta>{$r['db']}</>",
            );
        }
    }

    /**
     * @param list<array{table:string,column:string,kind:string,yaml:string,db:string}> $rows
     */
    private function printDriftSummary(array $rows): void
    {
        $n = count($rows);
        if ($n === 0) {
            return;
        }
        $this->warn("⚠ 发现 {$n} 处 yaml↔DB 漂移（上方 drift 行）。baseline 已照「当前 yaml」落盘，");
        $this->warn('  但 yaml 与实际 DB 不符 —— 请按 DB 现状修正 yaml 后重跑 moo:snapshot:init --force，');
        $this->warn('  不要把漂移连同 baseline 一起 commit（会掩盖真实 yaml↔DB 分歧）。');
        $this->line('<fg=gray>  随时可单独跑 `php artisan moo:db:audit` 复核 yaml↔DB 一致性。</>');
    }

    /**
     * 逐表打印「无 create migration」警告(会被吸进 baseline → 之后卡「无变化」)。
     *
     * @param list<string> $tables
     */
    private function printMissing(array $tables): void
    {
        foreach ($tables as $t) {
            $this->line(
                "      <fg=yellow>⚠ 无 create migration</> {$t}  "
                . '<fg=gray>（将吸进 baseline → designer/moo:migration 之后判「无变化」）</>',
            );
        }
    }

    /**
     * @param array<string, list<string>> $missingAll
     */
    private function printMissingSummary(array $missingAll): void
    {
        if ($missingAll === []) {
            return;
        }
        $total = array_sum(array_map('count', $missingAll));
        $this->warn("⚠ {$total} 张表在 yaml 里有、但找不到对应 create migration 文件（上方 ⚠ 无 create migration 行）。");
        $this->warn('  baseline 仍照 yaml 落盘 —— 但这些表已被吸进基线，之后会被判「无变化」、永远不再生成。');
        $this->warn('  若这些表本就该建：删掉 .snapshots/<Schema>.yaml 里对应表段 → `moo:migration <Schema> -t <表>` 单独补，');
        $this->warn('  再重跑 moo:snapshot:init --force。');
    }

    /**
     * 找出 schema 里「yaml 有表、但 migrations 目录下没有 create_<table>_table.php」的表。
     * 纯文件系统判断(不连 DB):有 create migration = 这张表走过正常生成流程,可安全入 baseline。
     *
     * @return list<string>
     */
    private function tablesMissingCreateMigration(string $schema, bool $dbAware): array
    {
        // plan-53:按出身解析(包 schema 的 yaml 与 migrations 都在包内)
        $loader = app(\Mooeen\Scaffold\Designer\SchemaLoader::class);
        $file   = $loader->yamlPath($schema);
        if (! is_file($file)) {
            return [];
        }
        try {
            $parsed = Yaml::parse((string) file_get_contents($file)) ?: [];
        } catch (\Throwable) {
            return [];
        }
        $tableKeys = array_keys((array) ($parsed['tables'] ?? []));
        if ($tableKeys === []) {
            return [];
        }

        $migrationsDir = $loader->migrationDirFor($schema);
        $filenames     = is_dir($migrationsDir)
            ? array_map('basename', glob($migrationsDir . '/*.php') ?: [])
            : [];

        $missing = self::tablesWithoutCreateMigration($tableKeys, $filenames);

        // DB 可达时:无 create migration 但表已在 DB 的(Laravel 框架表走框架迁移)不算「会卡住」,
        // 已存在 → baseline 收录无害,滤掉以免噪声。DB 不可达(--no-db-check / 非 mysql)则全报。
        if ($dbAware && $missing !== []) {
            $missing = array_values(array_filter($missing, static fn (string $t): bool => ! self::dbHasTableSafe($t)));
        }

        return $missing;
    }

    private static function dbHasTableSafe(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;     // 查不动就当不存在 → 倾向多提醒(宁可报,不可漏)
        }
    }

    /**
     * 纯匹配(可单测):返回 $tableKeys 中没有 `*_create_<table>_table.php` 文件的表。
     * 用 str_ends_with 锚定 `_create_<table>_table.php` —— create 文件名恒为
     * `<ts>_create_<table>_table.php`,故该后缀精确区分 market_services ↔ market_base_services。
     *
     * @param list<string> $tableKeys
     * @param list<string> $migrationFilenames basename 列表
     *
     * @return list<string>
     */
    public static function tablesWithoutCreateMigration(array $tableKeys, array $migrationFilenames): array
    {
        $missing = [];
        foreach ($tableKeys as $table) {
            $needle = '_create_' . $table . '_table.php';
            $found  = false;
            foreach ($migrationFilenames as $fn) {
                if (str_ends_with($fn, $needle)) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $missing[] = $table;
            }
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    private function collectSchemas(string $only): array
    {
        // plan-53:走 SchemaLoader 聚合枚举(host + 扩展包),出身由 loader 统一解析
        $loader = app(\Mooeen\Scaffold\Designer\SchemaLoader::class);
        $files  = $loader->listSchemaFiles();

        if ($only !== '') {
            return isset($files[$only]) ? [$only] : [];
        }

        $out = array_keys($files);
        sort($out);

        return $out;
    }

    private function relPath(string $abs): string
    {
        $base = base_path();

        return str_starts_with($abs, $base) ? substr($abs, strlen($base) + 1) : $abs;
    }
}
