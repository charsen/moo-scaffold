<?php

namespace Mooeen\Scaffold\Command;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use Mooeen\Scaffold\Generator\CreateApiGenerator;
use Mooeen\Scaffold\Generator\FreshStorageGenerator;
use Mooeen\Scaffold\RouterTool;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Terminal;

/**
 * Create Api Command
 *
 * @author Charsen https://github.com/charsen
 */
class CreateApiCommand extends Command
{
    /**
     * The console command title.
     */
    protected string $title = 'Create Api Command';

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'moo:api';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create Api Command';

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
            ['namespace', InputArgument::OPTIONAL, 'The name of the namespace. (Ex: System)'],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['sort', null, InputOption::VALUE_OPTIONAL, 'The column (domain, method, uri, name, action) to sort by', 'uri'],
            ['route', '-r', InputOption::VALUE_OPTIONAL, 'Display routes.', false],
        ];
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->alert($this->title);

        $this->checkRunning();

        $apps      = $this->utility->getConfig('controller');
        if (empty($this->argument('app'))) {
            $app_keys = array_keys($apps);
            $app  = $this->choice('which app?', $app_keys);
        } else {
            $app = $this->argument('app');

            if (! isset($apps[$app])) {
                $this->components->error("The '{$app}' is not configured, Please check controller configuration.");

                return;
            }
        }

        $namespace = $this->argument('namespace');
        if (empty($namespace)) {
            $namespaces = $this->utility->getControllerNamespaces($app);
            $namespace  = $this->choice('which namespace?', $namespaces);
        } else {
            $namespace = $namespace === '/' ? '' : ucfirst($namespace);
        }

        $this->tipCallCommand('moo:fresh');
        (new FreshStorageGenerator($this, $this->filesystem, $this->utility))->start();

        $this->tipCallCommand('moo:api');

        $tool = new RouterTool($app, $namespace, 'uri', $this->utility, $this->router);
        $routes = $tool->init();

        $result = (new CreateApiGenerator($this->components, $this->filesystem, $this->utility))
            ->start($app, $namespace, $routes);

        if ($this->option('route') === null) {
            $tool->displayRoutes($routes);
        }

        $this->tipDone($result);
    }
}
