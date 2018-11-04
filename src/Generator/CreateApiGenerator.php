<?php
namespace Charsen\Scaffold\Generator;

use InvalidArgumentException;
use Route;
use Symfony\Component\Yaml\Yaml;

/**
 * Create Api
 *
 * @author Charsen <780537@gmail.com>
 */
class CreateApiGenerator extends Generator
{
    protected $api_path;
    protected $api_relative_path;
    protected $unknow_fields;
    
    /**
     * @param $namespace
     * @param $force
     */
    public function start($namespace, $force = false)
    {
        $this->api_path                 = $this->utility->getApiPath('schema');
        $this->api_relative_path        = $this->utility->getApiPath('schema', true);
        $this->unknow_fields            = [];

        // 获取路由列表，但 create 和 edit 两个动作，不一定有，
        $routes = $this->getRoutes($namespace);
        if (empty($routes))
        {
            throw new InvalidArgumentException('Controllers are not found.');
        }

        // 获取所有字段
        $fields = $this->utility->getFields();

        // 获取对应的 repository_class, table_name, model_class
        $controllers = $this->utility->getControllers();

        foreach ($routes as $controller_name => $actions)
        {
            // 过滤掉当前控制器 - 路由里多余的 action
            $controller    = 'App\Http\Controllers\\' . "{$namespace}\\{$controller_name}" . 'Controller';
            $methods       = get_class_methods($controller);
            $real_actions  = array_intersect(array_keys($actions), $methods);
            $unset_actions = array_diff(array_keys($actions), $real_actions);
            foreach ($unset_actions as $key)
            {
                unset($actions[$key]);
            }

            $rules      = [];
            $table_name = '';
            if (isset($controllers["{$namespace}/{$controller_name}"]))
            {
                $repository = 'App\Repositories\\' . $controllers["{$namespace}/{$controller_name}"]['repository_class'] . 'Repository';
                $rules      = (new $repository(app()))->getRules();
                //dump($rules);

                $table      = $this->utility->getOneTable($controllers["{$namespace}/{$controller_name}"]['table_name']);
                $table_name = $table['name'];
                // dump($table_name);
            }

            $api_yaml          = $this->api_path . $namespace . '/' . $controller_name . '.yaml';
            $api_relative_yaml = $this->api_relative_path . $namespace . '/' . $controller_name . '.yaml';
            if ($this->filesystem->isFile($api_yaml) && !$force)
            {
                $this->append($api_yaml, $api_relative_yaml, $controller_name, $actions, $rules, $fields, $table_name);
            }
            else
            {
                $this->create($api_yaml, $api_relative_yaml, $controller_name, $actions, $rules, $fields, $table_name);
            }
        }

        if (!empty($this->unknow_fields))
        {
            $this->command->error('* unknow_fields: ' . implode(', ', $this->unknow_fields));
        }
    }
    
    /**
     * @param $api_yaml
     * @param $api_relative_yaml
     * @param $actions
     * @param $rules
     * @param $fields
     * @param $table_name
     *
     * @return mixed
     */
    private function append($api_yaml, $api_relative_yaml, $controller_name, $actions, $rules, $fields, $table_name)
    {
        $yaml             = new Yaml;
        $old_data         = $yaml::parseFile($api_yaml);
        $old_actions_keys = array_keys($old_data['actions']);
        $actions_keys     = array_keys($actions);
        $reduce_data      = array_merge(array_diff($old_actions_keys, $actions_keys));
        $add_data         = array_merge(array_diff($actions_keys, $old_actions_keys));
        //var_dump($add_data);
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
            return $this->command->warn('- ' . $api_relative_yaml . ', Did not increase.');
        }

        $code          = [];
        $actions_count = 0;
        foreach ($add_data as $action_name)
        {
            $this->buildOneRequest($code, $rules, $fields, $table_name, $action_name,
                $actions[$action_name]['method'], $actions[$action_name]['uri']);
            $actions_count++;
        }

