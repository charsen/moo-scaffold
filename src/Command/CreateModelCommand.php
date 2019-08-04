<?php

namespace Charsen\Scaffold\Command;

use Charsen\Scaffold\Generator\CreateControllerGenerator;
use Charsen\Scaffold\Generator\CreateMigrationGenerator;
use Charsen\Scaffold\Generator\CreateModelGenerator;
use Charsen\Scaffold\Generator\CreateRepositoryGenerator;
use Charsen\Scaffold\Generator\FreshStorageGenerator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Create Model Command
 *
 * @author Charsen https://github.com/charsen
 */
class CreateModelCommand extends Command
{
    /**
     * The console command title.
     *
     * @var string
     */
    protected $title = 'Create Model Command';

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'scaffold:model';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create Model Command';

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
                'force',
                '-f',
                InputOption::VALUE_OPTIONAL,
                'Overwrite Model File.',
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
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
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

        $force       = $this->option('force') === null;
        $fresh       = $this->option('fresh') === null;

        if ($fresh)
        {
            $this->tipCallCommand('scaffold:fresh');
            $result = (new FreshStorageGenerator($this, $this->filesystem, $this->utility))->start();

            $this->tipCallCommand('scaffold:model');
        }

        $result = (new CreateModelGenerator($this, $this->filesystem, $this->utility))
            ->start($schema_name, $force);

        $this->tipDone();
    }
}
