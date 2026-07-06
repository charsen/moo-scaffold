<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2025-07-27 18:38
 * @LastEditors: Charsen
 * @LastEditTime: 2025-08-11 09:54
 * @Description: Adder
 */

namespace Mooeen\Scaffold\Command;

use Illuminate\Support\Str;
use Mooeen\Scaffold\Adder\ControllerAdder;
use Mooeen\Scaffold\Adder\RouterAdder;
use Symfony\Component\Console\Input\InputArgument;

class AdderCommand extends Command
{
    /**
     * The console command title.
     */
    protected string $title = 'Adder Command';

    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'moo:adder';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Incrementally add controller actions and route entries to an existing module';

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['app', InputArgument::OPTIONAL, 'The app to add actions to. (Ex: admin)'],
            ['folder', InputArgument::OPTIONAL, 'The controller namespace/folder. (Ex: Light)'],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            // ...
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

        $apps = $this->utility->getConfig('controller');
        $app  = $this->argument('app');
        if (empty($app)) {
            $app = $this->chooseApp($apps);
        }
        if (! isset($apps[$app])) {
            $this->reportAppNotConfigured($app);

            return;
        }

        $folder = $this->argument('folder');
        if (empty($folder)) {
            // $folder_names = $this->getFolders($apps[$app]['path']);
            $folder_names = $this->utility->getControllerNamespaces($app);
            $folder       = $this->choicePrompt('选择目录', $folder_names);
        }
        $folder = ucfirst($folder);
        $folder = $folder === '/' ? '<ROOT_PATH>' : $folder;

        // plan-53 出身:目录命中 extra_modules 且对应自动发现的扩展包(命名空间匹配)→ 走包目录/包路由;
        // extra_module 声明了但本机没发现软链包(如纯 vcs 环境)→ 明确拒绝,不 silent 落 host
        $origin = $this->resolveFolderOrigin($app, $folder);
        if ($origin === false) {
            $this->console()->error("目录「{$folder}」来自 extra_modules,但本机未发现对应的软链扩展包 —— 无法增量(请在软链装该包的开发环境操作)。");

            return;
        }

        $controllers = $origin !== null
            ? $this->getPackageControllers($origin)
            : $this->getControllers($apps[$app]['path'], $folder);
        $controller     = $this->choicePrompt('选择控制器', $controllers);
        $new_controller = false;

        if ($controller === '<NEW_ONE>') {
            $new_controller = true;
            $controller     = $this->askPrompt('输入控制器名');
        }

        $action_txt                    = $this->askPrompt('输入 action [request] [resource](空格分隔,后两项可留空)');
        [$action, $request, $resource] = $this->parseAction($action_txt);
        if ($action === '') {
            $this->console()->warn('action 必填:至少输入一个 action 名(格式 action [request] [resource],空格分隔)。');

            return;
        }

        $this->tipCallCommand('Controller Adder');
        $make_controller = (new ControllerAdder($this, $this->filesystem, $this->utility))
            ->start($app, $folder, $controller, $new_controller, $action, $request, $resource, $origin);

        if ($make_controller) {
            $route = $this->askPrompt('输入 method url(空格分隔;留空 = 不加路由)');
            if (! empty($route)) {
                (new RouterAdder($this, $this->filesystem, $this->utility))->start($app, $make_controller, $route, $origin);
            } else {
                $this->console()->warn('未添加任何路由。');
            }
        }

        $this->tipDone((bool) $make_controller);
    }

    protected function parseAction(?string $action_txt): array
    {
        // 空输入(回车 / 纯空白)→ '',避免 explode(null) TypeError;多空格也容错(preg_split)
        $action_txt = trim((string) $action_txt);
        $tmp        = $action_txt === '' ? [] : preg_split('/\s+/', $action_txt);
        $action     = $tmp[0] ?? '';
        $request    = $tmp[1] ?? '';
        $resource   = $tmp[2] ?? '';

        $request  = in_array($request, ['null', "''", '""']) ? '' : $request;
        $resource = in_array($resource, ['null', "''", '""']) ? '' : $resource;

        return [$action, $request, $resource];
    }

    /**
     * plan-53:目录 → 出身解析。返回:null = host 目录;string = 扩展包 key;
     * false = extra_modules 声明了但本机没发现对应软链包(拒绝增量)。
     */
    protected function resolveFolderOrigin(string $app, string $folder): null|string|false
    {
        $extraModules = $this->utility->getExtraModules($app);
        if (! isset($extraModules[$folder])) {
            return null;    // host 普通目录
        }

        $declaredNs = $extraModules[$folder];
        foreach (app(\Mooeen\Scaffold\Support\PackageRegistry::class)->all() as $key => $pkg) {
            // extra_modules 的命名空间以包 psr-4 根开头 → 该包即出身
            if (str_starts_with($declaredNs . '\\', $pkg['namespace'] . '\\')) {
                return $key;
            }
        }

        return false;
    }

    /** plan-53:扫描扩展包 Controllers/Admin(平铺)下的控制器,供选择。 */
    protected function getPackageControllers(string $origin): array
    {
        $dir = rtrim($this->utility->targetContext($origin)->pathFor('controller'), '/');
        $out = ['<NEW_ONE>'];
        if (is_dir($dir)) {
            foreach ($this->filesystem->files($dir) as $item) {
                if (Str::endsWith($item->getFilename(), 'Controller.php')) {
                    $out[] = $item->getBasename('.php');
                }
            }
        }

        return $out;
    }

    protected function getControllers($path, $folder): array
    {
        $folder      = str_replace('<ROOT_PATH>', '', $folder);
        $path        = base_path('/') . $path . $folder;
        $controllers = $this->filesystem->files($path);

        foreach ($controllers as $key => $item) {
            if (Str::endsWith($item->getFilename(), 'Controller.php')) {
                // folder/name 带 '/' 分隔:① 列表可读(Market/BaseServiceController,原先 MarketBaseServiceController 糊一起);
                //   ② 值 = 相对路径,ControllerAdder 拼 config.path + controller 才命中真实文件(子目录里)。ROOT_PATH 时 folder='' 不加斜杠。
                $controllers[$key] = ($folder === '' ? '' : $folder . '/') . str_replace([$path, '.php'], ['', ''], $item->getBasename());
            } else {
                unset($controllers[$key]);
            }
        }
        array_unshift($controllers, '<NEW_ONE>');

        return $controllers;
    }

    // protected function getFolders($path): array
    // {
    //     $path        = base_path('/') . $path;
    //     $directories = $this->filesystem->directories($path);
    //     $folders     = array_map('basename', $directories);

    //     return array_diff($folders, ['Traits']);
    // }
}
