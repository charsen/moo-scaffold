<?php

namespace Mooeen\Scaffold\Command;

use Mooeen\Scaffold\Generator\InitGenerator;
use Symfony\Component\Console\Input\InputArgument;

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
    protected $name = 'moo:init';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Init Laravel Scaffold';

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['author', InputArgument::REQUIRED, 'Your Name. (Ex: Charsen)'],
        ];
    }

    /**
     * Execute the console command.
     */
    public function handle(): bool
    {
        $this->alert($this->title);

        $author = $this->argument('author');

        $result = (new InitGenerator($this, $this->filesystem, $this->utility))
            ->start($author);

        return $this->tipDone($result);
    }
}
