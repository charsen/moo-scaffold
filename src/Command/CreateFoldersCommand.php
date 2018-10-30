<?php
namespace Charsen\Scaffold\Command;

use Charsen\Scaffold\Generator\CreateFoldersGenerator;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Create Laravel Scaffold\'s Folders
 *
 * @author   Charsen <780537@gmail.com>
 */
class CreateFoldersCommand extends Command
{
    /**
     * The console command title.
     *
     * @var string
     */
    protected $title = 'Laravel Scaffold Create Folders';

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'scaffold:folders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create Laravel Scaffold\'s Folders';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->alert($this->title);

        $result = (new CreateFoldersGenerator($this, $this->filesystem, $this->utility))->start();

        $this->info('done!');
    }
}