        $code[] = '';
        $put    = $this->filesystem->append($api_yaml, implode("\n", $code));
        if ($put)
        {
            return $this->command->info('+ ' . $api_relative_yaml . ' (Append ' . $actions_count . ' Actions)');
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
     * @param $rules
     * @param $fields
     * @param $table_name
     *
     * @return void [type]
     */
    private function create($api_yaml, $api_relative_yaml, $controller_name, $actions, $rules, $fields, $table_name)
    {
        $code   = ['###'];
        $code[] = '# 一个 controller 一个 api.schema 文件';
        $code[] = '##';
        $code[] = 'controller:';
        $code[] = $this->getTabs(1) . 'code:';
        $code[] = $this->getTabs(1) . 'class: ' . $controller_name;
        $code[] = $this->getTabs(1) . 'name: ' . $controller_name;
        $code[] = $this->getTabs(1) . 'desc: []';
        $code[] = 'actions:';

        foreach ($actions as $action_name => $attr)
        {
            $this->buildOneRequest($code, $rules, $fields, $table_name, $action_name, $attr['method'], $attr['uri']);
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
     * @param $rules
     * @param $fields
     * @param $table_name
     * @param $action_name
     * @param $method
     * @param $uri
     *
     * @return array
     */
    private function buildOneRequest(&$code, $rules, $fields, $table_name, $action_name, $method, $uri)
    {
        $method_txt = ['PUT' => 'POST', 'DELETE' => 'POST', 'GET' => 'GET', 'POST' => 'POST'];
        $name_txt   = [
            'index'   => '{table_name}列表',
            'show'    => '查看{table_name}',
            'store'   => '添加{table_name}',
            'update'  => '更新{table_name}',
            'destroy' => '删除{table_name}',
        ];
        $name   = isset($name_txt[$action_name]) ? str_replace('{table_name}', $table_name, $name_txt[$action_name]) : $action_name;
        $code[] = $this->getTabs(1) . "{$action_name}:";
        $code[] = $this->getTabs(2) . "name: {$name}";
        $code[] = $this->getTabs(2) . 'desc: []';
        $code[] = $this->getTabs(2) . "request: [{$method_txt[$method]}, {$uri}]";

        // controller store action 需要转换一下
        $rule_key = $action_name == 'store' ? 'create' : $action_name;

        // GET 请求，参数放到 url_params
        if ($method == 'GET')
        {
            if (!isset($rules[$rule_key]))
            {
                $code[] = $this->getTabs(2) . 'url_params: []';
            }
            else
            {
                $code[] = $this->getTabs(2) . 'url_params:';
                $this->buildRequestParams($code, $rules[$action_name], $fields);
            }
            $code[] = $this->getTabs(2) . 'body_params: []';
        }
        else
        {
            $code[]      = $this->getTabs(2) . 'url_params: []';
            $body_params = [$this->getTabs(2) . 'body_params:'];
            
            if (in_array($method, ['PUT', 'DELETE']))
            {
                $body_params[] = $this->getTabs(3) . "_method: [{$method}]";
            }

            if (isset($rules[$rule_key]))
            {
                $this->buildRequestParams($body_params, $rules[$rule_key], $fields);
            }
            
            if ( ! isset($body_params[1]))
            {
                $body_params[0] .= ' []';
            }
            
            $code = array_merge($code, $body_params);
        }

        return $code;
    }
    
    /**
     * 生成 请求参数配置
     *
     * @param  array &$code
     * @param  array $rules
     * @param  array $fields
     *
     * @return void
     */
    private function buildRequestParams(array &$code, array $rules, array $fields)
    {
        //position_ids: [岗位ID, ':in:2,4', 数组，关联部门ID]
        foreach ($rules as $field_name => $rule)
        {
            $rule_txt = [];
            // 是否必填
            if (!strstr($rule, 'required') && !strstr($rule, 'sometimes'))
            {
                $rule_txt[] .= 'false';
            }

            // 参数名称
            $rule_txt[] = isset($fields[$field_name]['cn']) ? $fields[$field_name]['cn'] : $field_name;

            // 数值
            $rule_txt[] = !empty($fields[$field_name]['default']) ? $fields[$field_name]['default'] : "''";

            // 描述
            if (!empty($fields[$field_name]['desc']))
            {
                $rule_txt[] = "'{$fields[$field_name]['desc']}'";
            }

            //dump($rule_txt);
            $code[] = $this->getTabs(3) . "{$field_name}: [" . implode(', ', $rule_txt) . ']';
        }
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
            if (!preg_match('/^api\/(.*)/', $route->uri()))
            {
                continue;
            }
            
            $pre_pattern = 'App\\\\Http\\\\Controllers\\\\' . $namespace . '\\\\';
            // 过滤掉不是指定 namespace 的
            $action_name = ltrim($route->getActionName(), '\\');
            if (!preg_match('/^' . $pre_pattern .'(.*)/', $action_name))
            {
                continue;
            }

            // 正则匹配出 controller 和 action 名称
            preg_match('/^' . $pre_pattern . '([a-z]+)Controller@([a-z]+)/i', $action_name, $result);
            $method                       = implode('|', $route->methods());
            $method                       = ($method == 'GET|HEAD') ? 'GET' : $method;
            $method                       = ($method == 'PUT|PATCH') ? 'PUT' : $method;
            $data[$result[1]][$result[2]] = [
                'name'   => $route->getName(),
                'uri'    => str_replace('api/', '', $route->uri()),
                'method' => $method,
            ];
        }

        return $data;
    }
}
