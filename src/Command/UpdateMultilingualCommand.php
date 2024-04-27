<?php

namespace Mooeen\Scaffold\Command;

use Mooeen\Scaffold\Generator\FreshStorageGenerator;
use Mooeen\Scaffold\Generator\UpdateMultilingualGenerator;

/**
 * Create i18n Command
 *
 * @author Charsen https://github.com/charsen
 */
class UpdateMultilingualCommand extends Command
{
    /**
     * The console command title.
     */
    protected string $title = 'Update Multilingual Command';

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'moo:i18n';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update Multilingual Command';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->alert($this->title);

        $this->tipCallCommand('moo:fresh');
        (new FreshStorageGenerator($this, $this->filesystem, $this->utility))->start();

        $this->tipCallCommand('moo:i18n ');

        $result = (new UpdateMultilingualGenerator($this, $this->filesystem, $this->utility))->start();

        $this->tipDone($result);
    }
}
