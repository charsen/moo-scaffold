<?php

namespace Charsen\Scaffold\Http\Controllers;

use Faker\Factory as Faker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

/**
 * Class     ApiController
 *
 * @package  Charsen\Scaffold\Http\Controllers
 * @author Charsen https://github.com/charsen
 */
class ApiController extends Controller
{

    /**
     * list view
     *
     */
    public function index(Request $req)
    {
        $data                       = $this->getApiList();
        $data['menus_transform']    = $this->getMenusTransform();
        $data['uri']                = $req->getPathInfo();

        $data['current_folder']     = $req->input('f', 'Index');
        $data['current_controller'] = $req->input('c', null);
        $data['current_action']     = $req->input('a', null);

        $data['first_menu_active']  = $data['current_controller'] != null;
        $data['first_table_active'] = $data['current_controller'] != null;

        return $this->view('api.index', $data);
    }

    /**
     * api view
     *
     * @param \Illuminate\Http\Request $req
     *
     * @return \Illuminate\View\View
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function show(Request $req)
    {
        $data              = $this->getOneApi($req, 'show');
        $data['namespace'] = $req->input('file', null);

        return $this->view('api.show', $data);
    }

    /**
     * @param Request $req
     *
     * @return \Illuminate\View\View
     */
    public function request(Request $req)
    {
        $data                       = $this->getApiList();
        $data['menus_transform']    = $this->getMenusTransform();
        $data['uri']                = $req->getPathInfo();
        //$data['request_url']        = str_replace($req->path(), 'api', $req->url());

        $data['api_index']          = 1;
        $data['current_folder']     = $req->input('f', 'Index');
        $data['current_controller'] = $req->input('c', null);
        $data['current_action']     = $req->input('a', null);
        $data['first_menu_active']  = false;

        if (isset($data['apis'][$data['current_folder']][$data['current_controller']][$data['current_action']]))
        {
            $current_method = $data['apis'][$data['current_folder']][$data['current_controller']][$data['current_action']]['method'];
        }

        $data['current_method']     = $current_method ?? false;

        return $this->view('api.request', $data);
    }

    /**
     * 缓存结果和参数
     *
     * @param \Illuminate\Http\Request $req
     */
    public function cache(Request $req)
    {
        $uri    = $req->input('uri', NULL);
        $params = $req->input('params', NULL);
        $result = $req->input('result', NULL);

        if ($uri != NULL && $params != NULL && $result != NULL)
        {
            unset($params['token']);

            $uri = md5(trim($uri, '/'));
            $put_params = Cache::store('file')->put($uri . '_params', $params, 30 * 24 * 60 * 60);
            $put_result = Cache::store('file')->put($uri . '_result', $result, 30 * 24 * 60 * 60);
        }
    }

    /**
     * @param Request $req
     *
     * @return \Illuminate\View\View
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function param(Request $req)
    {
        $data                = $this->getOneApi($req, 'request');
        $data['request_url'] = str_replace($req->path(), 'api', $req->url());
        //dump($data);

        $params              = ($data['request'][0] == 'GET') ? $data['url_params'] : $data['body_params'];

        // 从 cache 获取数据，并恢复到现有参数中
        $cache_params        = Cache::get(md5(trim($data['request'][1], '/')) . '_params', NULL);
        if ($cache_params != NULL)
        {
            foreach ($cache_params as $key => $val)
            {
                if (isset($params[$key]))
                {
                    $params[$key]['require']    = true;
                    $params[$key]['value']      = is_array($val) ? implode(',', $val) : $val;
                }
            }
        }

        if ($data['request'][0] == 'GET')
        {
            $data['url_params'] = $params;
        }
        else
        {
            $data['body_params'] = $params;
        }

        return $this->view('api.param', array_merge($data));
    }

    /**
     * 获取缓存的请求结果
     *
     * @param \Illuminate\Http\Request $req
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function result(Request $req)
    {
        $data                = $this->getOneApi($req, 'request');

        $rui                 = md5(trim($data['request'][1], '/'));
        $cache_result        = Cache::get($rui . '_result', NULL);

    }

    /**
     * 获取菜单转换名称数据
     *
     * @return array|mixed
     */
    private function getMenusTransform()
    {
        $yaml_file = $this->utility->getApiPath('schema') . '_menus_transform.yaml';
        if ( ! $this->filesystem->isFile($yaml_file))
        {
            return [];
        }

        return  (new Yaml)::parseFile($yaml_file);
    }

