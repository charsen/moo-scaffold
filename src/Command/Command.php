<?php

namespace Mooeen\Scaffold\Command;

use Illuminate\Console\Command as BaseCommand;
use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Output\ConsoleOutput;

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
     * 是否在非正式环境中关闭命令行功能
     */
    protected function checkRunning(): void
    {
        if ($this->utility->getConfig('only_in_local') && ! app()->isLocal()) {
            $output = new ConsoleOutput();
            $style  = new OutputFormatterStyle('yellow');

            $output->getFormatter()->setStyle('warning', $style);
            $string = 'Warning: moo-scaffold command model is closed.';

            $output->writeln("\n<warning>$string</warning>\n");
            exit;
        }
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
