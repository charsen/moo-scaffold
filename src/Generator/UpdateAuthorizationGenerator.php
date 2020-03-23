<?php
namespace Charsen\Scaffold\Generator;

use Route;

/**
 * Update Authorization Files
 *
 * @author Charsen https://github.com/charsen
 */
class UpdateAuthorizationGenerator extends Generator
{

    /**
     * 只做增量，不做替换，因为可能会有手工润色
     *
     * @throws \ReflectionException
     */
    public function start()
    {
        $route_actions = $this->getActions();

        $config_actions     = [];
        $whitelist          = [];
        $lang_actions       = [];
        // dump($route_actions);
        foreach ($route_actions as $controller => $actions)
        {
            $reflection_class   = new \ReflectionClass($controller);
            $PMCNames           = $this->utility->parsePMCNames($reflection_class);

            $new_actions     = $this->formatActions($reflection_class, $PMCNames, $controller, $actions, $lang_actions, $whitelist);
            if ( ! empty($new_actions))
            {
                $this->formatControllerActions($PMCNames, $config_actions, $new_actions, $controller, $lang_actions);
            }
        }
        $this->buildActions($config_actions, $whitelist);
        $this->buildLangFiles($lang_actions);
    }

    /**
     * 生成多语言文件
     *
     * @param $lang_actions
     */
    private function buildLangFiles($lang_actions)
    {
        $languages = $this->utility->getConfig('languages');
        foreach ($languages as $lang)
        {
            $code = [
                "<?php",
                "",
                "return ["
            ];
            foreach ($lang_actions as $key => $attr)
            {
                $annotation = ($attr['name'][$lang]== '') ? '//' : '';
                $attr['name'][$lang] = str_replace("'", "&apos;", $attr['name'][$lang]);
                $code[]     = $this->getTabs(1) . "{$annotation}'{$key}' => '{$attr['name'][$lang]}',";
            }
            $code[] = "];";
            $code[] = "";

            $this->filesystem->put(resource_path('lang/' . $lang . '/actions.php'), implode("\n", $code));
            $this->command->info('+ ./resources/lang/' . $lang . '/actions.php (Updated)');
        }
    }

    /**
     * 格式化单个控制器的动作，若没有 @acl 的添加到白名单
     *
     * @param $controller
     * @param $actions
     * @param $lang_actions
     * @param $whitelist
     *
     * @return array
     * @throws \ReflectionException
     */
    private function formatActions($reflection_class, $PMCNames, $controller, $actions, &$lang_actions, &$whitelist)
    {
        $package_key       = $this->getMd5($PMCNames['package']['name']['en']);

        $new_actions       = [];
        foreach ($actions as $key => $val)
        {
            // 去掉 创建及编辑 表单
            if (in_array($key, ['create', 'edit']))
            {
                continue;
            }

            $action_key  = $this->getActionKey($package_key, $controller, $val);
            $action_key  = $this->getMd5($action_key);
            $acl_info    = $this->utility->parseActionNames($val, $reflection_class);

            if ( ! isset($acl_info['whitelist']))
            {
                $lang_actions[$action_key]  = $acl_info;
                $new_actions[]              = $action_key;
            }
            else
            {
                $whitelist[] = "'" . $action_key . "'";
            }
        }

        return $new_actions;
    }

