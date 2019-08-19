<?php
namespace Charsen\Scaffold\Generator;

use Route;
use Symfony\Component\Yaml\Yaml;

/**
 * Create Api
 *
 * @author Charsen https://github.com/charsen
 */
class CreateApiGenerator extends Generator
{
    protected $api_path;
    protected $api_relative_path;
    protected $files_path;

    /**
     * @param      $namespace
     * @param bool $ignore_controller
     * @param bool $force
     *
     * @throws \ReflectionException
     */
    public function start($namespace, $ignore_controller = false, $force = false)
    {
        $this->api_path                 = $this->utility->getApiPath('schema');
        $this->api_relative_path        = $this->utility->getApiPath('schema', true);
        $this->files_path               = $this->api_path . $namespace . '/';

        // 获取路由列表，但 create 和 edit 两个动作，不一定有，
        $routes = $this->getRoutes($namespace);
        if (empty($routes))
        {
            return $this->command->error(' x Routes are not found.');
        }

        // 创建目录
        if (!$this->filesystem->isDirectory($this->files_path))
        {
            $this->filesystem->makeDirectory($this->files_path, 0777, true, true);
        }

        foreach ($routes as $controller_name => $actions)
        {
            $controller       = 'App\Http\Controllers\\' . str_replace('/', '\\', $namespace) . "\\{$controller_name}Controller";
            $reflection_class = new \ReflectionClass($controller);

            // 过滤掉当前控制器 - 路由里多余的 action
            // 由于用了 `Route::resources`，实际controller中可能没那么多action
            if ( ! $ignore_controller)
            {
                $methods    = get_class_methods($controller);
                if (empty($methods)) {
                    return $this->command->error(' x Controller\'s action  are not found.');
                }

                $real_actions  = array_intersect(array_keys($actions), $methods);
                $unset_actions = array_diff(array_keys($actions), $real_actions);
                foreach ($unset_actions as $key) {
                    unset($actions[$key]);
                }
            }

            $api_yaml          = $this->files_path . $controller_name . '.yaml';
            $api_relative_yaml = $this->api_relative_path . $namespace . '/' . $controller_name . '.yaml';
            if ($this->filesystem->isFile($api_yaml) && !$force)
            {
                $this->append($api_yaml, $api_relative_yaml, $actions, $reflection_class);
            }
            else
            {
                $this->create($api_yaml, $api_relative_yaml, $controller_name, $actions, $reflection_class);
            }
        }

        return true;
    }

    /**
     * 获取默认的 action 用于排序输出
     *
     * @return array
     */
    private function getDefaultActions()
    {
        return ['create', 'edit', 'index', 'trashed', 'store', 'update', 'show', 'destroy', 'destroyBatch', 'restore'];
    }

    /**
     * @param $api_yaml
     * @param $api_relative_yaml
     * @param $actions
     * @param $reflection_class
     *
     * @return mixed
     */
    private function append($api_yaml, $api_relative_yaml, $actions, $reflection_class)
    {
        $yaml             = new Yaml;
        $old_data         = $yaml::parseFile($api_yaml);
        $old_actions_keys = array_keys($old_data['actions']);
        $old_actions_keys = $this->utility->removeActionNameMethod($old_actions_keys);

        $actions_keys     = array_keys($actions);
        $reduce_data      = array_merge(array_diff($old_actions_keys, $actions_keys));
        $add_data         = array_merge(array_diff($actions_keys, $old_actions_keys));

        if (empty($reduce_data) && empty($add_data))
        {
            return $this->command->warn('+ ' . $api_relative_yaml . ' (Nothing Changed)');
        }
        if (!empty($reduce_data))
        {
            $this->command->error('- ' . $api_relative_yaml . ', Actions [ ' . implode(',', $reduce_data) . ' ] need to be reduced.');
        }
        if (empty($add_data))
        {
            return $this->command->warn('- ' . $api_relative_yaml . ' (Not Increase)');
        }

        $code               = [];
        $appends            = [];
        foreach ($add_data as $action_name)
        {
            $this->buildOneRequest($code, $reflection_class, $action_name, $actions[$action_name]['methods'], $actions[$action_name]['uri']);
            $appends[]      = $action_name;
        }

        $code[]     = '';
        $put        = $this->filesystem->append($api_yaml, implode("\n", $code));
        if ($put)
        {
            $msg    = ' (Append ' . count($appends) . ' Actions: [' . implode(', ', $appends) . '])';
            return $this->command->info('+ ' . $api_relative_yaml . $msg);
        }

        return $this->command->error('+ ' . $api_relative_yaml . '(Update Failed)');
    }

