<?php

namespace Mooeen\Scaffold\Command;

use Mooeen\Scaffold\Generator\CreateMigrationGenerator;
use Mooeen\Scaffold\Generator\FreshStorageGenerator;
use Symfony\Component\Console\Input\InputArgument;

/**
 * Create Database Migration Command
 *
 * @author Charsen https://github.com/charsen
 */
class CreateMigrationCommand extends Command
{
    /**
     * The console command title.
     */
    protected string $title = 'Create Database Migration Command';

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'moo:migration';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create Database Migration Command';

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['schema_name', InputArgument::OPTIONAL, 'The name of the schema. (Ex: System)'],
        ];
    }

    /**
     * Execute the console command.
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

        $this->tipCallCommand('moo:migration ' . $schema_name);
        $result = (new CreateMigrationGenerator($this, $this->filesystem, $this->utility))->start($schema_name);

        if ($this->confirm("Do you want to Execute 'artisan migrate' ?", 'yes')) {
            $this->tipCallCommand('migrate');
            $this->call('migrate');
        }

        return $this->tipDone($result);
    }
}