    /**
     * 格式化一个控件器的动作
     *
     * @param $config_actions
     * @param $controller
     * @param $actions
     * @param $lang_actions
     *
     * @return mixed
     * @throws \ReflectionException
     */
    private function formatControllerActions($PMCNames, &$config_actions, $actions, $controller, &$lang_actions)
    {
        $package_key        = $this->getMd5($PMCNames['package']['name']['en']);

        // 用 namespace 来解析目录深度，只支持 `app/Http/Controllers/` 往下**两级**，
        // todo: 用递归来处理
        $paths              = str_replace('App\\Http\\Controllers\\', '', $controller);
        $paths              = explode('\\', $paths);

        // 需要忽略的目录
        if (in_array($paths[0], $this->utility->getConfig('authorization.exclude_forder'))) {
            return $config_actions;
        }

        for ($i = 0; $i < count($paths); $i++)
        {
            // App/Http/Controllers/TestController.php
            // App/Http/Controllers/Folder1/AuthController.php
            // App/Http/Controllers/{$Path[0]}
            if ($i == 0)
            {
                $lang_actions[$package_key] = $PMCNames['package'];

                $is_controller              = preg_match("/[\w]+Controller$/", $paths[0]);
                if ($is_controller)
                {
                    $controller_key                  = $this->getMd5($paths[0]);
                    $lang_actions[$controller_key]   = $PMCNames['controller'];
                    $config_actions[$package_key][$controller_key] = $actions;
                }
                else
                {
                    // $module_key = $package_key . $paths[0];
                    $tmp_key = $this->getMd5("$package_key-$paths[0]");
                    if ( ! isset($config_actions[$package_key][$tmp_key]))
                    {
                        $config_actions[$package_key][$tmp_key] = [];
                    }
                    // $tmp_key = {package_key}.App
                    if ( ! preg_match("/[\w]+Controller$/", "$paths[0]-$paths[1]")) {
                        $lang_actions[$tmp_key]     = $PMCNames['package'];
                    } else {
                        $lang_actions[$tmp_key]     = $PMCNames['module'];
                    }
                }
            }
            // App/Http/Controllers/Folder1/AuthController.php
            // App/Http/Controllers/Folder1/Folder2/AuthController.php
            // App/Http/Controllers/{$Path[0]}/{$Path[1]}
            elseif ($i == 1)
            {
                $controller_key = "$package_key-$paths[0]-$paths[1]";
                $is_controller  = preg_match("/[\w]+Controller$/", $controller_key);

                $controller_key = $this->getMd5($controller_key);
                $controller_key = $is_controller ? $controller_key . 'Controller' : $controller_key;

                if ($is_controller)
                {
                    $tmp_key                                                 = $this->getMd5("$package_key-$paths[0]");
                    $config_actions[$package_key][$tmp_key][$controller_key] = $actions;
                    $lang_actions[$controller_key]                           = $PMCNames['controller'];
                }
                else
                {
                    // $tmp_key = {package_key}.App/Authorization/
                    $tmp_key = $this->getMd5("$package_key-$paths[0]-$paths[1]");
                    if ( ! isset($config_actions[$package_key][$tmp_key]))
                    {
                        $config_actions[$package_key][$tmp_key] = [];
                    }
                    $lang_actions[$tmp_key]     = $PMCNames['module'];
                }
            }
            // App/Http/Controllers/App/Folder1/Folder2/Folder3/AuthController.php
            // App/Http/Controllers/{$Path[0]}/{$Path[1]}/{$Path[2]}
            elseif ($i == 2)
            {
                $controller_key                 = "$package_key-$paths[0]-$paths[1]-$paths[2]";
                $module_key                     = $this->getMd5("$package_key-$paths[0]-$paths[1]");
                $controller_key                 = $this->getMd5($controller_key) . 'Controller';
                $lang_actions[$controller_key]  = $PMCNames['controller'];
                $config_actions[$package_key][$module_key][$controller_key] = $actions;
            }
        }

        return $config_actions;
    }

    /**
     * @param $actions
     * @param $whitelist
     */
    private function buildActions($actions, $whitelist)
    {
        /** 数据首页生成，列表 */
        $php_code   = '<?php' . PHP_EOL . PHP_EOL
                    . 'return [' . PHP_EOL
                    . $this->getTabs(1) . "'whitelist' => [" . implode(",\n", $whitelist) . "]," . PHP_EOL
                    . $this->getTabs(1) . "'actions' => "
                    . var_export($actions, true) . ','. PHP_EOL
                    . '];'
                    . PHP_EOL;

        $put        = $this->filesystem->put(config_path('actions.php'), $php_code);

        if ($put)
        {
            return $this->command->info('+ ./config/actions.php (Updated)');
        }

        return $this->command->error('x ./config/actions.php (Failed)');
    }

    /**
     * 获取 action key 值
     *
     * @param string $controller
     * @param string  $action
     * @return string
     */
    private function getActionKey($package_key, $controller, $action)
    {
        $controller  = str_replace(['\\', 'App-Http-Controllers-', 'Controller'], ['-', '', ''], $controller);

        return $package_key . '-' . $controller . '-' . $action;
    }

    /**
     * 获取 动作
     *
     * @return array
     */
    private function getActions()
    {
        $routes          = Route::getRoutes();
        $controllers     = [];
        foreach ($routes as $route)
        {
            $action_name = ltrim($route->getActionName(), '\\');
            if ( ! strstr($action_name, 'App\\Http\\Controllers\\'))
            {
                continue;
            }
            list($controller, $action)          = explode('@', $action_name);

            $controllers[$controller][$action]  = $action;
        }

        // 与 controller 里的 actions 求交集，以保证精准
        foreach ($controllers as $controller => &$actions)
        {
            $methods    = get_class_methods($controller);
            if (empty($methods))
            {
                unset($controllers[$controller]);
                continue;
            }

            $real_actions  = array_intersect($actions, $methods);
            $unset_actions = array_diff($actions, $real_actions);
            foreach ($unset_actions as $key)
            {
                unset($controllers[$controller][$key]);
            }
        }

        return $controllers;
    }

    /**
     * 16位 m5 加密
     *
     * @param $str
     *
     * @return bool|string
     */
    private function getMd5($str)
    {
        $str = str_replace('Controller', '', $str);
        if ($this->utility->getConfig('authorization.md5'))
        {
            if ($this->utility->getConfig('authorization.short_md5'))
            {
                return substr(md5($str), 8, 16);
            }
            else
            {
                return md5($str);
            }
        }

        return strtolower($str);
    }
}
