<?php
namespace Charsen\Scaffold\Command;

use Charsen\Scaffold\Generator\FreshStorageGenerator;
use Charsen\Scaffold\Generator\UpdateMultilingualGenerator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Create i18n Command
 *
 * @author Charsen https://github.com/charsen
 */
class UpdateMultilingualCommand extends Command
{
    /**
     * The console command title.
     *
     * @var string
     */
    protected $title = 'Update Multilingual Command';

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'scaffold:i18n';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update Multilingual Command';

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
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

        $fresh       = $this->option('fresh') === null;
        if ($fresh)
        {
            $this->tipCallCommand('scaffold:fresh');
            (new FreshStorageGenerator($this, $this->filesystem, $this->utility))->start();

            $this->tipCallCommand('scaffold:i18n');
        }

        $result = (new UpdateMultilingualGenerator($this, $this->filesystem, $this->utility))
            ->start();

        $this->tipDone($result);
    }
}
