<?php
namespace Charsen\Scaffold\Command;

use Charsen\Scaffold\Generator\UpdateAuthorizationGenerator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Update ACL Command
 *
 * @author Charsen https://github.com/charsen
 */
class UpdateAuthorizationCommand extends Command
{
    /**
     * The console command title.
     *
     * @var string
     */
    protected $title = 'Update Authorization Command';

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'scaffold:auth';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update Authorization Files';
    
    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->alert($this->title);
    
        $result = (new UpdateAuthorizationGenerator($this, $this->filesystem, $this->utility))->start();
    
        $this->tipDone();
    }
}
