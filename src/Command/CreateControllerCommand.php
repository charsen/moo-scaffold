<?php
namespace Charsen\Scaffold\Command;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Charsen\Scaffold\Generator\FreshStorageGenerator;
use Charsen\Scaffold\Generator\CreateControllerGenerator;

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
                'trait',
                '-t',
                InputOption::VALUE_OPTIONAL,
                'Build Trait File.',
                false,
            ],
            [
                'force',
                '-f',
                InputOption::VALUE_OPTIONAL,
                'Overwrite Controller File.',
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

        // 创建 BaseActionTriat
        (new CreateControllerGenerator($this, $this->filesystem, $this->utility))->buildBaseAction();

        $schema_name = $this->argument('schema_name');
        if (empty($schema_name))
        {
            $file_names  = $this->utility->getSchemaNames();
            $schema_name = $this->choice('What is schema name?', $file_names);
        }

        $trait       = $this->option('trait') === null;
        $force       = $this->option('force') === null;
        $fresh       = $this->option('fresh') === null;

        if ($fresh)
        {
            $this->tipCallCommand('scaffold:fresh');
            $result = (new FreshStorageGenerator($this, $this->filesystem, $this->utility))->start();
            $this->tipCallCommand('scaffold:controller');
        }

        if ($trait)
        {
            $contollers = $this->utility->getControllers();
            $controller_name = $this->choice('What is controller name?', array_keys($contollers));
            $result = (new CreateControllerGenerator($this, $this->filesystem, $this->utility))
                        ->buildTrait($controller_name, $contollers[$controller_name], $force);
        }
        else
        {
            $result = (new CreateControllerGenerator($this, $this->filesystem, $this->utility))
                        ->start($schema_name, $force);
        }

        $this->tipDone();
    }
}