    /**
     * 获取 api 列表
     *
     * @return array
     */
    private function getApiList()
    {
        $yaml_files = $this->filesystem->allFiles($this->utility->getApiPath('schema'));

        $yaml  = new Yaml;
        $menus = [];
        $apis  = [];
        foreach ($yaml_files as $file)
        {
            if ($file->getBasename()== '_menus_transform.yaml')
            {
                continue;
            }

            $file_name = $file->getPathname();
            $path      = empty($file->getRelativePath()) ? 'Index' : $file->getRelativePath();
            $data      = $yaml::parseFile($file_name);

            $data['controller']['api_count']            = 0;
            $menus[$path][$data['controller']['class']] = $data['controller'];

            $temp = [];
            foreach ($data['actions'] as $action_name => $attr)
            {
                $temp[$action_name] = [
                    'name'   => $attr['name'],
                    'desc'   => $attr['desc'],
                    'method' => $attr['request'][0],
                    'url'    => $attr['request'][1],
                ];
                $menus[$path][$data['controller']['class']]['api_count']++;
            }
            $apis[$path][$data['controller']['class']] = $temp;
        }

        return ['menus' => $menus, 'apis' => $apis];
    }

    /**
     * @param $req
     *
     * @param $from_action
     *
     * @return mixed
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    private function getOneApi($req, $from_action)
    {
        $folder_name      = $req->input('f', 'Index');
        $folder_path      = $folder_name == 'Index' ? '' : $folder_name;
        $controller_class = $req->input('c', null);
        $action_name      = $req->input('a', null);

        // 判断文件是否存在
        $file = $this->utility->isApiFileExist($folder_path, $controller_class, 'schema');

        // 格式化 接口数据
        $yaml = new Yaml;
        $data = $yaml::parseFile($file);
        if (!isset($data['actions'][$action_name]))
        {
            throw new InvalidArgumentException('Invalid Action Argument (Not Found).');
        }
        $action_data                       = $data['actions'][$action_name];
        $action_data['request']            = $this->formatRequest($action_data['request']);
        $action_data['current_action']     = $action_name;
        $action_data['current_folder']     = $folder_path;
        $action_data['current_controller'] = $controller_class;

        // 12-09, 因为出现了同一个 url 多个 method ，api.yaml 的键名重复了，附加了方法，在这里特殊处理一下
        $action_name = $this->utility->removeActionNameMethod($action_name);

        // 针对 创建 及 更新 两个动作的原型做特殊处理
        if ($action_name == 'store' || $action_name == 'update')
        {
            $temp_name                = ($action_name == 'store') ? 'create' : 'edit';
            $action_data['prototype'] = empty($action_data['prototype']) && ! empty($data['actions'][$temp_name]['prototype'])
                                      ? $data['actions'][$temp_name]['prototype']
                                      : $action_data['prototype'];
        }

        // 字典数据
        $dictionaries   = $this->utility->getDictionaries();

        // 字段数据，从中获取字段格式，以便 faker 伪造数据
        $fields         = $this->utility->getFields();

        // i18n 润色
        $lang_fields    = $this->utility->getLangFields();

        if (! empty($req->cookie('api_token')) && $action_name != 'authenticate')
        {
            // 接口测试时从 cookie 中取值，若是文件则为空
            $param = ['Token', ($from_action == 'request' ? $req->cookie('api_token') : '')];
            if ($action_data['request'][0] == 'GET')
            {
                $action_data['url_params']['token'] = $param;
            }
            else
            {
                $action_data['body_params']['token'] = $param;
            }
        }

        // controllers, 从 repository 中获取验证规则的字段名，作为接口参数
        $controller        = 'App\Http\Controllers\\' . trim($folder_path . '/' . $controller_class . 'Controller', '/');
        $reflection_class  = new \ReflectionClass($controller);
        $request_object    = $this->utility->getActionRequestClass($action_name, $reflection_class);

        $rule_params       = [];
        if ( $request_object != null && ! empty($request_object->rules()))
        {
            $rule_params = $this->formatRules($action_name, $request_object->rules(), $dictionaries, $fields, $lang_fields);
        }

        $url_params     = $this->formatParams($action_data['url_params'], $dictionaries, $fields, $lang_fields);
        $body_params    = $this->formatParams($action_data['body_params'], $dictionaries, $fields, $lang_fields);


        if ($action_data['request'][0] == 'GET')
        {
            $url_params    = array_merge($rule_params, $url_params);
        }
        else
        {
            $method_rest = [
                'update'       => 'PATCH',
                'destroy'      => 'DELETE',
                'destroyBatch' => 'DELETE',
                'restoreBatch' => 'PATCH',
            ];
            $method_param = isset($method_rest[$action_name])
                ? ['_method' => ['require' => true, 'name' => '', 'value' => $method_rest[$action_name], 'desc' => '']]
                : [];

            $body_params   = array_merge($method_param, $rule_params, $body_params);
        }

        // 伪造数据
        $faker = Faker::create('zh_CN');
        $action_data['url_params']  = $this->formatToFaker($faker, $url_params);
        $action_data['body_params'] = $this->formatToFaker($faker, $body_params);

        return $action_data;
    }

    /**
     * 格式化请求参数
     *
     * @param $request
     *
     * @return array
     */
    private function formatRequest($request)
    {
        $method = strtoupper($request[0]) == 'GET' ? 'GET' : 'POST';
        $url    = $request[1];

        // 把 model 对象 转换为整数 1
        $url    = preg_replace('/\{[a-z_]+\}/i', 1, $url);

        return [strtoupper($method), $url];
    }

