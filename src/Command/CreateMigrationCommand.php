<?php
namespace Charsen\Scaffold\Command;

use Charsen\Scaffold\Generator\CreateMigrationGenerator;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Create Database Migration Command
 *
 * @author   Charsen <780537@gmail.com>
 */
class CreateMigrationCommand extends Command
{
    /**
     * The console command title.
     *
     * @var string
     */
    protected $title = 'Create Database Migration Command';

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'scaffold:migration';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create Database Migration Command';

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['schema_name', InputArgument::REQUIRED, 'The name of the schema. (Ex: Personnels)'],
        ];
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            [
                'force',
                '-f',
                InputOption::VALUE_OPTIONAL,
                'Overwrite Migration File.',
                false,
            ],
        ];
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->alert($this->title);
        $schema_name = $this->argument('schema_name');
        $force       = $this->option('force') === null;

        $result = (new CreateMigrationGenerator($this, $this->filesystem, $this->utility))
            ->start($schema_name, $force);

        $this->info('done!');
    }
}
