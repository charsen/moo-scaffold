<?php
namespace Charsen\Scaffold\Command;

use Charsen\Scaffold\Generator\CreateApiGenerator;
use Charsen\Scaffold\Generator\FreshStorageGenerator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Create Api Command
 *
 * @author Charsen https://github.com/charsen
 */
class CreateApiCommand extends Command
{
    /**
     * The console command title.
     *
     * @var string
     */
    protected $title = 'Create Api Command';

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'scaffold:api';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create Api Command';

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['namespace', InputArgument::OPTIONAL, 'The name of the namespace. (Ex: Enterprise)'],
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
                'Overwrite Api Files.',
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
    
        $namespace = $this->argument('namespace');
        if (empty($namespace))
        {
            $namespaces = $this->utility->getControllerNamespaces();
            $namespace  = $this->choice('What is namespace?', $namespaces);
        }
        else
        {
            $namespace = ucfirst($namespace);
        }
        
        $force     = $this->option('force') === null;
        $fresh     = $this->option('fresh') === null;
        if ($fresh)
        {
            $this->tipCallCommand('scaffold:fresh');
            $result = (new FreshStorageGenerator($this, $this->filesystem, $this->utility))->start();
    
            $this->tipCallCommand('scaffold:api');
        }

        $result = (new CreateApiGenerator($this, $this->filesystem, $this->utility))
            ->start($namespace, $force);
    
        $this->tipDone();
    }
}
