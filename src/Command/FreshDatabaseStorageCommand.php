<?php
namespace Charsen\Scaffold\Command;

use Charsen\Scaffold\Generator\FreshDatabaseStorageGenerator;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fresh Database Storage Command
 *
 * @author   Charsen <780537@gmail.com>
 */
class FreshDatabaseStorageCommand extends Command
{
    /**
     * The console command title.
     *
     * @var string
     */
    protected $title = 'Laravel Scaffold Fresh Database Storage Command';

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'scaffold:db:fresh';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fresh Database Storage Command';

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            [
                'force',
                '-f',
                InputOption::VALUE_OPTIONAL,
                'Overwrite All Storage Files.',
                false,
            ],
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

        $format = $this->option('force') === null;
        $result = (new FreshDatabaseStorageGenerator($this, $this->filesystem, $this->utility))
            ->start($format);

        $this->info('done!');
    }
}
