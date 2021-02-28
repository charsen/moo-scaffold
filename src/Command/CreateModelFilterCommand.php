<?php
namespace Charsen\Scaffold\Command;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Charsen\Scaffold\Generator\CreateFilterGenerator;

/**
 * Create Model Filter Command
 *
 * @author Charsen https://github.com/charsen
 */
class CreateModelFilterCommand extends Command
{
    /**
     * The console command title.
     *
     * @var string
     */
    protected $title = 'Create Model Filter Command';

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'scaffold:filter';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create Model Filter Command';

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['folder', InputArgument::OPTIONAL, 'The folder path of Model Class. (Ex: Content)'],
            ['class_name', InputArgument::OPTIONAL, 'The class name of the model. (Ex: Article)'],
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

        $folder     = $this->argument('folder');
        $model_name = $this->argument('class_name');

        $force       = $this->option('force') === null;

        // check BaseFilter
        (new CreateFilterGenerator($this, $this->filesystem, $this->utility))
                        ->buildBaseFilter();

        // create model filter
        $result = (new CreateFilterGenerator($this, $this->filesystem, $this->utility))
                        ->start($folder, $model_name, $force);

        $this->tipDone($result);
    }
}
