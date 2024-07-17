<?php

namespace Mooeen\Scaffold\Command;

use Mooeen\Scaffold\Generator\CreateModelGenerator;
use Mooeen\Scaffold\Generator\FreshStorageGenerator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Create Model Command
 *
 * @author Charsen https://github.com/charsen
 */
class CreateModelCommand extends Command
{
    /**
     * The console command title.
     *
     * @var string
     */
    protected $title = 'Create Model Command';

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'moo:model';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create Model Command';

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['schema_name', InputArgument::OPTIONAL, 'The name of the schema. (Ex: Personnels)'],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            [
                'factory',
                '-F',
                InputOption::VALUE_OPTIONAL,
                'Build The Factory File & Update DatabaseSeeder.',
                false,
            ],
            [
                'force',
                '-f',
                InputOption::VALUE_OPTIONAL,
                'Overwrite Model File.',
                false,
            ],
        ];
    }

    /**
     * Execute the console command.
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function handle(): bool
    {
        $this->alert($this->title);

        $this->checkRunning();

        $schema_name = $this->argument('schema_name');
        if (empty($schema_name)) {
            $file_names  = $this->utility->getSchemaNames();
            $schema_name = $this->choice('Which schema?', $file_names);
        }

        $this->tipCallCommand('moo:fresh');
        (new FreshStorageGenerator($this, $this->filesystem, $this->utility))->start();

        $this->tipCallCommand('moo:model ' . $schema_name);

        $force   = $this->option('force')   !== false;
        $factory = $this->option('factory') !== false;

        $result = (new CreateModelGenerator($this, $this->filesystem, $this->utility))
            ->start($schema_name, $force, $factory);

        if ($factory) {
            if ($this->confirm("Do you want to Execute 'artisan db:seed' ?", 'yes')) {
                $this->tipCallCommand('db:seed');
                $this->call('db:seed');
            }
        }

        return $this->tipDone($result);
    }
}
