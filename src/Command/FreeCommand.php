<?php

namespace Mooeen\Scaffold\Command;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use Mooeen\Scaffold\Generator\CreateApiGenerator;
use Mooeen\Scaffold\Generator\CreateControllerGenerator;
use Mooeen\Scaffold\Generator\CreateMigrationGenerator;
use Mooeen\Scaffold\Generator\CreateModelGenerator;
use Mooeen\Scaffold\Generator\FreshStorageGenerator;
use Mooeen\Scaffold\Generator\UpdateAuthorizationGenerator;
use Mooeen\Scaffold\Generator\UpdateMultilingualGenerator;
use Mooeen\Scaffold\RouterTool;
use Mooeen\Scaffold\Utility;
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
     * The router instance.
     */
    protected Router $router;

    /**
     * Create a new route command instance.
     *
     * @return void
     */
    public function __construct(Filesystem $filesystem, Utility $utility, Router $router)
    {
        parent::__construct($filesystem, $utility);

        $this->router = $router;
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return [
            ['app', InputArgument::OPTIONAL, 'The name of the app. (Ex: admin)'],
            ['schema', InputArgument::OPTIONAL, 'The name of the schema. (Ex: Personnels)'],
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
            ['force', '-f', InputOption::VALUE_OPTIONAL, 'Overwrite Models/Controllers Files.', false],
            ['api', '-a', InputOption::VALUE_OPTIONAL, 'Post/Update Api to debugging tool.', false],
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

        $this->checkRunning();

        $force = $this->option('force') === null;
        $api   = $this->option('api')   === null;

        $apps = $this->utility->getConfig('controller');
        if (empty($this->argument('app'))) {
            $app_keys = array_keys($apps);
            $app      = $this->choice('which app?', $app_keys);
        } else {
            $app = $this->argument('app');
        }

        if (! isset($apps[$app])) {
            $this->components->error("The '{$app}' is not configured, Please check again.");

            return;
        }

        $file_names = $this->utility->getSchemaNames();
        if (empty($this->argument('schema'))) {
            $schema_name = $this->choice('What schema?', $file_names);
        } else {
            $schema_name = $this->argument('schema');
        }

        if (! in_array($schema_name, $file_names)) {
            $this->components->error("The '{$schema_name}' is not exists, Please check again.");

            return;
        }

        $schema_path = $this->utility->getDatabasePath('schema');
        $yaml        = new Yaml;
        $data        = $yaml::parseFile($schema_path . $schema_name . '.yaml');

        $this->tipCallCommand('moo:fresh');
        (new FreshStorageGenerator($this, $this->filesystem, $this->utility))->start(true, true);

        $this->tipCallCommand('moo:model');
        (new CreateModelGenerator($this, $this->filesystem, $this->utility))->start($schema_name, $force);

        $this->tipCallCommand('moo:controller');
        (new CreateControllerGenerator($this, $this->filesystem, $this->utility))->start($schema_name, $force);

        // 附加 -a 选项时再执行
        if ($api) {
            $this->tipCallCommand('moo:api');
            $routes = (new RouterTool($app, $schema_name, 'uri', $this->utility, $this->router))->init();
            (new CreateApiGenerator($this, $this->filesystem, $this->utility))->start($app, $schema_name, $routes);
        }

        $this->tipCallCommand('moo:i18n');
        (new UpdateMultilingualGenerator($this, $this->filesystem, $this->utility))->start();

        $this->tipCallCommand('moo:auth');
        $tool   = new RouterTool($app, '', 'action', $this->utility, $this->router);
        $routes = $tool->init();
        $routes = $tool->stortActions($routes);
        (new UpdateAuthorizationGenerator($this, $this->filesystem, $this->utility))->start($app, $routes);

        $this->tipCallCommand('moo:migration');
        (new CreateMigrationGenerator($this, $this->filesystem, $this->utility))->start($schema_name);

        if ($this->confirm("Do you want to Execute 'artisan migrate' ?", 'yes')) {
            $this->tipCallCommand('migrate');
            $this->call('migrate');
        }

        $this->tipDone();
    }
}
