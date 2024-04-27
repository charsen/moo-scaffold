<?php

namespace Mooeen\Scaffold\Command;

use Mooeen\Scaffold\Generator\CreateSchemaGenerator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Create a new module schema
 *
 * @author Charsen https://github.com/charsen
 */
class CreateSchemaCommand extends Command
{
    /**
     * The console command title.
     *
     * @var string
     */
    protected $title = 'Create a new module schema';

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'moo:schema';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new module schema';

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            [
                'force',
                '-f',
                InputOption::VALUE_OPTIONAL,
                'Overwrite the schema file.',
                false,
            ],
        ];
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['schema_name', InputArgument::REQUIRED, 'The name of the schema. (Ex: System)'],
        ];
    }

    /**
     * Execute the console command.
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function handle(): bool
    {
        $this->alert($this->title);

        $schema_name = $this->argument('schema_name');
        $force       = $this->option('force') === null;

        if (str_contains($schema_name, '/')) {
            $this->error('Multi-level directory is not supported at this time.');

            return false;
        }

        $result = (new CreateSchemaGenerator($this, $this->filesystem, $this->utility))
            ->start($schema_name, $force);

        return $this->tipDone($result);
    }
}
