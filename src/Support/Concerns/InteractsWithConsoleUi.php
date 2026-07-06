<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Support\Concerns;

use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Console\View\Components\Factory;
use Mooeen\Scaffold\Support\ConsoleUi;
use Symfony\Component\Console\Output\OutputInterface;

trait InteractsWithConsoleUi
{
    private ?ConsoleUi $consoleUi = null;

    abstract protected function getConsoleTarget(): ConsoleCommand|Factory|OutputInterface;

    protected function console(): ConsoleUi
    {
        return $this->consoleUi ??= new ConsoleUi($this->getConsoleTarget());
    }
}
