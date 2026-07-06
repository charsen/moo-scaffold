<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2025-08-29 17:38
 * @Description: Update ACL Command
 */

namespace Mooeen\Scaffold\Command;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use Mooeen\Scaffold\Generator\UpdateAuthorizationGenerator;
use Mooeen\Scaffold\RouterTool;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class UpdateAuthorizationCommand extends Command
{
    protected string $title = 'Update Authorization Command';

    protected $name = 'moo:auth';

    protected $description = 'Rebuild ACL config, language files, and visualization from real routes';

    protected Router $router;

    public function __construct(Filesystem $filesystem, Utility $utility, Router $router)
    {
        parent::__construct($filesystem, $utility);

        $this->router = $router;
    }

    protected function getArguments(): array
    {
        return [
            ['app', InputArgument::OPTIONAL, 'The name of the app. (Ex: admin)'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            ['route', '-r', InputOption::VALUE_OPTIONAL, 'Display matched routes in terminal after generation.', false],
        ];
    }

    public function handle(): void
    {
        $this->showTitle();

        if (! $this->checkRunning()) {
            return;
        }

        $apps = $this->utility->getConfig('controller');
        $app  = $this->argument('app') ?: $this->chooseApp($apps);

        if (! isset($apps[$app])) {
            $this->reportAppNotConfigured($app);

            return;
        }

        $tool   = new RouterTool($app, '', 'action', $this->utility, $this->router);
        $routes = $tool->get();

        $result = (new UpdateAuthorizationGenerator($this, $this->filesystem, $this->utility))
            ->start($app, $tool->storeActions($routes));

        if ($this->option('route') === null) {
            $tool->displayRoutes($routes);
        }

        $this->tipDone($result);
    }
}
