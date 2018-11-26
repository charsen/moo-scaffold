<?php

namespace Charsen\Scaffold\Command;

use Charsen\Scaffold\Generator\CreateControllerGenerator;
use Charsen\Scaffold\Generator\CreateMigrationGenerator;
use Charsen\Scaffold\Generator\CreateModelGenerator;
use Charsen\Scaffold\Generator\CreateRepositoryGenerator;
use Charsen\Scaffold\Generator\FreshStorageGenerator;
use Charsen\Scaffold\Generator\UpdateMultilingualGenerator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Free : Release your hands
 *
 * @author Charsen https://github.com/charsen
 */
class FreeCommand extends Command
{
    /**
     * The console command title.
     *
     * @var string
     */
    protected $title = 'Free : Release your hands';
    
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'scaffold:free';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create Controllers, Models, Repositories, Migrations ...';
    
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
                'clean',
                '-c',
                InputOption::VALUE_OPTIONAL,
                'Overwrite All Storage Files.',
                false,
            ],
            [
                'force',
                '-f',
                InputOption::VALUE_OPTIONAL,
                'Overwrite Model File.',
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
        if (empty($schema_name)) {
            $file_names  = $this->utility->getSchemaNames();
            $schema_name = $this->choice('What is schema name?', $file_names);
        }
        
        $clean = $this->option('clean') === null;
        $force = $this->option('force') === null;
        
        $this->tipCallCommand('scaffold:fresh');
        (new FreshStorageGenerator($this, $this->filesystem, $this->utility))->start($clean);
        
        $this->tipCallCommand('scaffold:model');
        (new CreateModelGenerator($this, $this->filesystem, $this->utility))->start($schema_name, $force);
        
        //$this->tipCallCommand('scaffold:controller');
        //(new CreateControllerGenerator($this, $this->filesystem, $this->utility))->start($schema_name, $force);
        
        $this->tipCallCommand('scaffold:repository');
        (new CreateRepositoryGenerator($this, $this->filesystem, $this->utility))->start($schema_name, $force);
        
        $this->tipCallCommand('scaffold:i18n');
        (new UpdateMultilingualGenerator($this, $this->filesystem, $this->utility))->start();
        
        $this->tipCallCommand('scaffold:migration');
        (new CreateMigrationGenerator($this, $this->filesystem, $this->utility))->start($schema_name, $force);
    
        if ($this->confirm("Do you want to Execute 'artisan migrate' ?", 'yes'))
        {
            $this->tipCallCommand('migrate');
            $this->call('migrate');
        }
        
        $this->tipDone();
    }
}
