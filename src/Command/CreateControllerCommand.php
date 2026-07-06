<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2025-07-27 17:24
 * @Description: Create Controller Command
 */

namespace Mooeen\Scaffold\Command;

use Mooeen\Scaffold\Generator\CreateControllerGenerator;
use Mooeen\Scaffold\Generator\FreshStorageGenerator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class CreateControllerCommand extends Command
{
    protected string $title = 'Create Controller Command';

    protected $name = 'moo:controller';

    protected $description = 'Generate Controller, Request, Trait files and update route definitions';

    protected function getArguments(): array
    {
        return [
            ['schema_name', InputArgument::OPTIONAL, 'The name of the schema. (Ex: System)'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            ['force', '-f', InputOption::VALUE_OPTIONAL, 'Force overwrite Controller, Request, and Trait files.', false],
            ['table', '-t', InputOption::VALUE_OPTIONAL, 'Only generate code for one table key (Ex: system_departments).', null],
        ];
    }

    public function handle(): void
    {
        $this->showTitle();

        if (! $this->checkRunning()) {
            return;
        }

        // 创建 Admin 的 BaseActionTrait
        (new CreateControllerGenerator($this, $this->filesystem, $this->utility))->checkAdminBaseAction();

        // 先刷缓存,再定位 schema —— `-t` 给的全局唯一表 key 能反查出 schema(读 models.php),无需再选模块
        (new FreshStorageGenerator($this, $this->filesystem, $this->utility))->start(false, true);

        $only_table  = $this->resolveOnlyTable();
        $schema_name = $this->resolveSchemaArg($this->argument('schema_name'), $only_table);
        if ($schema_name === '') {
            return;
        }
        if ($only_table !== null && ! $this->assertTableInSchema($schema_name, $only_table)) {
            return;
        }

        $force = $this->option('force') === null;

        $this->tipCallCommand('moo:controller ' . $schema_name);
        $result = (new CreateControllerGenerator($this, $this->filesystem, $this->utility))
            ->start($schema_name, $force, $only_table);

        $this->tipDone($result);
    }
}
