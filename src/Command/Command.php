<?php
namespace Charsen\Scaffold\Command;

use Charsen\Scaffold\Utility;
use Illuminate\Console\Command as BaseCommand;
use Illuminate\Filesystem\Filesystem;

/**
 * Command
 *
 * @author Charsen https://github.com/charsen
 */
class Command extends BaseCommand
{
    protected $filesystem;

    protected $utility;

    /**
     * Create a new command instance.
     *
     * @param \Illuminate\Filesystem\Filesystem $filesystem
     * @param \Charsen\Scaffold\Utility         $utility
     */
    public function __construct(Filesystem $filesystem, Utility $utility)
    {
        parent::__construct();

        $this->filesystem = $filesystem;
        $this->utility    = $utility;
    }

    /**
     * 提示执行的命令
     *
     * @param $command
     */
    protected function tipCallCommand($command)
    {
        $this->warn("\n***     {$command}     ***");
    }

    /**
     * 提示执行完成
     */
    protected function tipDone($result = true)
    {
        if ($result)
        {
            $this->info("\n √ done!");
        }
        else
        {
            $this->error("\n x failed!");
        }
    }
}
