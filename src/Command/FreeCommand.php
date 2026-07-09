<?php declare(strict_types=1);

/*
 * @Author: Charsen Charsen
 * @Date: 2024-08-02 09:10
 * @LastEditors: Charsen
 * @LastEditTime: 2025-08-29 17:36
 * @Description: Free : Release your hands
 */

namespace Mooeen\Scaffold\Command;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use Mooeen\Scaffold\Designer\EmptyDiffException;
use Mooeen\Scaffold\Designer\MigrationWriter;
use Mooeen\Scaffold\Designer\SchemaDiffService;
use Mooeen\Scaffold\Generator\CreateApiGenerator;
use Mooeen\Scaffold\Generator\CreateControllerGenerator;
use Mooeen\Scaffold\Generator\CreateModelGenerator;
use Mooeen\Scaffold\Generator\CreateResourceGenerator;
use Mooeen\Scaffold\Generator\CreateTestGenerator;
use Mooeen\Scaffold\Generator\FreshStorageGenerator;
use Mooeen\Scaffold\Generator\UpdateAuthorizationGenerator;
use Mooeen\Scaffold\Generator\UpdateMultilingualGenerator;
use Mooeen\Scaffold\RouterTool;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class FreeCommand extends Command
{
    protected string $title = 'Free : Release your hands';

    protected $name = 'moo:free';

    protected $description = 'Run the full generation pipeline: model, resource, controller, test, i18n, auth, migration, optionally API docs';

    protected Router $router;

    public function __construct(Filesystem $filesystem, Utility $utility, Router $router)
    {
        parent::__construct($filesystem, $utility);

        $this->router = $router;
    }

    protected function getArguments(): array
    {
        // 参数顺序:app 在前 schema 在后 —— 跟 moo:api / moo:auth 一致(app 名少、好记,作主锚);
        // schema 名不一定记得,可省(配 -t 时按表 key 自动反查,或交互选)
        return [
            ['app',    InputArgument::OPTIONAL, 'The name of the app. (Ex: admin)'],
            ['schema', InputArgument::OPTIONAL, 'The name of the schema. (Ex: System)'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            ['force', '-f', InputOption::VALUE_OPTIONAL, 'Force overwrite Model, Controller, and Request files.', false],
            ['api',   '-a', InputOption::VALUE_OPTIONAL, 'Also generate API YAML documentation.', false],
            ['table', '-t', InputOption::VALUE_OPTIONAL, 'Only generate code for one table key (Ex: system_departments). Filters Model/Resource/Controller; i18n / auth / migration / api still run in full.', null],
        ];
    }

    /**
     * @throws FileNotFoundException
     */
    public function handle(): void
    {
        $this->showTitle();

        if (! $this->checkRunning()) {
            return;
        }

        $force = $this->isForced();
        $api   = $this->option('api') === null;

        // -t/--table:只生成单个表 key 的 Model/Resource/Controller;空值(仅给 flag 不给值)视作不过滤
        $only_table = $this->resolveOnlyTable();

        // app 优先:名少、好记,作主锚;无法由表反推 → 显式给或交互选
        $apps = $this->utility->getConfig('controller');
        $app  = $this->argument('app') ?: $this->chooseApp($apps);
        if (! isset($apps[$app])) {
            $this->reportAppNotConfigured($app, 'Please check the scaffold configuration and try again.');

            return;
        }

        // 先刷缓存,再定位 schema —— `-t` 给的全局唯一表 key 能反查出 schema(读 models.php),省得记模块名
        (new FreshStorageGenerator($this, $this->filesystem, $this->utility))->start(false, true);

        $file_names  = $this->utility->getSchemaNames();
        $schema_name = $this->resolveSchemaArg($this->argument('schema'), $only_table, $app);
        if ($schema_name === '') {
            return;
        }
        if (! in_array($schema_name, $file_names, true)) {
            $this->reportSchemaNotFound($schema_name);

            return;
        }

        // plan-53 fail-fast:包 schema 固定 admin(交互路径已按 app 收窄列不出来;这里拦显式传参的矛盾组合)
        $schema_origin = $this->utility->schemaOrigin($schema_name);
        if ($schema_origin !== null && $app !== 'admin') {
            $this->console()->error("「{$schema_name}」是扩展包 [{$schema_origin}] 的 schema(固定 admin),不能用于 app 「{$app}」。");

            return;
        }

        // -t/--table 校验:必须是该 schema 下真实存在的表 key,否则报错并列出可选项
        if ($only_table !== null && ! $this->assertTableInSchema($schema_name, $only_table)) {
            return;
        }

        $this->tipCallCommand('moo:model');
        (new CreateModelGenerator($this, $this->filesystem, $this->utility))->start($schema_name, $force, false, $only_table);

        $this->tipCallCommand('moo:resource');
        (new CreateResourceGenerator($this, $this->filesystem, $this->utility))->start($schema_name, $force, $only_table);

        $this->tipCallCommand('moo:controller');
        (new CreateControllerGenerator($this, $this->filesystem, $this->utility))->start($schema_name, $force, $only_table);

        // moo:test 跟 i18n/auth 一样全量(不吃 -t;-t 只过滤 Model/Resource/Controller)。生成一次,-f 才覆盖。
        // plan-53:包 schema 暂不生成测试(测试脚手架路径/命名空间推导是 host 形态,包侧未设计)
        if ($schema_origin !== null) {
            $this->console()->info('moo:test:扩展包 schema 暂不生成测试脚手架,跳过。');
        } else {
            $this->tipCallCommand('moo:test');
            $test_gen = new CreateTestGenerator($this, $this->filesystem, $this->utility);
            foreach (array_keys($this->utility->getControllers(false)[$schema_name] ?? []) as $controller) {
                $test_gen->start($schema_name, $controller, $force);
            }
        }

        $this->tipCallCommand('moo:i18n');
        // plan-53 i18n 分流:包 schema → 词条子集进包 lang/;host → 全量照旧
        (new UpdateMultilingualGenerator($this, $this->filesystem, $this->utility))->start($schema_name);

        $this->tipCallCommand('moo:auth');
        $tool   = new RouterTool($app, '', 'action', $this->utility, $this->router);
        $routes = $tool->get();
        (new UpdateAuthorizationGenerator($this, $this->filesystem, $this->utility))->start($app, $tool->storeActions($routes));

        $this->tipCallCommand('moo:migration');
        // plan-40 §六 P1 #2:走 designer 同一套 diff + writer,不再调老 CreateMigrationGenerator(已删)。
        // moo:free 是流水线,migration 阶段如果 empty diff / 加载失败 / suspected_renames 都不阻断后续步骤,只 warn。
        // -t 单表模式:migration 同步只写这张表(否则单表生成代码 + 全量迁移自相矛盾)。
        $migration_count = $this->runMigrationStep($schema_name, $only_table);

        if ($api) {
            $this->tipCallCommand('moo:api');
            $tool   = new RouterTool($app, $schema_name, 'uri', $this->utility, $this->router);
            $routes = $tool->get();
            (new CreateApiGenerator($this, $this->filesystem, $this->utility))->start($app, $schema_name, $tool->storeActions($routes));
        }

        if ($this->confirmConsoleCommand('artisan migrate')) {
            $this->tipCallCommand('migrate');
            $this->call('migrate');
        }

        $this->summarizeFree($schema_name, $app, $only_table, $migration_count, $api);
        // 包 schema 走上面 if 分支跳过 moo:test,$test_gen 未定义 → tipRunTests 也只 host 路径调
        // (否则包 schema 全部生成完后 fatal:Call to a member function testDirs() on null)。
        if ($schema_origin === null) {
            $this->tipRunTests($test_gen->testDirs($schema_name));
        }
    }

    /**
     * plan-40 §六 P1 #2:moo:free 流水线 migration 阶段,容错 ≠ 阻断。
     * 跟独立 moo:migration 不同(那里 suspected_renames 直接 return)— free 是 batch 流程,要继续。
     */
    private function runMigrationStep(string $schemaName, ?string $onlyTable = null): int
    {
        $diffService = app(SchemaDiffService::class);
        $writer      = app(MigrationWriter::class);

        try {
            $diff = $diffService->diff($schemaName);
        } catch (\Throwable $e) {
            $this->console()->warn("migration 阶段:schema 加载失败 {$e->getMessage()},跳过");

            return 0;
        }

        // -t 单表模式:收窄到该表(表名已在 model 步前 assertTableInSchema 校验过,理论不会 null;兜底 warn)
        if ($onlyTable !== null) {
            $filtered = SchemaDiffService::filterToTable($diff, $onlyTable);
            if ($filtered === null) {
                $this->console()->warn("migration 阶段:表 [{$onlyTable}] 不在变更集,跳过");

                return 0;
            }
            $diff = $filtered;
        }

        if (! empty($diff['suspected_renames'])) {
            $this->tipUseDesignerRename('确认后单独跑 moo:migration(本步已跳过)');

            return 0;
        }

        try {
            $result = $writer->write($diff);
        } catch (EmptyDiffException) {
            $this->console()->info('migration 阶段:无变更,跳过');

            return 0;
        } catch (\Throwable $e) {
            $this->console()->warn("migration 阶段失败:{$e->getMessage()}");

            return 0;
        }

        $files = $result['files_written'] ?? [];
        if (count($files) === 0) {
            $this->console()->info('migration 阶段:无文件生成');

            return 0;
        }
        $this->console()->info(count($files) . ' 个 migration 文件已生成');
        foreach ($files as $f) {
            $this->line('  + ' . $f);
        }

        return count($files);
    }

    /**
     * moo:free 末尾总结:一眼看清这趟跑了什么、规模多大。model/resource/controller 是「存在则跳过」,
     * 故按 schema 范围报数(不是"本次新建"数);migration 报本次真正写入的文件数。
     */
    private function summarizeFree(string $schema, string $app, ?string $onlyTable, int $migrationCount, bool $api): void
    {
        // 收成一个对齐小块:绿徽标抬头(2 空格)+ 明细缩进(4 空格)+ 上下留白,数字高亮抓眼
        $hi = fn (int|string $v): string => "<fg=cyan;options=bold>{$v}</>";

        $this->newLine();
        $this->line("  <fg=black;bg=green;options=bold> ✓ moo:free </>  <options=bold>{$schema}</> → <options=bold>{$app}</>");

        if ($onlyTable !== null) {
            $this->line('    单表 ' . $hi($onlyTable) . ' · model / resource / controller <fg=gray>(存在则跳过)</>');
        } else {
            $t = count($this->utility->getModels()[$schema] ?? []);
            $c = count($this->utility->getControllers(false)[$schema] ?? []);
            $this->line('    ' . $hi($t) . ' model · ' . $hi($t) . ' resource · ' . $hi($c) . ' controller <fg=gray>(存在则跳过)</>');
        }

        $this->line('    i18n · auth 已更新 · migration 本次 <fg=green;options=bold>+' . $migrationCount . '</>' . ($api ? ' · API 文档已生成' : ''));
        $this->newLine();
    }
}
