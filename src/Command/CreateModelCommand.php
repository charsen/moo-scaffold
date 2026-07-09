<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2025-07-18 10:08
 * @Description: Create Model Command
 */

namespace Mooeen\Scaffold\Command;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Mooeen\Scaffold\Generator\CreateModelGenerator;
use Mooeen\Scaffold\Generator\CreateTSModelGenerator;
use Mooeen\Scaffold\Generator\FreshStorageGenerator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class CreateModelCommand extends Command
{
    protected string $title = 'Create Model Command';

    protected $name = 'moo:model';

    protected $description = 'Generate Model, Trait, Enum, and optionally Factory / TypeScript model files from schema';

    protected function getArguments(): array
    {
        return [
            ['schema_name', InputArgument::OPTIONAL, 'The name of the schema. (Ex: System)'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            ['factory',     '-F', InputOption::VALUE_OPTIONAL, 'Also generate Factory and update DatabaseSeeder.',  false],
            ['force',       '-f', InputOption::VALUE_OPTIONAL, 'Force overwrite Model and Filter files.',            false],
            ['type-script', '-T', InputOption::VALUE_OPTIONAL, 'Also generate frontend TypeScript model files.',     false],
            ['table',       '-t', InputOption::VALUE_OPTIONAL, 'Only generate code for one table key (Ex: system_departments).', null],
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

        // 先刷缓存,再定位 schema —— 这样 `-t` 给的全局唯一表 key 能反查出 schema(读 models.php),无需再选模块
        (new FreshStorageGenerator($this, $this->filesystem, $this->utility))->start(false, true);

        $only_table  = $this->resolveOnlyTable();
        $schema_name = $this->resolveSchemaArg($this->argument('schema_name'), $only_table);
        if ($schema_name === '') {
            return;
        }
        if ($only_table !== null && ! $this->assertTableInSchema($schema_name, $only_table)) {
            return;
        }

        $this->tipCallCommand('moo:model ' . $schema_name);

        $force   = $this->option('force')   === null;
        $factory = $this->option('factory') === null;

        // plan-53:包 schema 不生成 Factory —— 包 model 平铺命名空间(Mooeen\X\Models\Foo)与 host
        // database/factories/{Folder}/ 落点不匹配,Laravel 工厂解析找不到 → db:seed 崩;包 model 也
        // 不进 host seeder(user 2026-07-05 拍板:包不需要 factory)。与 TS 同款 command 级 gate。
        if ($factory && $this->schemaOrigin($schema_name) !== null) {
            $this->console()->info('moo:model -F:扩展包 schema 不生成 Factory(包不需要),跳过。');
            $factory = false;
        }

        $result = (new CreateModelGenerator($this, $this->filesystem, $this->utility))
            ->start($schema_name, $force, $factory, $only_table);

        if ($factory) {
            if ($this->confirmConsoleCommand('artisan db:seed')) {
                $this->tipCallCommand('db:seed');
                $this->call('db:seed');
            }
        }

        if ($this->option('type-script') === null) {
            // plan-53:包 schema 跳过 TS(包无前端,与 moo:view 同款裁决 — 前端结合未设计,user 2026-07-03 拍板)
            if ($this->schemaOrigin($schema_name) !== null) {
                $this->console()->info('moo:ts-model:扩展包 schema 无前端,跳过 TS Model 生成。');
            } else {
                $this->tipCallCommand('moo:ts-model ' . $schema_name);
                (new CreateTSModelGenerator($this, $this->filesystem, $this->utility))->start($schema_name, $force, $only_table);
            }
        }

        $this->tipDone($result);
    }
}
