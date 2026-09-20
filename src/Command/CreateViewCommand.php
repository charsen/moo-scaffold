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
use Mooeen\Scaffold\Support\StorageRegistry;
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

    public function handle(): int
    {
        $this->showTitle();

        if (! $this->checkRunning()) {
            return self::FAILURE;
        }

        $schema_name = $this->argument('schema_name');
        if (empty($schema_name)) {
            // plan-53:moo:view 本 plan 不碰包(前端未结合)—— 列表只给 host schema
            $schema_name = $this->chooseSchema($this->hostSchemaNames());
            if ($schema_name === null) {
                return self::FAILURE;
            }
        } elseif ($this->schemaOrigin((string) $schema_name) !== null) {
            $this->console()->error("「{$schema_name}」是扩展包 schema —— moo:view（前端脚手架）暂不支持扩展包。");

            return self::FAILURE;
        }

        (new FreshStorageGenerator($this, $this->filesystem, $this->utility))->start(false, true);

        $all = StorageRegistry::controllers(false);
        if (! isset($all[$schema_name])) {
            return $this->reportSchemaNotFound($schema_name);
        }

        $force      = $this->isForced();
        $controller = $this->chooseRequired(
            '选择控制器',
            array_keys($all[$schema_name]),
            "schema「{$schema_name}」下没有控制器可生成 view。请先跑 moo:controller / moo:free 生成控制器。",
            // 本命令只有 schema_name 参数、**没有** controller 参数 ⇒ 非交互下无出路，只能回交互终端
            '未选择控制器。本命令的控制器只能交互选择，请去掉 --no-interaction 重跑。',
        );
        if ($controller === null) {
            return self::FAILURE;   // 早前 null 直接进 CreateViewGenerator::start(string $controller) 抛 TypeError
        }

        $this->tipCallCommand('moo:view ' . $schema_name);
        $result = (new CreateViewGenerator($this, $this->filesystem, $this->utility))
            ->start($schema_name, $controller, $force);

        return $this->tipDone($result);
    }
}
