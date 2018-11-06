<?php
namespace Charsen\Scaffold\Command;

use Charsen\Scaffold\Generator\CreateControllerGenerator;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Create Controller Command
 *
 * @author Charsen https://github.com/charsen
 */
class CreateControllerCommand extends Command
{
    /**
     * The console command title.
     *
     * @var string
     */
    protected $title = 'Create Controller Command';

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'scaffold:controller';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create Controller Command';

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['schema_name', InputArgument::OPTIONAL, 'The name of the schema. (Ex: personnels)'],
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
                'Overwrite Controller File.',
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
        
        $force       = $this->option('force') === null;

        $result = (new CreateControllerGenerator($this, $this->filesystem, $this->utility))
            ->start($schema_name, $force);
    
        $this->tipDone();
    }
}
