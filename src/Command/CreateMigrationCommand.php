<?php
namespace Charsen\Scaffold\Command;

use Charsen\Scaffold\Generator\CreateMigrationGenerator;
use Charsen\Scaffold\Generator\FreshStorageGenerator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Create Database Migration Command
 *
 * @author Charsen https://github.com/charsen
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
            ['schema_name', InputArgument::OPTIONAL, 'The name of the schema. (Ex: Personnels)'],
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
                'migrate',
                '-m',
                InputOption::VALUE_OPTIONAL,
                'Class the artisan:migrate command.',
                false,
            ],
            [
                'fresh',
                '--fresh',
                InputOption::VALUE_OPTIONAL,
                'Fresh all cache files.',
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
        if (empty($schema_name))
        {
            $file_names  = $this->utility->getSchemaNames();
            $schema_name = $this->choice('What is schema name?', $file_names);
        }

        $migrate     = $this->option('migrate') === null;
        $fresh       = $this->option('fresh') === null;
        if ($fresh)
        {
            $this->tipCallCommand('scaffold:fresh');
            $result = (new FreshStorageGenerator($this, $this->filesystem, $this->utility))->start();

            $this->tipCallCommand('scaffold:migration');
        }

        $result = (new CreateMigrationGenerator($this, $this->filesystem, $this->utility))
            ->start($schema_name);

        if ($migrate)
        {
            $this->tipCallCommand('migrate');
            $this->call('migrate');
        }
        else
        {
            if ($this->confirm("Do you want to Execute 'artisan migrate' ?", 'yes'))
            {
                $this->tipCallCommand('migrate');
                $this->call('migrate');
            }
        }

        $this->tipDone($result);
    }
}
