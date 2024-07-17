<?php

namespace Mooeen\Scaffold\Command;

use Closure;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Routing\ViewController;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Mooeen\Scaffold\Generator\CreateApiGenerator;
use Mooeen\Scaffold\Generator\FreshStorageGenerator;
use Mooeen\Scaffold\Utility;
use ReflectionClass;
use ReflectionFunction;
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
     * The app name
     */
    protected string $app;

    /**
     * 本次 控制器 的基础命名空间
     */
    protected string $base_namespace;

    /**
     * 本次要过滤出来的制定目录
     */
    protected string $filter_folder;

    /**
     * 缓存 controller 的信息
     */
    protected array $controllers = [];

    /**
     * The router instance.
     */
    protected Router $router;

    /**
     * The table headers for the command.
     */
    protected array $headers = ['Domain', 'Method', 'URI', 'Name', 'Action'];

    /**
     * The terminal width resolver callback.
     *
     * @var \Closure|null
     */
    protected static $terminalWidthResolver;

    /**
     * The verb colors for the command.
     *
     * @var array
     */
    protected $verbColors = [
        'ANY'     => 'red',
        'GET'     => 'blue',
        'HEAD'    => '#6C7280',
        'OPTIONS' => '#6C7280',
        'POST'    => 'yellow',
        'PUT'     => 'yellow',
        'PATCH'   => 'yellow',
        'DELETE'  => 'red',
    ];

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
        ];
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->alert($this->title);

        $this->checkRunning();

        $this->app = $this->argument('app');
        $apps      = $this->utility->getConfig('controller');
        if (! isset($apps[$this->app])) {
            $this->components->error("The '{$this->app}' is not configured, Please check controller configuration.");

            return;
        }

        $namespace = $this->argument('namespace');
        if (empty($namespace)) {
            $namespaces = $this->utility->getControllerNamespaces($this->app);
            $namespace  = $this->choice('which namespace?', $namespaces);
        } else {
            $namespace = $namespace === '/' ? '' : ucfirst($namespace);
        }

        $this->filter_folder  = rtrim($apps[$this->app]['path'] . $namespace, '/');
        $this->filter_folder  = ucfirst(str_replace(['/<ROOT_PATH>', '/'], ['', '\\'], $this->filter_folder));
        $this->base_namespace = ucfirst(str_replace('/', '\\', rtrim($apps[$this->app]['path'], '/')));

        $this->tipCallCommand('moo:fresh');
        (new FreshStorageGenerator($this, $this->filesystem, $this->utility))->start();

        $this->tipCallCommand('moo:api');

        if (! $this->router->getRoutes()->count()) {
            $this->components->error("Your application doesn't have any routes.");

            return;
        }

        if (empty($routes = $this->getRoutes())) {
            $this->components->error("Your application doesn't have any routes matching the given criteria.");

            return;
        }

        // 按指定的键名进行排序
        $actions = [
            'create'       => 1,
            'edit'         => 2,
            'index'        => 3,
            'trashed'      => 4,
            'store'        => 5,
            'update'       => 6,
            'show'         => 7,
            'destroy'      => 8,
            'restore'      => 9,
            'destroyBatch' => 10,
            'forceDestroy' => 11,
        ];
        usort($routes, static function ($a, $b) use ($actions) {
            if ($a['name'] === null or $b['name'] === null) {
                $a_action = 99;
                $b_action = 99;
            } else {
                [$c, $a_action] = explode('.', $a['name']);
                [$c, $b_action] = explode('.', $b['name']);
                $a_action       = $actions[$a_action] ?? 99;
                $b_action       = $actions[$b_action] ?? 99;
            }

            return $a_action <=> $b_action;
        });

        $result = (new CreateApiGenerator($this->components, $this->filesystem, $this->utility))
            ->start($this->app, $namespace, $routes);

        $this->displayRoutes($routes);

        $this->tipDone($result);
    }

    /**
     * Compile the routes into a displayable format.
     */
    protected function getRoutes(): array
    {
        $routes = collect($this->router->getRoutes())->map(function ($route) {
            return $this->getRouteInformation($route);
        })->filter()->all();

        if (($sort = $this->option('sort')) !== null) {
            $routes = $this->sortRoutes($sort, $routes);
        } else {
            $routes = $this->sortRoutes('uri', $routes);
        }

        return $this->pluckColumns($routes);
    }

    /**
     * Get the route information for a given route.
     */
    protected function getRouteInformation(Route $route): array
    {
        return $this->filterRoute($route);
    }

    /**
     * Sort the routes by a given element.
     */
    protected function sortRoutes(string $sort, array $routes): array
    {
        return Arr::sort($routes, function ($route) use ($sort) {
            return $route[$sort];
        });
    }

    /**
     * Remove unnecessary columns from the routes.
     */
    protected function pluckColumns(array $routes): array
    {
        return array_map(function ($route) {
            return Arr::only($route, $this->getColumns());
        }, $routes);
    }

    /**
     * Filter the route
     */
    protected function filterRoute(Route $route): array
    {
        $action_name = ltrim($route->getActionName(), '\\');
        // dump($action_name);

        // 当制定的是 控制器的根目录时，目录层级大于 4 的都不要
        $count_path = explode('\\', $action_name);
        if ($this->base_namespace === $this->filter_folder && count($count_path) > 4) {
            return [];
        }

        // 不是指定的 namespace 目录，或第三方路由都不要
        if (! Str::startsWith($action_name, $this->filter_folder) || $this->isVendorRoute($route)) {
            return [];
        }

        $controller = $route->getControllerClass();
        if (! isset($this->controllers[$controller])) {
            $methods = (new ReflectionClass($controller))->getMethods();
            foreach ($methods as $item) {
                if (in_array($item->getName(), ['__construct', '__call', 'boot', 'callAction'])) {
                    continue;
                }

                $this->controllers[$controller][] = $controller . '@' . $item->getName();
            }
        }

        // 筛选出，实际 controller 中定义了的 action ，因为路由有可能多定义了（使用 Route::resource() 的原因）
        if (! in_array($action_name, $this->controllers[$controller], true)) {
            return [];
        }

        return [
            'domain' => $route->domain(),
            'method' => implode('|', $route->methods()),
            'uri'    => $route->uri(),
            'name'   => $route->getName(),
            'action' => $action_name,
        ];
    }

    /**
     * Determine if the route has been defined outside of the application.
     */
    protected function isVendorRoute(Route $route): bool
    {
        if ($route->action['uses'] instanceof Closure) {
            $path = (new ReflectionFunction($route->action['uses']))->getFileName();
        } elseif (is_string($route->action['uses']) && str_contains($route->action['uses'], 'SerializableClosure')) {
            return false;
        } elseif (is_string($route->action['uses'])) {
            if ($this->isFrameworkController($route)) {
                return false;
            }

            $path = (new ReflectionClass($route->getControllerClass()))->getFileName();
        } else {
            return false;
        }

        return str_starts_with($path, base_path('vendor'));
    }

    /**
     * Determine if the route uses a framework controller.
     */
    protected function isFrameworkController(Route $route): bool
    {
        return in_array($route->getControllerClass(), [
            '\Illuminate\Routing\RedirectController',
            '\Illuminate\Routing\ViewController',
        ], true);
    }

    /**
     * Get the column names to show (lowercase table headers).
     */
    protected function getColumns(): array
    {
        return array_map('strtolower', $this->headers);
    }

    // ---- 下面的这几个函数，是格式化输出到 Terminal 上的 -----------------------------------------------------------

    /**
     * Display the route information on the console.
     */
    protected function displayRoutes(array $routes): void
    {
        $this->output->writeln($this->forCli(collect($routes)));
    }

    /**
     * Convert the given routes to regular CLI output.
     */
    protected function forCli(Collection $routes): array
    {
        $routes = $routes->map(
            fn ($route) => array_merge($route, [
                'action' => $this->formatActionForCli($route),
                'method' => $route['method'] === 'GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS' ? 'ANY' : $route['method'],
                'uri'    => $route['domain'] ? ($route['domain'] . '/' . ltrim($route['uri'], '/')) : $route['uri'],
            ]),
        );

        $maxMethod = mb_strlen($routes->max('method'));

        $terminalWidth = self::getTerminalWidth();

        $routeCount = $this->determineRouteCountOutput($routes, $terminalWidth);

        return $routes->map(function ($route) use ($maxMethod, $terminalWidth) {
            [
                'action' => $action,
                'domain' => $domain,
                'method' => $method,
                'uri'    => $uri,
            ] = $route;

            $spaces = str_repeat(' ', max($maxMethod + 6 - mb_strlen($method), 0));

            $dots = str_repeat('.', max(
                $terminalWidth - mb_strlen($method . $spaces . $uri . $action) - 6 - ($action ? 1 : 0), 0
            ));

            $dots = empty($dots) ? $dots : " $dots";

            if ($action && ! $this->output->isVerbose() && mb_strlen($method . $spaces . $uri . $action . $dots) > ($terminalWidth - 6)) {
                $action = substr($action, 0, $terminalWidth - 7 - mb_strlen($method . $spaces . $uri . $dots)) . '…';
            }

            $method = Str::of($method)->explode('|')->map(
                fn ($method) => sprintf('<fg=%s>%s</>', $this->verbColors[$method] ?? 'default', $method),
            )->implode('<fg=#6C7280>|</>');

            return [sprintf(
                '  <fg=white;options=bold>%s</> %s<fg=white>%s</><fg=#6C7280>%s %s</>',
                $method,
                $spaces,
                preg_replace('#({[^}]+})#', '<fg=yellow>$1</>', $uri),
                $dots,
                str_replace('   ', ' › ', $action ?? ''),
            )];
        })
                      ->flatten()
                      ->filter()
                      ->prepend('')
                      ->push('')->push($routeCount)->push('')
                      ->toArray();
    }

    /**
     * Get the formatted action for display on the CLI.
     */
    protected function formatActionForCli(array $route): ?string
    {
        ['action' => $action, 'name' => $name] = $route;

        if ($action === 'Closure' || $action === ViewController::class) {
            return $name;
        }

        $name = $name ? "$name   " : null;

        $rootControllerNamespace = $this->laravel[UrlGenerator::class]->getRootControllerNamespace()
            ?? ($this->laravel->getNamespace() . 'Http\\Controllers');

        if (str_starts_with($action, $rootControllerNamespace)) {
            return $name . substr($action, mb_strlen($rootControllerNamespace) + 1);
        }

        $actionClass = explode('@', $action)[0];

        if (class_exists($actionClass) && str_starts_with((new ReflectionClass($actionClass))->getFilename(), base_path('vendor'))) {
            $actionCollection = collect(explode('\\', $action));

            return $name . $actionCollection->take(2)->implode('\\') . '   ' . $actionCollection->last();
        }

        return $name . $action;
    }

    /**
     * Get the terminal width.
     */
    public static function getTerminalWidth(): int
    {
        return is_null(static::$terminalWidthResolver) ? (new Terminal)->getWidth() : call_user_func(static::$terminalWidthResolver);
    }

    /**
     * Determine and return the output for displaying the number of routes in the CLI output.
     */
    protected function determineRouteCountOutput(Collection $routes, int $terminalWidth): string
    {
        $routeCountText = 'Showing [' . $routes->count() . '] routes';

        $offset = $terminalWidth - mb_strlen($routeCountText) - 2;

        $spaces = str_repeat(' ', $offset);

        return $spaces . '<fg=blue;options=bold>Showing [' . $routes->count() . '] routes</>';
    }
}
