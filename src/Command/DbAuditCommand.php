<?php declare(strict_types=1);

/*
 * @Description: 随手查 yaml ↔ 实际 DB 漂移 —— 编码人员可随时发起的只读体检。
 *
 * 用法:
 *   moo:db:audit                  # 查所有 schema
 *   moo:db:audit --schema=Platform # 只查 Platform
 *
 * 跟 moo:snapshot:init 落基线时的内嵌对账同源(共用 SchemaDbAuditor),但这是**独立、好记**
 * 的入口:平时不必记 snapshot 那套,改完 yaml / 怀疑 DB 跟 yaml 不一致时直接跑这条。
 * 纯只读(只查 information_schema),任何环境可跑 —— 也可用来核对生产 DB 跟 yaml 是否一致。
 * 有漂移 → 退出码 1(可当 pre-commit / CI 闸门);干净 → 0。
 */

namespace Mooeen\Scaffold\Command;

use Mooeen\Scaffold\Designer\SchemaDbAuditor;

class DbAuditCommand extends Command
{
    protected bool $requiresLocalEnvironment = false;     // 只读,任何环境可跑(含 prod 核对)

    protected string $title = 'Schema ↔ DB Drift Audit';

    protected $name = 'moo:db:audit';

    protected $description = 'Audit schema YAML against the live database and report drift (read-only)';

    protected $signature = 'moo:db:audit
        {--schema= : Only audit the given schema (e.g. Platform); all if omitted}';

    public function handle(SchemaDbAuditor $auditor): int
    {
        $this->showTitle();

        if (! $auditor->isSupported()) {
            $this->warn('无法对账:当前默认连接不是 mysql,或数据库不可达。');
            $this->line('<fg=gray>(本命令依赖 mysql information_schema 反查实际表结构)</>');

            return self::SUCCESS;
        }

        $only    = (string) ($this->option('schema') ?? '');
        $schemas = $this->collectSchemas($only);
        if ($schemas === []) {
            $this->warn($only !== '' ? "没找到 schema:{$only}" : '没找到任何 schema yaml 文件');

            return self::FAILURE;
        }

        $totalDrift   = 0;
        $dirtySchemas = 0;

        foreach ($schemas as $schema) {
            $rows = $auditor->audit($schema);
            if ($rows === []) {
                $this->line("  <fg=green>✓</> {$schema}  <fg=gray>yaml 与 DB 一致</>");

                continue;
            }

            $dirtySchemas++;
            $totalDrift += count($rows);
            $n = count($rows);
            $this->line("  <fg=yellow>⚠ {$schema}</>  <fg=gray>({$n} 处漂移)</>");
            $this->printRows($rows);
        }

        $this->line('');
        if ($totalDrift === 0) {
            $this->info('✓ 全部 ' . count($schemas) . ' 个 schema 的 yaml 与实际 DB 一致,无漂移。');

            return self::SUCCESS;
        }

        $this->warn("⚠ 发现 {$totalDrift} 处 yaml↔DB 漂移,涉及 {$dirtySchemas} 个 schema。");
        $this->line('<fg=gray>  请按 DB 现状修正对应 yaml(designer 改或手改),改完可重跑本命令复核;</>');
        $this->line('<fg=gray>  若 baseline 也需同步,再跑 moo:snapshot:init --schema=X --force。</>');

        return self::FAILURE;
    }

    /**
     * 把一个 schema 的漂移按表分组缩进打印。
     *
     * @param list<array{table:string,column:string,kind:string,yaml:string,db:string}> $rows
     */
    private function printRows(array $rows): void
    {
        $byTable = [];
        foreach ($rows as $r) {
            $byTable[$r['table']][] = $r;
        }
        foreach ($byTable as $table => $items) {
            $this->line("      <fg=gray>{$table}</>");
            foreach ($items as $r) {
                $kind = SchemaDbAuditor::kindLabel($r['kind']);
                $this->line(
                    "        {$r['column']}  <fg=gray>{$kind}</>  "
                    . "yaml=<fg=cyan>{$r['yaml']}</>  db=<fg=magenta>{$r['db']}</>",
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function collectSchemas(string $only): array
    {
        $all = $this->schemaNames();
        sort($all);
        if ($only === '') {
            return $all;
        }

        return in_array($only, $all, true) ? [$only] : [];
    }
}
