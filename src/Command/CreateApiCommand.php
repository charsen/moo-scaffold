<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2025-08-29 17:28
 * @Description: Create Api Command
 */

namespace Mooeen\Scaffold\Command;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use Mooeen\Scaffold\Generator\CreateApiGenerator;
use Mooeen\Scaffold\Generator\FreshStorageGenerator;
use Mooeen\Scaffold\RouterTool;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

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
    protected $description = 'Generate API YAML documentation from routes and record publish history';

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
            ['app', InputArgument::OPTIONAL, 'The name of the app. (Ex: admin).'],
            ['namespace', InputArgument::OPTIONAL, 'The name of the namespace. (Ex: System). Omit it to choose one interactively.'],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['route', '-r', InputOption::VALUE_OPTIONAL, 'Display matched routes in terminal after generation.', false],
            ['force', '-f', InputOption::VALUE_OPTIONAL, 'Force overwrite existing YAML files even if unchanged.', false],
            ['all', 'a', InputOption::VALUE_NONE, 'Generate API YAML for all namespaces of the selected app.'],
            ['stale', null, InputOption::VALUE_OPTIONAL, 'How to handle stale actions: keep, deprecate (default), delete.', 'deprecate'],
            ['sync-names', null, InputOption::VALUE_NONE, 'Overwrite existing action name/desc from controller docblock (default keeps yaml, only reports diffs).'],
        ];
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->showTitle();

        if (! $this->checkRunning()) {
            return;
        }

        $apps        = $this->utility->getConfig('controller');
        $appArgument = $this->argument('app');
        $app         = $appArgument;
        if (empty($app)) {
            $app = $this->chooseApp($apps);
        }
        if (! isset($apps[$app])) {
            $this->reportAppNotConfigured($app);

            return;
        }

        // $this->tipCallCommand('moo:fresh');
        (new FreshStorageGenerator($this, $this->filesystem, $this->utility))->start(false, true);

        $this->tipCallCommand('moo:api');

        $force     = $this->isForced();
        $staleMode = strtolower(trim((string) $this->option('stale')));
        if (! in_array($staleMode, ['keep', 'deprecate', 'delete'], true)) {
            $this->console()->warn("--stale 选项无效 [{$staleMode}],回退为 [deprecate]。");
            $staleMode = 'deprecate';
        }
        $syncNames = (bool) $this->option('sync-names');
        $result    = true;

        if ($this->option('all')) {
            $result = $this->generateAllNamespaces($app, $force, $staleMode, $syncNames);
            $this->tipDone($result);

            return;
        }

        $namespaceArgument = $this->argument('namespace');
        if (empty($namespaceArgument)) {
            $namespaces = $this->utility->getControllerNamespaces($app);
            $namespace  = $this->choicePrompt('选择 namespace', $namespaces);
        } else {
            $namespace = $this->normalizeNamespace($namespaceArgument);
        }

        $tool   = new RouterTool($app, $namespace, 'uri', $this->utility, $this->router);
        $routes = $tool->get();

        $result = (new CreateApiGenerator($this, $this->filesystem, $this->utility))
                    ->start($app, $namespace, $tool->storeActions($routes), $force, $staleMode, $syncNames);

        if ($this->option('route') === null && ! empty($routes)) {
            $tool->displayRoutes($routes);
        }

        $this->tipDone($result);
    }

    private function generateAllNamespaces(string $app, bool $force, string $staleMode, bool $syncNames = false): bool
    {
        $namespaces = $this->utility->getControllerNamespaces($app);
        if ($namespaces === []) {
            $this->console()->error("app [{$app}] 下没有找到任何控制器命名空间。");

            return false;
        }

        $this->console()->info('共 ' . count($namespaces) . ' 个 namespace 待处理…');

        $result  = true;
        $done    = 0;
        $skipped = 0;
        foreach ($namespaces as $namespace) {
            $namespace = $this->normalizeNamespace((string) $namespace);
            $tool      = new RouterTool($app, $namespace, 'uri', $this->utility, $this->router, true);
            $routes    = $tool->get();

            if ($routes === [] && ! $this->hasNamespaceApiFiles($app, $namespace)) {
                $this->console()->unchanged($namespace, '无匹配路由');
                $skipped++;

                continue;
            }

            $this->console()->section("Namespace {$namespace}");
            $namespaceResult = (new CreateApiGenerator($this, $this->filesystem, $this->utility))
                ->start($app, $namespace, $tool->storeActions($routes), $force, $staleMode, $syncNames);
            $result = $result && $namespaceResult;
            $done++;

            if ($this->option('route') === null && $routes !== []) {
                $tool->displayRoutes($routes);
            }
        }

        $this->console()->info("完成:生成 {$done} 个 namespace,跳过 {$skipped} 个(无路由)");

        return $result;
    }

    private function hasNamespaceApiFiles(string $app, string $namespace): bool
    {
        $namespace = ($namespace === '<ROOT_PATH>' || $namespace === '/') ? '' : trim($namespace, '/');
        $path      = $this->utility->getApiPath('schema') . $app . '/' . ($namespace === '' ? '' : $namespace . '/');

        return $this->filesystem->isDirectory($path) && $this->filesystem->files($path) !== [];
    }

    private function normalizeNamespace(string $namespace): string
    {
        return match ($namespace) {
            '<ROOT_PATH>', '/' => '<ROOT_PATH>',
            default            => ucfirst($namespace),
        };
    }
}
