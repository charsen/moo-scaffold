<?php
namespace Charsen\Scaffold\Command;

use Charsen\Scaffold\Generator\FreshStorageGenerator;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Fresh Database Storage Command
 *
 * @author   Charsen <780537@gmail.com>
 */
class FreshStorageCommand extends Command
{
    /**
     * The console command title.
     *
     * @var string
     */
    protected $title = 'Fresh Database Storage Command';

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'scaffold:fresh';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fresh Database Storage Command';

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

        $clean  = $this->option('clean') === null;
        $result = (new FreshStorageGenerator($this, $this->filesystem, $this->utility))
            ->start($clean);

        $this->info('done!');
    }
}
