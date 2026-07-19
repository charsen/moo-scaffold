<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2025-07-16 11:02
 * @LastEditors: Charsen
 * @LastEditTime: 2025-08-29 17:18
 * @Description: Create Frontend View Command
 */

namespace Mooeen\Scaffold\Command;

use Mooeen\Scaffold\Generator\CreateViewGenerator;
use Mooeen\Scaffold\Generator\FreshStorageGenerator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class CreateViewCommand extends Command
{
    protected string $title = 'Create Frontend View Command';

    protected $name = 'moo:view';

    protected $description = 'Generate frontend Vue page scaffolding (index, trashed, show)';

    protected function getArguments(): array
    {
        return [
            ['schema_name', InputArgument::OPTIONAL, 'The name of the schema. (Ex: Light)'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            ['force', '-f', InputOption::VALUE_OPTIONAL, 'Force overwrite existing Vue view files.', false],
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
            // plan-53:moo:view 本 plan 不碰包(前端未结合)—— 列表只给 host schema
            $schema_name = $this->chooseSchema($this->hostSchemaNames());
        } elseif ($this->schemaOrigin((string) $schema_name) !== null) {
            $this->console()->error("「{$schema_name}」是扩展包 schema —— moo:view（前端脚手架）暂不支持扩展包。");

            return;
        }

        (new FreshStorageGenerator($this, $this->filesystem, $this->utility))->start(false, true);

        $all = $this->utility->getControllers(false);
        if (! isset($all[$schema_name])) {
            $this->reportSchemaNotFound($schema_name);

            return;
        }

        $force      = $this->isForced();
        $controller = $this->choicePrompt('选择控制器', array_keys($all[$schema_name]));

        $this->tipCallCommand('moo:view ' . $schema_name);
        $result = (new CreateViewGenerator($this, $this->filesystem, $this->utility))
            ->start($schema_name, $controller, $force);

        $this->tipDone($result);
    }
}
