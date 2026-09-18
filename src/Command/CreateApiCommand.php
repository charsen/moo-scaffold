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
            ['sync-names', null, InputOption::VALUE_NONE, 'Overwrite existing controller/action names and action descriptions from docblocks (default keeps non-empty YAML values).'],
        ];
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->showTitle();

        if (! $this->checkRunning()) {
            return self::FAILURE;
        }

        $apps        = $this->utility->getAppTargets();
        $appArgument = $this->argument('app');
        $app         = $appArgument;
        if (empty($app)) {
            $app = $this->chooseApp($apps);
            if ($app === null) {
                return self::FAILURE;   // chooseApp 已报错（没得选 / 非交互没选成）
            }
        }
        if (! isset($apps[$app])) {
            return $this->reportAppNotConfigured($app);
        }

        // $this->tipCallCommand('moo:fresh');
        (new FreshStorageGenerator($this, $this->filesystem, $this->utility))->start(false, true);

        $this->tipCallCommand('moo:api');

        $force     = $this->isForced();
        $staleMode = strtolower(trim((string) $this->option('stale')));
        if (! in_array($staleMode, ['keep', 'deprecate', 'delete'], true)) {
            $this->console()->warn("--stale 选项无效 [{$staleMode}]，回退为 [deprecate]。");
            $staleMode = 'deprecate';
        }
        $syncNames = (bool) $this->option('sync-names');

        if ($this->option('all')) {
            return $this->tipDone($this->generateAllNamespaces($app, $force, $staleMode, $syncNames));
        }

        $namespaceArgument = $this->argument('namespace');
        if (empty($namespaceArgument)) {
            $namespace = $this->chooseNamespace($app);
            if ($namespace === null) {
                return self::FAILURE;   // chooseNamespace 已报错，退出码必须跟着红字走
            }
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

        return $this->tipDone($result);
    }

    /**
     * 选 namespace（没给 `namespace` 参数时的交互回落）。
     *
     * 返回 `null` = **没得选或没选成**，两种情况都先报错再返回，调用方一律中止（FAILURE）——
     * 与 `Command::chooseSchema()` 同口径（第 16 项立的规矩），这里补的是同一个坑的另一处现场：
     *   ① 该 app 下一个控制器命名空间都没有 → 早前直接 `choice([], …)`，抛 Symfony 的
     *      `LogicException: Choice question must have at least 1 choice available.`（崩栈而非报错）；
     *   ② 非交互模式（`--no-interaction`）下 `choice` 回落默认值 null → 早前原样传给 RouterTool，
     *      而 `RouterTool::$folder` 是 `string` 属性，于是在**属性赋值处**抛
     *      `TypeError: Cannot assign null to property … of type string` 崩栈，而不是一行红字 + 退出码 1。
     */
    private function chooseNamespace(string $app): ?string
    {
        $namespaces = $this->utility->getControllerNamespaces($app);

        if ($namespaces === []) {
            $this->console()->error("app [{$app}] 下没有找到任何控制器命名空间，无法生成 API。");

            return null;
        }

        $picked = $this->choicePrompt('选择 namespace', $namespaces);

        if ($picked === null || $picked === '') {
            $this->console()->error('未选择 namespace。非交互模式下请显式传入 namespace，或用 -a 生成该 app 下全部 namespace。');

            return null;
        }

        return $this->normalizeNamespace((string) $picked);
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

        $this->console()->info("完成：生成 {$done} 个 namespace，跳过 {$skipped} 个（无路由）");

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
