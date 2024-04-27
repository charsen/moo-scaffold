<?php

namespace Mooeen\Scaffold\Command;

use Mooeen\Scaffold\Generator\FreshStorageGenerator;
use Symfony\Component\Console\Input\InputOption;

/**
 * Fresh Database Storage Command
 *
 * @author Charsen https://github.com/charsen
 */
class FreshStorageCommand extends Command
{
    /**
     * The console command title.
     *
     * @var string
     */
    protected $title = 'Fresh Schema Storage Command';

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'moo:fresh';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fresh Schema Storage Command';

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            [
                'clean',
                '-c',
                InputOption::VALUE_OPTIONAL,
                'Rebuild All Storage Files.',
                false,
            ],
        ];
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->alert($this->title);

        $clean  = $this->option('clean') === null;
        $result = (new FreshStorageGenerator($this, $this->filesystem, $this->utility))
            ->start($clean);

        $this->tipDone($result);
    }
}
