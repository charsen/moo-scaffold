<?php
namespace Charsen\Scaffold\Command;

use Charsen\Scaffold\Generator\CreateDatabaseSchemaGenerator;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Create a new database schema
 *
 * @author   Charsen <780537@gmail.com>
 */
class CreateDatabaseSchemaCommand extends Command
{
    /**
     * The console command title.
     *
     * @var string
     */
    protected $title = 'Laravel Scaffold New Databse Schema';

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'scaffold:db:schema';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new database schema';

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
                'Overwrite the schema file.',
                false,
            ]
        ];
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['file_name', InputArgument::REQUIRED, 'The name of the schema. (Ex: Personnel)'],
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

        $file_name = $this->argument('file_name');
        $format    = $this->option('force') === NULL;

        $result = (new CreateDatabaseSchemaGenerator($this, $this->filesystem, $this->utility))
                   ->start($file_name, $format);

        $this->info('done!');
    }
}
