<?php
namespace Charsen\Scaffold\Command;

use Charsen\Scaffold\Generator\InitGenerator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Init Laravel Scaffold
 *
 * @author Charsen https://github.com/charsen
 */
class InitCommand extends Command
{
    /**
     * The console command title.
     *
     * @var string
     */
    protected $title = 'Init Laravel Scaffold';

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'scaffold:init';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Init Laravel Scaffold';
    
    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['author', InputArgument::REQUIRED, 'Your Name. (Ex: Charsen)'],
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
    
        $author = $this->argument('author');
        
        $result = (new InitGenerator($this, $this->filesystem, $this->utility))->start($author);
    
        $this->tipDone();
    }
}
