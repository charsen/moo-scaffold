<?php

namespace Mooeen\Scaffold\Generator;

use Brick\VarExporter\VarExporter;
use Illuminate\Support\Str;

/**
 * Update Authorization Files
 *
 * @author Charsen https://github.com/charsen
 */
class UpdateAuthorizationGenerator extends Generator
{
    private array $controllers;

    /**
     * 只做增量，不做替换，因为可能会有手工润色
     */
    public function start(string $app, array $routes): bool
    {
        $config         = $this->utility->getConfig('controller.' . $app);
        $base_namespace = ucfirst(str_replace('/', '', $config['path']));
        $base_namespace = Str::snake($base_namespace, '-');
        // dump($base_namespace);

        $original_actions = [];
        $config_actions   = [];
        $whitelist        = [];
        $controllers      = [];
        $modules          = [];

        foreach ($routes as $route) {
            [$controller, $action] = explode('@', $route['action']);
            $PMC_names             = $this->utility->parsePMCNames($this->getController($controller));
            $module_key            = $app . '-' . Str::snake($PMC_names['module']['name']['en'], '-');
            $module_key            = $this->getMd5($module_key);
            $modules[$module_key]  = $PMC_names['module']['name'];

            $controller_key               = str_replace(['\\', '-controllers-', '-controller', 'app-'], ['', '-', '', ''], Str::snake($controller, '-'));
            $controller_key               = $this->getMd5($controller_key);
            $controllers[$controller_key] = $PMC_names['controller']['name'];

            $action_info = $this->utility->parseActionInfo($this->getMethod($controller, $action));
            $action_name = $this->utility->parseActionName($this->getMethod($controller, $action));
            $action_key  = str_replace(['\\', '@', '-controllers-', '-controller-', 'app-'], ['', '-', '-', '-', ''], Str::snake($route['action'], '-'));

            $meta = [
                'module_key'       => 'module-' . $module_key,
                'controller'       => $controller,
                'controller_key'   => 'controller-' . $controller_key,
                'action'           => $action,
                'action_plain_key' => $action_key,
                'action_key'       => $this->getMd5($action_key),
                'name'             => $action_name,
                'lang'             => $action_info['name'],
                'desc'             => $action_info['desc'],
                'whitelist'        => $action_info['whitelist'],
            ];

            if ($action_info['whitelist']) {
                $whitelist[] = $meta['action_key'];
            } else {
                $config_actions[$meta['module_key']][$meta['controller_key']][] = $meta['action_key'];
            }

            $original_actions[] = $meta;
        }

        $this->buildActions($app, $config_actions, $whitelist);
        $this->buildLangFiles($app, $config, $modules, $controllers, $original_actions);
        $this->buildACLViewer($app, $base_namespace, $original_actions);

        return true;
    }

    /**
     * 配置文件生成
     */
    private function buildActions(string $app, array $actions, array $whitelist): void
    {
        $apps   = $this->utility->getApps();
        $config = config('actions', []);

        foreach ($apps as $item => $name) {
            if ($item === $app) {
                $config[$app] = [
                    'whitelist' => $whitelist,
                    'actions'   => $actions,
                ];
            }
        }

        $php_code = '<?php' . PHP_EOL
            . 'return ' . VarExporter::export($config) . ';'
            . PHP_EOL;

        $this->filesystem->put(config_path('actions.php'), $php_code);
        $this->command->info('+ ./config/actions.php');
    }

    /**
     * 生成多语言文件
     */
    private function buildLangFiles(string $app, array $controller, array $modules, array $controllers, array $actions): void
    {
        $languages = $this->utility->getConfig('languages');
        foreach ($languages as $lang) {
            $code = [
                '<?php',
                '',
                'return [',
            ];

            $code[] = $this->getTabs(1) . "'app-{$app}' => '{$controller['name'][$lang]}',";

            foreach ($modules as $key => $val) {
                $code[] = $this->getTabs(1) . "'module-{$key}' => '{$val[$lang]}',";
            }

            foreach ($controllers as $key => $val) {
                $code[] = $this->getTabs(1) . "'controller-{$key}' => '{$val[$lang]}',";
            }

            foreach ($actions as $attr) {
                if ($attr['whitelist']) {
                    continue;
                }
                $attr['lang'][$lang] = str_replace("'", '&apos;', $attr['lang'][$lang]);
                $attr['desc']        = str_replace("'", '&apos;', $attr['desc']);
                $code[]              = $this->getTabs(1) . "'{$attr['action_key']}' => '{$attr['lang'][$lang]}',";
                $code[]              = $this->getTabs(1) . "'{$attr['action_key']}-desc' => '{$attr['desc']}',";
            }
            $code[] = '];';
            $code[] = '';

            $this->filesystem->put(lang_path($lang . '/actions.php'), implode("\n", $code));
            $this->command->info('+ ./lang/' . $lang . '/actions.php (Updated)');
        }
    }

    /**
     * 生成 acl 可视化文件，用于检查对比
     */
    private function buildACLViewer(string $app, string $namespace, array $actions): void
    {
        $code       = ['# ACL'];
        $controller = '';
        foreach ($actions as $item) {
            if ($controller !== $item['controller']) {
                $controller = $item['controller'];
                $code[]     = '';
                $code[]     = '## ' . $item['controller'] . ' - ' . $item['controller_key'];
            }

            $code[] = "- {$item['action_plain_key']} - `{$item['action_key']}` - " . ($item['whitelist'] ? '`whitelist`' : $item['name']);
        }

        $this->filesystem->put(app_path($app . '-acl.md'), implode("\n", $code));
        $this->command->info('+ ./app/acl.md (Updated)');
    }

    /**
     * 8 位 m5 加密
     */
    private function getMd5($str): string
    {
        if ($this->utility->getConfig('authorization.md5')) {
            return substr(md5($str), 8, 16);
        }

        return $str;
    }

    /**
     * 获取并缓存 控制器 的反向解析结果
     */
    private function getController(string $name)
    {
        if (isset($this->controllers[$name])) {
            return $this->controllers[$name];
        }

        $this->controllers[$name] = new \ReflectionClass($name);

        return $this->controllers[$name];
    }

    /**
     * 获取并缓存 控制器 方法函数 的反向解析结果
     */
    private function getMethod($controller, $action)
    {
        $key = $controller . '_' . $action;
        if (isset($this->controllers[$key])) {
            return $this->controllers[$key];
        }

        $this->controllers[$key] = new \ReflectionMethod($controller, $action);

        return $this->controllers[$key];
    }
}
