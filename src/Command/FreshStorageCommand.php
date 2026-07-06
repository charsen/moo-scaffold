<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2025-07-16 11:06
 * @Description: Fresh File Storage Command
 */

namespace Mooeen\Scaffold\Command;

use Mooeen\Scaffold\Generator\FreshStorageGenerator;
use Symfony\Component\Console\Input\InputOption;

class FreshStorageCommand extends Command
{
    protected bool $requiresLocalEnvironment = false;

    protected string $title = 'Fresh Schema Storage Command';

    protected $name = 'moo:fresh';

    protected $description = 'Parse YAML schema files and refresh storage/scaffold cache';

    protected function getOptions(): array
    {
        return [
            ['clean', '-c', InputOption::VALUE_OPTIONAL, 'Clean storage directory before rebuilding all cache files.', false],
        ];
    }

    public function handle(): void
    {
        $this->showTitle();

        if (! $this->checkRunning()) {
            return;
        }

        $clean  = $this->option('clean') === null;
        $result = (new FreshStorageGenerator($this, $this->filesystem, $this->utility))
            ->start($clean);

        $this->tipDone($result);
    }
}