    /**
     * 转换为 faker 值
     *
     * @param  $faker
     * @param  array $params
     *
     * @return array
     */
    private function formatToFaker($faker, array $params)
    {
        if (empty($params)) return [];

        foreach ($params as $field_name => &$attr)
        {
            if ($attr['value'] != '' || $field_name == '_method')
            {
                continue;
            }
            // https://github.com/fzaninotto/Faker
            if (strstr($field_name, '_ids'))
            {
                $attr['value'] = $faker->numberBetween(1, 3) . ',' . $faker->numberBetween(4, 7);
            }
            elseif ($field_name == 'password' || strstr($field_name, '_password'))
            {
                $attr['value'] = $faker->password;
            }
            elseif ($field_name == 'address' || strstr($field_name, '_address'))
            {
                $attr['value'] = $faker->address;
            }
            elseif ($field_name == 'mobile' || strstr($field_name, '_mobile'))
            {
                $attr['value'] = $faker->phoneNumber;
            }
            elseif ($field_name == 'email' || strstr($field_name, '_email'))
            {
                $attr['value'] = $faker->unique()->safeEmail;
            }
            elseif ($field_name == 'user_name' || $field_name == 'nick_name')
            {
                $attr['value'] = $faker->userName;
            }
            elseif ($field_name == 'id_card_number')
            {
                $attr['value'] = '';
            }
            elseif ($field_name == 'logo' || strstr($field_name, '_logo'))
            {
                // 需要远程下载，影响加载速度
                //if ($attr['require'])
                //{
                //    $attr['value'] = $faker->image(public_path('uploads/temp'), 320, 320);
                //    $attr['value'] = str_replace(public_path(), '', $attr['value']);
                //}
            }
            elseif ($field_name == 'banner' || strstr($field_name, '_banner'))
            {
                // 需要远程下载，影响加载速度
                //if ($attr['require'])
                //{
                //    $attr['value'] = $faker->image(public_path('uploads/temp'), 750, 360);
                //    $attr['value'] = str_replace(public_path(), '', $attr['value']);
                //}
            }
            elseif ($field_name == 'real_name')
            {
                $attr['value'] = $faker->name(array_random(['male', 'female']));
            }
            elseif (strstr($field_name, '_code'))
            {
                $attr['value'] = $faker->numerify('C####');
            }
            elseif (in_array($attr['type'], ['int', 'tinyint', 'bigint']))
            {
                $attr['value'] = 1;
            }
            elseif ($attr['type'] == 'varchar' || $attr['type'] == 'char')
            {
                $attr['value'] = implode(' ', $faker->words(2));
            }
            elseif ($attr['type'] == 'text')
            {
                $attr['value'] = $faker->text(100);
            }
            elseif ($attr['type'] == 'date')
            {
                $attr['value'] = $faker->date();
            }
            elseif ($attr['type'] == 'datetime' || $attr['type'] == 'timestamp')
            {
                $attr['value'] = $faker->date() . ' ' . $faker->time();
            }
            elseif ($attr['type'] == 'boolean')
            {
                $attr['value'] = rand(0, 1);
                $attr['desc']  = '{1: true, 0: false}';
            }
        }

        return $params;
    }

