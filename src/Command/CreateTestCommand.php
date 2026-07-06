<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Description: Create Feature Test Command —— moo:test [schema] [-f]
 *
 * 给某 schema 下所有控制器批量吐路由契约冒烟测（Pest，B-lean）。批量 + 非交互，可进 moo:free。
 * 写类命令，只在 local 跑（checkRunning）。生成一次，-f 覆盖。
 */

namespace Mooeen\Scaffold\Command;

use Mooeen\Scaffold\Generator\CreateTestGenerator;
use Mooeen\Scaffold\Generator\FreshStorageGenerator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class CreateTestCommand extends Command
{
    protected string $title = 'Create Feature Test Command';

    protected $name = 'moo:test';

    protected $description = 'Generate route-contract smoke tests (Pest) for generated controllers';

    protected function getArguments(): array
    {
        return [
            ['schema_name', InputArgument::OPTIONAL, 'The name of the schema. (Ex: Light)'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            ['force', '-f', InputOption::VALUE_OPTIONAL, 'Force overwrite existing test files.', false],
        ];
    }

    public function handle(): void
    {
        $this->showTitle();

        if (! $this->checkRunning()) {
            return;
        }

        $schema_name = $this->argument('schema_name');
        if (empty($schema_name)) {
            // plan-53:包 schema 测试脚手架未设计(路径/命名空间推导是 host 形态)—— 列表只给 host schema
            $schema_name = $this->chooseSchema(array_values(array_filter($this->utility->getSchemaNames(), fn (string $s): bool => $this->utility->schemaOrigin($s) === null)));
        } elseif ($this->utility->schemaOrigin((string) $schema_name) !== null) {
            $this->console()->error("「{$schema_name}」是扩展包 schema —— moo:test 暂不支持扩展包。");

            return;
        }

        (new FreshStorageGenerator($this, $this->filesystem, $this->utility))->start(false, true);

        $all = $this->utility->getControllers(false);
        if (! isset($all[$schema_name])) {
            $this->reportSchemaNotFound($schema_name);

            return;
        }

        $force     = $this->option('force') === null;
        $generator = new CreateTestGenerator($this, $this->filesystem, $this->utility);

        $this->tipCallCommand('moo:test ' . $schema_name);
        foreach (array_keys($all[$schema_name]) as $controller) {
            $generator->start($schema_name, $controller, $force);
        }

        $this->tipDone(true);
        $this->tipRunTests($generator->testDirs($schema_name));
    }
}
