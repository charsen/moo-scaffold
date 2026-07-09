<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2025-07-16 11:06
 * @Description: Create a new module schema
 */

namespace Mooeen\Scaffold\Command;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Mooeen\Scaffold\Generator\CreateSchemaGenerator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class CreateSchemaCommand extends Command
{
    protected string $title = 'Create a new module schema';

    protected $name = 'moo:schema';

    protected $description = 'Create a new module schema';

    protected function getOptions(): array
    {
        return [
            ['force', '-f', InputOption::VALUE_OPTIONAL, 'Overwrite the schema file.', false],
        ];
    }

    protected function getArguments(): array
    {
        return [
            ['schema_name', InputArgument::REQUIRED, 'The name of the schema. (Ex: System)'],
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

        $schema_name = $this->argument('schema_name');
        $force       = $this->isForced();

        if (str_contains($schema_name, '/')) {
            $this->console()->error('暂不支持多级目录,请用单级名(如 System,不要 System/Sub)。');

            return;
        }

        $result = (new CreateSchemaGenerator($this, $this->filesystem, $this->utility))
            ->start($schema_name, $force);

        $this->tipDone($result);
    }
}
