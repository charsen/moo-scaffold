<?php
namespace Charsen\Scaffold\Command;

use Charsen\Scaffold\Generator\CreateApiGenerator;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Create Api Command
 *
 * @author   Charsen <780537@gmail.com>
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
            ['namespace', InputArgument::REQUIRED, 'The name of the namespace. (Ex: Enterprise)'],
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

        $namespace = ucfirst($this->argument('namespace'));
        $force     = $this->option('force') === null;

        $result = (new CreateApiGenerator($this, $this->filesystem, $this->utility))
            ->start($namespace, $force);

        $this->info('done!');
    }
}
