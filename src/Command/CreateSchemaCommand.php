<?php
namespace Charsen\Scaffold\Command;

use Charsen\Scaffold\Generator\CreateSchemaGenerator;
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
    protected $name = 'scaffold:schema';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new module schema';

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
            ],
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
            ['schema_name', InputArgument::REQUIRED, 'The name of the schema. (Ex: Personnels)'],
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
        $force       = $this->option('force') === null;
        
        if (strstr($schema_name, '/'))
        {
            return $this->error('Multi-level directory is not supported at this time.');
        }

        $result = (new CreateSchemaGenerator($this, $this->filesystem, $this->utility))
            ->start($schema_name, $force);
    
        $this->tipDone();
    }
}