    /**
     * 格式 验证规则 成为api参数
     *
     * @param       $action_name
     * @param array $rules
     * @param array $dictionaries
     * @param array $fields
     * @param array $lang_fields
     *
     * @return array
     */
    private function formatRules($action_name, array $rules, array $dictionaries, array $fields, array $lang_fields)
    {
        $data = [];
        foreach ($rules as $key => $attr)
        {
            $data[$key]                 = strstr($attr, 'nullable') ? ['require' => false] : ['require' => true];
            $data[$key]['name']         = $fields[$key]['zh-CN'] ?? $key;
            $data[$key]['value']        = '';
            $data[$key]['desc']         = '';

            if ($key == 'page')
            {
                $data[$key]['value']    = 1;
            }
            elseif ($key == 'page_limit')
            {
                $data[$key]['value']    = 10;
            }
            elseif ($key == 'ids')
            {
                $data[$key]['value']    = '2,3';
                $data[$key]['desc']     = '用,分割为数组';
            }
            elseif ($key == 'force')
            {
                $data[$key]['require']  = false;
                $data[$key]['name']     = (in_array($action_name, ['destroy', 'destroyBatch'])) ? '强制删除' : '强制';
                $data[$key]['value']    = 1;
                $data[$key]['desc']     = '{0: false, 1: true}';
            }

            if (isset($dictionaries[$key]))
            {
                $data[$key]['value'] = array_random(array_pluck($dictionaries[$key], 0));
                $data[$key]['desc'] .= ' ' . json_encode(array_pluck($dictionaries[$key], 2, 0), JSON_UNESCAPED_UNICODE);
            }

            if (isset($lang_fields[$key]))
            {
                $data[$key]['name'] = $lang_fields[$key]['zh-CN'];
            }

            $data[$key]['type'] = isset($fields[$key]['type']) ? $fields[$key]['type'] : null;
        }

        return $data;
    }

    /**
     * 格式化参数
     *
     * @param  array $params
     * @param  array $dictionaries
     * @param  array $fields
     * @param  array $lang_fields
     *
     * @return array
     */
    private function formatParams(array $params, array $dictionaries, array $fields, array $lang_fields)
    {
        if (empty($params)) return [];

        $data = [];
        foreach ($params as $key => $attr)
        {
            if ($key == '_method')
            {
                $data[$key] = ['require' => true, 'name' => '', 'value' => strtoupper($attr[0]), 'desc' => '兼容处理'];
                continue;
            }

            if ($attr[0] === false)
            {
                $data[$key] = ['require' => false, 'name' => $attr[1], 'value' => ($attr[2] ?? ''), 'desc' => ($attr[3] ?? '')];
            }
            else
            {
                $data[$key] = ['require' => true, 'name' => $attr[0], 'value' => $attr[1], 'desc' => ($attr[2] ?? '')];
            }

            if (isset($dictionaries[$key]))
            {
                $data[$key]['value'] = array_random(array_pluck($dictionaries[$key], 0));
                $data[$key]['desc'] .= ' ' . json_encode(array_pluck($dictionaries[$key], 2, 0), JSON_UNESCAPED_UNICODE);
            }

            if (isset($lang_fields[$key]))
            {
                $data[$key]['name'] = $lang_fields[$key]['zh-CN'];
            }

            $data[$key]['type'] = isset($fields[$key]['type']) ? $fields[$key]['type'] : null;
        }

        return $data;
    }
}
