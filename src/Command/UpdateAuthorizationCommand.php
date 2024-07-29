<?php

namespace Mooeen\Scaffold\Command;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Mooeen\Scaffold\Generator\UpdateAuthorizationGenerator;
use Mooeen\Scaffold\RouterTool;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Update ACL Command
 *
 * @author Charsen https://github.com/charsen
 */
class UpdateAuthorizationCommand extends Command
{
    /**
     * The console command title.
     */
    protected string $title = 'Update Authorization Command';

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'moo:auth-new';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update Authorization Files';

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
     */
    protected function getArguments(): array
    {
        return [
            ['app', InputArgument::OPTIONAL, 'The name of the app. (Ex: admin)'],
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
            ['route', '-r', InputOption::VALUE_OPTIONAL, 'Display routes.', false],
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

        $this->checkRunning();

        $apps = $this->utility->getConfig('controller');
        if (empty($this->argument('app'))) {
            $app_keys = array_keys($apps);
            $app      = $this->choice('which app?', $app_keys);
        } else {
            $app = $this->argument('app');
        }

        if (! isset($apps[$app])) {
            $this->components->error("The '{$app}' is not configured, Please check controller configuration.");

            return;
        }

        $tool   = new RouterTool($app, '', 'action', $this->utility, $this->router);
        $routes = $tool->init();
        $routes = $tool->stortActions($routes);

        $result = (new UpdateAuthorizationGenerator($this, $this->filesystem, $this->utility))->start($app, $routes);

        if ($this->option('route') === null) {
            $tool->displayRoutes($routes);
        }

        $this->info("\n √ [{$app}] is updated!");
    }
}
