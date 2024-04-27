<?php

namespace Mooeen\Scaffold\Command;

use Illuminate\Console\Command as BaseCommand;
use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Utility;

/**
 * Command
 *
 * @author Charsen https://github.com/charsen
 */
class Command extends BaseCommand
{
    protected Filesystem $filesystem;

    protected Utility $utility;

    /**
     * Create a new command instance.
     */
    public function __construct(Filesystem $filesystem, Utility $utility)
    {
        parent::__construct();

        $this->filesystem = $filesystem;
        $this->utility    = $utility;
    }

    /**
     * 提示执行的命令
     */
    protected function tipCallCommand($command): void
    {
        $this->warn("\n******************     {$command}     ******************");
    }

    /**
     * 提示执行完成
     */
    protected function tipDone($result = true): bool
    {
        if ($result) {
            $this->info("\n √ done!");
        } else {
            $this->error("\n x failed!");
        }

        return true;
    }
}
