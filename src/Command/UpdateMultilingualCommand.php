<?php
namespace Charsen\Scaffold\Command;

use Charsen\Scaffold\Generator\UpdateMultilingualGenerator;
use InvalidArgumentException;
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
    protected $title = 'Create i18n Command';

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
    protected $description = 'Create i18n Command';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->alert($this->title);
      

        $result = (new UpdateMultilingualGenerator($this, $this->filesystem, $this->utility))
            ->start();
    
        $this->tipDone();
    }
}