    /**
     * 创建一个新的接口 schema 文件
     *
     * @param $api_yaml
     * @param $api_relative_yaml
     * @param $controller_name
     * @param $actions
     * @param $reflection_class
     *
     * @return void [type]
     */
    private function create($api_yaml, $api_relative_yaml, $controller_name, $actions, $reflection_class)
    {
        $names = $this->utility->parsePMCNames($reflection_class);
        $name  = empty($names['controller']['name']) ? '' : $names['controller']['name']['zh-CN'];

        $code   = ['###'];
        $code[] = "# {$controller_name} Api";
        $code[] = '#';
        $code[] = '# @author ' . $this->utility->getConfig('author');
        $code[] = '# @data ' . date('Y-m-d H:i:s');
        $code[] = '##';
        $code[] = 'controller:';
        $code[] = $this->getTabs(1) . 'code:';
        $code[] = $this->getTabs(1) . 'class: ' . $controller_name;
        $code[] = $this->getTabs(1) . 'name: ' . $name;
        $code[] = $this->getTabs(1) . 'desc: []';
        $code[] = 'actions:';

        // todo: 用排序解决优先生成问题
        $default_actions = $this->getDefaultActions();
        foreach ($default_actions as $action_name)
        {
            if (isset($actions[$action_name]))
            {
                $this->buildOneRequest(
                    $code,
                    $reflection_class,
                    $action_name,
                    $actions[$action_name]['methods'],
                    $actions[$action_name]['uri']
                );
                unset($actions[$action_name]);
            }
        }

        if ( ! empty($actions))
        {
            foreach ($actions as $action_name => $attr)
            {
                $this->buildOneRequest($code, $reflection_class, $action_name, $attr['methods'], $attr['uri']);
            }
        }

        $code[] = '';
        $put    = $this->filesystem->put($api_yaml, implode("\n", $code));
        if ($put)
        {
            return $this->command->info('+ ' . $api_relative_yaml . ' (Created)');
        }

        return $this->command->error('+ ' . $api_relative_yaml . ' (Create Failed)');
    }

    /**
     * @param $code
     * @param $reflection_class
     * @param $action_name
     * @param $methods
     * @param $uri
     *
     * @return array
     */
    private function buildOneRequest(&$code, $reflection_class, $action_name, $methods, $uri)
    {
        $method_txt     = ['PATCH' => 'POST', 'PUT' => 'POST', 'DELETE' => 'POST', 'GET' => 'GET', 'POST' => 'POST'];

        foreach ($methods as $method)
        {
            $code[] = $this->getTabs(1) . "{$action_name}_" . strtolower($method) . ":";
            $code[] = $this->getTabs(2) . "name: " . $this->getActionName($reflection_class, $action_name);
            $code[] = $this->getTabs(2) . 'desc: []';
            $code[] = $this->getTabs(2) . "prototype: ''";
            $code[] = $this->getTabs(2) . "request: [{$method_txt[$method]}, {$uri}]";
            $code[] = $this->getTabs(2) . 'url_params: []';
            $code[] = $this->getTabs(2) . 'body_params: []';
        }

        return $code;
    }

    /**
     * 获取 动作名
     *
     * @param $action_name
     * @param $reflection_class
     *
     * @return mixed|string
     */
    private function getActionName($reflection_class, $action_name)
    {
        $default_names  = ['create' => '创建表单', 'edit' => '编辑表单'];

        if (isset($default_names[$action_name]))
        {
            $name       = $default_names[$action_name];
        }
        else
        {
            $name       = $this->utility->parseActionNames($action_name, $reflection_class);
            if (! isset($name['name']))
            {
                $name   = $this->utility->parseActionName($action_name, $reflection_class);
            }
            else
            {
                $name       = $name['name']['zh-CN'];
            }
        }

        return empty($name) ? $action_name : $name;
    }

    /**
     * 从 laravel 路由列表中解析出当前模块的控制器和动作
     *
     * @param  string $namespace
     *
     * @return array
     */
    private function getRoutes($namespace)
    {
        $routes = Route::getRoutes();

        $data = [];
        foreach ($routes as $route)
        {
            // 过滤掉不是 api 的
            if ( ! preg_match('/^api\/(.*)/', $route->uri()))
            {
                continue;
            }

            $namespace   = str_replace('/', '\\\\', $namespace);    // 多级目录时，需要转换一下
            $pre_pattern = 'App\\\\Http\\\\Controllers\\\\' . $namespace . '\\\\';
            // 过滤掉不是指定 namespace 的
            $action_name = ltrim($route->getActionName(), '\\');
            if ( ! preg_match('/^' . $pre_pattern .'([a-zA-Z]+)Controller@([a-zA-Z]+)/', $action_name))
            {
                continue;
            }

            // 正则匹配出 controller 和 action 名称
            preg_match('/^' . $pre_pattern . '([a-zA-Z]+)Controller@([a-zA-Z]+)/', $action_name, $result);

            //$method                       = implode('|', $route->methods());
            //$method                       = ($method == 'GET|HEAD') ? 'GET' : $method;
            //$method                       = ($method == 'PUT|PATCH') ? 'PUT' : $method;
            $methods = $route->methods();
            $delete_keys = ['HEAD', 'PATCH'];
            foreach ($delete_keys as $val)
            {
                $key = array_search($val, $methods);
                if ($key)
                {
                    unset($methods[$key]);
                }
            }

            $data[$result[1]][$result[2]] = [
                'name'   => $route->getName(),
                'uri'    => str_replace('api/', '', $route->uri()),
                //'method' => $method,
                'methods' => $methods,
            ];
        }

        return $data;
    }
}
