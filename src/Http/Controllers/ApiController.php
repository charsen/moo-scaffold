<?php

namespace Charsen\Scaffold\Http\Controllers;

use Faker\Factory as Faker;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

/**
 * Class     ApiController
 *
 * @package  Charsen\Scaffold\Http\Controllers
 * @author   Charsen <780537@gmail.com>
 */
class ApiController extends Controller
{
    /**
     * @var array
     */
    private $table_style = ['red', 'orange', 'yellow', 'blue', 'olive', 'teal'];

    /**
     * list view
     *
     */
    public function index()
    {
        $data                       = $this->getApiList();
        $data['table_style']        = $this->table_style[array_rand($this->table_style)];
        $data['first_menu_active']  = false;
        $data['first_table_active'] = false;

        return $this->view('api.index', $data);
    }
    
    /**
     * api view
     *
     * @param \Illuminate\Http\Request $req
     *
     * @return \Illuminate\View\View
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
        $list                = $this->getApiList();
        $data                = $this->getOneApi($req, 'request');
        $data['request_url'] = str_replace($req->path(), 'api', $req->url());
        $data['api_index']   = 1;

        return $this->view('api.request', array_merge($data, $list));
    }

    /**
     * 获取 api 列表
     *
     * @return array
     */
    private function getApiList()
    {
        $yaml_files = $this->filesystem->allFiles($this->utility->getApiPath('schema'));
        //dump($yaml_files);

        $yaml  = new Yaml;
        $menus = [];
        $apis  = [];
        foreach ($yaml_files as $file)
        {
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
     * @return mixed
     */
    private function getOneApi($req, $from_action)
    {
        $folder_name      = $req->input('f', 'Index');
        $folder_path      = $folder_name == 'Index' ? '' : '' . $folder_name;
        $controller_class = $req->input('c', null);
        $action_name      = $req->input('a', null);

        // 判断
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

        // 字典数据
        $dictionaries = $this->utility->getDictionaries();

        // 字段数据，从中获取字段格式，以便 faker 伪造数据
        $fields = $this->utility->getFields();
        
        // 添加 cookie 到 body params
        if (! empty($req->cookie('api_token')) && $action_name != 'authenticate')
        {
            $action_data['body_params']['token'] = [
                'Api Token',
                ($from_action == 'request' ? $req->cookie('api_token') : '')
            ];
        }

        $url_params = $body_params = [];
        // 格式化 faker 标识
        $faker = Faker::create('zh_CN');
        if (isset($action_data['url_params']))
        {
            $url_params                = $this->formatParams($action_data['url_params'], $dictionaries, $fields);
            $action_data['url_params'] = $this->formatToFaker($faker, $url_params);
        }
        if (isset($action_data['body_params']))
        {
            $body_params                = $this->formatParams($action_data['body_params'], $dictionaries, $fields);
            $action_data['body_params'] = $this->formatToFaker($faker, $body_params);
        }
        //dump($action_data);

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
        $method = $request[0];
        $url    = $request[1];
    
        $url    = preg_replace('/\{[a-z_]+\}/i', rand(1, 5), $url);
        
        return [$method, $url];
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
        if (empty($params))
        {
            return [];
        }
        foreach ($params as $field_name => &$attr)
        {
            if ($attr[2] != '' || $field_name == '_method')
            {
                continue;
            }
            // https://github.com/fzaninotto/Faker
            if ($field_name == 'password' || strstr($field_name, '_password'))
            {
                $attr[2] = $faker->password;
            }
            elseif ($field_name == 'address' || strstr($field_name, '_address'))
            {
                $attr[2] = $faker->address;
            }
            elseif ($field_name == 'mobile' || strstr($field_name, '_mobile'))
            {
                $attr[2] = $faker->phoneNumber;
            }
            elseif ($field_name == 'email' || strstr($field_name, '_email'))
            {
                $attr[2] = $faker->unique()->safeEmail;
            }
            elseif ($field_name == 'user_name' || $field_name == 'nick_name')
            {
                $attr[2] = $faker->userName;
            }
            elseif ($field_name == 'real_name')
            {
                $attr[2] = $attr[2] = $faker->name(array_random(['male', 'female']));
            }
            elseif (strstr($field_name, '_code'))
            {
                $attr[2] = $faker->numerify('C####');
            }
            elseif (in_array($attr[4], ['int', 'tinyint', 'bigint']))
            {
                $attr[2] = $faker->numberBetween(1, 7);
            }
            elseif ($attr[4] == 'varchar' || $attr[4] == 'char')
            {
                $attr[2] = implode(' ', $faker->words(2));
            }
            elseif ($attr[4] == 'text')
            {
                $attr[2] = $faker->text(100);
            }
            elseif ($attr[4] == 'date')
            {
                $attr[2] = $faker->date('Y-m-d');
            }
            elseif ($attr[4] == 'datetime')
            {
                $attr[2] = $faker->datetime();
            }
        }

        return $params;
    }

    /**
     * 格式化参数
     *
     * @param  array $params
     * @param  array $dictionaries
     * @param  array $fields
     *
     * @return array
     */
    private function formatParams(array $params, array $dictionaries, array $fields)
    {
        $data = [];
        foreach ($params as $key => $attr)
        {
            if ($key == '_method')
            {
                $data[$key] = [true, '', strtoupper($attr[0]), '兼容处理'];
                continue;
            }

            if ($attr[0] === false)
            {
                $data[$key] = [false, $attr[1], ($attr[2] ?? ''), ($attr[3] ?? '')];
            }
            else
            {
                $data[$key] = [true, $attr[0], $attr[1], ($attr[2] ?? '')];
            }

            if (isset($dictionaries[$key]))
            {
                $data[$key][3] .= ' ' . json_encode(array_pluck($dictionaries[$key], 2, 0), JSON_UNESCAPED_UNICODE);
            }

            $data[$key][4] = isset($fields[$key]) ? $fields[$key]['type'] : null;
        }

        return $data;
    }
}
