<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2026-05-20
 * @Description: Create Database Migration Command — plan-40 §六 P1 #2:
 *               整合到 designer 同一套 SchemaDiffService + MigrationWriter,
 *               不再走老 CreateMigrationGenerator(已删)。
 */

namespace Mooeen\Scaffold\Command;

use Mooeen\Scaffold\Designer\EmptyDiffException;
use Mooeen\Scaffold\Designer\MigrationWriter;
use Mooeen\Scaffold\Designer\SchemaDiffService;
use Mooeen\Scaffold\Designer\SchemaLoadException;
use Mooeen\Scaffold\Generator\FreshStorageGenerator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class CreateMigrationCommand extends Command
{
    protected string $title = 'Create Database Migration Command';

    protected $name = 'moo:migration';

    protected $description = 'Generate database migration files from schema';

    protected function getArguments(): array
    {
        return [
            ['schema_name', InputArgument::OPTIONAL, 'The name of the schema. (Ex: System)'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            ['table', '-t', InputOption::VALUE_OPTIONAL, 'Only generate migration for one table key (Ex: system_departments); other tables changes are skipped.', null],
        ];
    }

    public function handle(SchemaDiffService $diffService, MigrationWriter $writer): void
    {
        $this->showTitle();

        if (! $this->checkRunning()) {
            return;
        }

        // 先刷 storage/scaffold cache(让 generator 看到最新 yaml),再定位 schema —— `-t` 可按全局唯一表 key 反查 schema
        (new FreshStorageGenerator($this, $this->filesystem, $this->utility))->start(false, true);

        $only_table  = $this->resolveOnlyTable();
        $schema_name = $this->resolveSchemaArg($this->argument('schema_name'), $only_table);
        if ($schema_name === '') {
            return;
        }

        $this->tipCallCommand('moo:migration ' . $schema_name);

        // plan-40 §六 P1 #2:CLI 走 designer 同一套 diff + writer,生成 create / update / drop 三类 migration。
        // 相比老 CreateMigrationGenerator(只 create)更完整,跟 designer 行为一致。
        try {
            $diff = $diffService->diff($schema_name);
        } catch (SchemaLoadException $e) {
            $this->console()->error("schema 加载失败:{$e->getMessage()}");

            return;
        }

        // -t/--table:把 diff 收窄到单张表(在 suspected_renames 检查前过滤,别让其它表的疑似改名拦住本表)
        if ($only_table !== null) {
            $filtered = SchemaDiffService::filterToTable($diff, $only_table);
            if ($filtered === null) {
                $this->console()->error("表 key \"{$only_table}\" 不在 schema \"{$schema_name}\" 的表集中(baseline 与 current 中都没有)。");
                $this->line('  可选表 key:' . (($keys = array_keys($diff['tables'] ?? [])) === [] ? '(无)' : implode(', ', $keys)));

                return;
            }
            $diff = $filtered;
            $this->console()->info("单表模式:仅为表 [{$only_table}] 生成 migration");
        }

        // suspected_renames:CLI 没 GUI rename hint 流,提示用户走 Web UI 标改名
        if (! empty($diff['suspected_renames'])) {
            $this->tipUseDesignerRename('确认后再重跑 moo:migration');
            foreach ($diff['suspected_renames'] as $r) {
                $this->line("  {$r['table']}: {$r['drop']} → {$r['add']}?");
            }

            return;
        }

        try {
            $result = $writer->write($diff);
        } catch (EmptyDiffException $e) {
            $this->console()->info('无变更,跳过生成 migration。');

            return;
        }

        $files = $result['files_written'] ?? [];
        $this->console()->info(count($files) . ' 个 migration 文件已生成');
        foreach ($files as $f) {
            $this->line('  + ' . $f);
        }
        // plan-39:GUI 不再 git commit,CLI 同样不自动 commit,提示用户手动
        $this->line('');
        $this->line('<fg=gray>提示:scaffold 不会自动 git commit,请手动:</>');
        $this->line('<fg=gray>  git add scaffold/database/' . $schema_name . '.yaml scaffold/database/.snapshots/' . $schema_name . '.yaml database/migrations/...</>');
        $this->line('<fg=gray>  git commit -m "scaffold: update ' . $schema_name . ' schema"</>');

        if ($this->confirmConsoleCommand('artisan migrate')) {
            $this->tipCallCommand('migrate');
            $this->call('migrate');
        }

        $this->tipDone(true);
    }
}
