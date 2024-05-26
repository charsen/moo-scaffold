<?php

namespace Mooeen\Scaffold\Command;

use Mooeen\Scaffold\Generator\CreateApiGenerator;
use Mooeen\Scaffold\Generator\CreateControllerGenerator;
use Mooeen\Scaffold\Generator\CreateMigrationGenerator;
use Mooeen\Scaffold\Generator\CreateModelGenerator;
use Mooeen\Scaffold\Generator\FreshStorageGenerator;
use Mooeen\Scaffold\Generator\UpdateAuthorizationGenerator;
use Mooeen\Scaffold\Generator\UpdateMultilingualGenerator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Yaml\Yaml;

/**
 * Free : Release your hands
 *
 * @author Charsen https://github.com/charsen
 */
class FreeCommand extends Command
{
    /**
     * The console command title.
     *
     * @var string
     */
    protected $title = 'Free : Release your hands';

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'moo:free';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create Controllers, Models, Migrations ...';

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['schema_name', InputArgument::OPTIONAL, 'The name of the schema. (Ex: Personnels)'],
        ];
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            [
                'clean',
                '-c',
                InputOption::VALUE_OPTIONAL,
                'Overwrite All Storage Files.',
                false,
            ],
            [
                'force',
                '-f',
                InputOption::VALUE_OPTIONAL,
                'Overwrite Models/Controllers Files.',
                false,
            ],
        ];
    }

    /**
     * Execute the console command.
     *
     * @return void
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function handle()
    {
        $this->alert($this->title);

        $schema_name = $this->argument('schema_name');
        if (empty($schema_name)) {
            $file_names  = $this->utility->getSchemaNames();
            $schema_name = $this->choice('What is schema name?', $file_names);
        }

        $clean = $this->option('clean') === null;
        $force = $this->option('force') === null;

        $schema_path = $this->utility->getDatabasePath('schema');
        $yaml        = new Yaml;
        $data        = $yaml::parseFile($schema_path . $schema_name . '.yaml');

        $this->tipCallCommand('moo:fresh');
        (new FreshStorageGenerator($this, $this->filesystem, $this->utility))->start($clean);

        $this->tipCallCommand('moo:model');
        (new CreateModelGenerator($this, $this->filesystem, $this->utility))->start($schema_name, $force);

        $this->tipCallCommand('moo:controller');
        (new CreateControllerGenerator($this, $this->filesystem, $this->utility))->start($schema_name, $force);

        $this->tipCallCommand('moo:api');
        //$namespace     = "{$data['package']['folder']}/{$data['module']['folder']}";
        $namespace = "{$data['module']['folder']}";
        (new CreateApiGenerator($this, $this->filesystem, $this->utility))->start($namespace, false, $force);

        $this->tipCallCommand('moo:i18n');
        (new UpdateMultilingualGenerator($this, $this->filesystem, $this->utility))->start();

        $this->tipCallCommand('moo:auth');
        (new UpdateAuthorizationGenerator($this, $this->filesystem, $this->utility))->start();

        $this->tipCallCommand('moo:migration');
        (new CreateMigrationGenerator($this, $this->filesystem, $this->utility))->start($schema_name);

        if ($this->confirm("Do you want to Execute 'artisan migrate' ?", 'yes')) {
            $this->tipCallCommand('migrate');
            $this->call('migrate');
        }

        $this->tipDone();
    }
}
