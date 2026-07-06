<?php

declare(strict_types=1);
/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2025-08-29 17:32
 * @Description: Laravel Route Tool
 */

namespace Mooeen\Scaffold;

use Closure;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Routing\ViewController;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Mooeen\Scaffold\Support\ConsoleUi;
use ReflectionClass;
use ReflectionFunction;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Terminal;

class RouterTool
{
    /**
     * The router instance.
     */
    protected Router $router;

    /**
     * sort by (domain, method, uri, name, action)
     */
    protected string $sort;

    /**
     * The folder to filter controllers.
     */
    protected string $folder;

    /**
     * The utility instance.
     */
    protected Utility $utility;

    /**
     * The app name
     */
    protected string $app;

    /**
     * Suppress no-route messages when probing namespaces in bulk.
     */
    protected bool $quiet;

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
     * The table headers for the command.
     */
    protected array $headers = ['Domain', 'Method', 'URI', 'Name', 'Action'];

    /**
     * The terminal width resolver callback.
     *
     * @var Closure|null
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
     * Console output instance.
     */
    protected ConsoleOutput $output;

    /**
     * moo:api 时，$folder != ''，只获取指定目录下的控制器
     * moo:auth 时，$folder = '' $app 下的所有控制器都需要
     */
    public function __construct(string $app, $folder, string $sort, Utility $utility, Router $router, bool $quiet = false)
    {
        $this->app     = $app;
        $this->folder  = $folder;
        $this->router  = $router;
        $this->sort    = $sort;
        $this->utility = $utility;
        $this->quiet   = $quiet;
        $this->output  = new ConsoleOutput;
    }

    public function get()
    {
        $apps = $this->utility->getConfig('controller');

        $this->filter_folder = ucfirst(rtrim($apps[$this->app]['path'] . $this->folder, '/'));
        // moo:api 时，$folder != ''，只获取指定目录下的控制器
        if ($this->folder != '') {
            $this->filter_folder  = str_replace(['/<ROOT_PATH>', '/'], ['', '\\'], $this->filter_folder);
            $this->base_namespace = ucfirst(str_replace('/', '\\', rtrim($apps[$this->app]['path'], '/')));
        }
        // moo:auth 时，$folder = '' $app 下的所有控制器都需要
        else {
            $this->filter_folder = str_replace('/', '\\', $this->filter_folder);
        }

        if (empty($this->router->getRoutes())) {
            if (! $this->quiet) {
                (new ConsoleUi($this->output))->error('应用里没有找到任何路由。');
            }

            return [];
        }

        if (empty($routes = $this->getRoutes())) {
            if (! $this->quiet) {
                (new ConsoleUi($this->output))->error('没有路由匹配当前过滤条件。');
            }

            return [];
        }

        return $routes;
    }

    /**
     * 按指定的键名进行排序
     */
    public function storeActions($routes): mixed
    {
        $actions = [
            'create'       => 1,
            'store'        => 2,
            'edit'         => 3,
            'update'       => 4,
            'show'         => 5,
            'index'        => 6,
            'trashed'      => 7,
            'destroy'      => 8,
            'destroyBatch' => 9,
            'forceDestroy' => 10,
            'restore'      => 11,
        ];

        usort($routes, static function ($a, $b) use ($actions) {
            if ($a['name'] === null or $b['name'] === null) {
                $a_action = 99;
                $b_action = 99;
            } else {
                $a_action = last(explode('.', $a['name']));
                $b_action = last(explode('.', $b['name']));
                $a_action = $actions[$a_action] ?? 99;
                $b_action = $actions[$b_action] ?? 99;
            }

            return $a_action <=> $b_action;
        });

        return $routes;
    }

    /**
     * Compile the routes into a displayable format.
     */
    protected function getRoutes(): array
    {
        $routes = collect($this->router->getRoutes())->map(function ($route) {
            return $this->getRouteInformation($route);
        })->filter()->all();

        $routes = $this->sortRoutes($this->sort, $routes);

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

        // 当指定的是 控制器的根目录时，目录层级大于 4 的都不要
        if ($this->folder != '') {
            $count_path = explode('\\', $action_name);
            if ($this->base_namespace === $this->filter_folder && count($count_path) > 4) {
                return [];
            }
        }

        // 额外纳入的模块（包提供的 admin 控制器，见 controller.{app}.extra_modules：模块名 => 命名空间）。
        // 这些命名空间不在 filter_folder 主命名空间下、生产环境又位于 vendor/ —— 默认会被下面两道排除滤掉。
        // 按当前查询的 folder 决定是否豁免，避免漏进不相干的模块：
        //   - folder=''（moo:auth 全量 ACL）：纳入所有 extra 模块的命名空间；
        //   - folder=<extra 模块名>（moo:api 生成该模块）：只纳入该模块命名空间（并豁免 vendor 检查）；
        //   - folder=其它 host 模块：不纳入（否则 extra 路由会被写进每个模块的 api 目录）。
        $extraModules = $this->utility->getExtraModules($this->app);
        $inExtra      = false;
        if ($this->folder === '') {
            foreach ($extraModules as $ns) {
                if ($ns !== '' && Str::startsWith($action_name, $ns)) {
                    $inExtra = true;
                    break;
                }
            }
        } else {
            $folderKey = ucfirst(trim(str_replace('/', '\\', $this->folder), '\\'));
            if (isset($extraModules[$folderKey]) && Str::startsWith($action_name, $extraModules[$folderKey])) {
                $inExtra = true;
            }
        }

        // 不是指定的目录、或第三方路由都不要（额外模块豁免）
        if (! $inExtra && (! Str::startsWith($action_name, $this->filter_folder) || $this->isVendorRoute($route))) {
            return [];
        }

        // 忽略 Traits 目录
        if (str_contains($action_name, '\\Traits\\')) {
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
    public function displayRoutes(array $routes): void
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
                // 2026-05-24 audit P1:terminal 太窄时算出负长度,substr 返错 slice。clamp 到 0。
                $action = substr($action, 0, max(0, $terminalWidth - 7 - mb_strlen($method . $spaces . $uri . $dots))) . '…';
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

        $rootControllerNamespace = app(UrlGenerator::class)->getRootControllerNamespace()
            ?? (app()->getNamespace() . 'Http\\Controllers');

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
