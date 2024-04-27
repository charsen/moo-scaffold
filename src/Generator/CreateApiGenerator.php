<?php

namespace Mooeen\Scaffold\Generator;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use Mooeen\Scaffold\Models\MooApi;

/**
 * Create Api
 *
 * @author Charsen https://github.com/charsen
 */
class CreateApiGenerator extends Generator
{
    private string $app;

    private array $controllers;

    /**
     * @throws \ReflectionException
     */
    public function start(string $app, string $folder, array $routes): bool
    {
        $this->app   = $app;
        $controllers = $this->updateDB($routes);

        // 推送新数据
        // - 字段值明明不相等(一个空，一个不空)，但是判断不相等的时候就是得不到TRUE。
        // - 并不是<>不稳定，而是字段值为NULL是，不能使用=或者<>比较值，应该使用IS NULL判断是否为空。
        // - 把空理解为未知,一个非空和未知比较,结果还是未知.
        foreach ($controllers as $controller) {
            $updates = MooApi::where('api_controller', $controller)
                             ->whereRaw('`api_status`!=`x_status`')
                             ->orWhereNull('x_status')
                             ->get();
            // dump($updates->toArray());
            foreach ($updates as $update) {
                $this->pushApi($update);
            }
        }

        return true;
    }

    /**
     * 更新数据库
     */
    private function updateDB(array $routes): array
    {
        // dump($routes);
        $res_controllers   = [];
        $check_controllers = [];
        foreach ($routes as $item) {
            $item['method']       = str_replace('GET|HEAD', 'GET', $item['method']);
            $item['operation_id'] = str_replace(['App\\', 'Controllers\\', '\\', '@'], ['', '', '_', '_'], $item['action']) . "_{$item['method']}";
            $exist                = MooApi::where('api_operation_id', $item['operation_id'])->first();
            $new_data             = $this->getNewApiData($item);
            $res_controllers[]    = $new_data['api_controller'];

            if ($exist) {
                $check_controllers[$new_data['api_controller']][] = $exist->id; // 保存下已存在的 id

                // 有可能 n 久前写了个接口，后来有删除了，现在又写了一个同名的
                // 这时: api_status = 'deprecated', x_status = null or 'deprecated'
                // 更新: api_status = 'released' , 后续的流程才能重新发布到 x 远程平台
                if ($exist->api_status !== 'released') {
                    $exist->api_status = 'released';
                }

                if ($this->checkParameterChanges($exist, $new_data)) {
                    $exist->x_status       = null;
                    $exist->api_parameters = $new_data['api_parameters'];
                }

                $check_fields = ['api_name', 'api_summary', 'api_description', 'api_uri', 'api_route_name'];
                foreach ($check_fields as $field) {
                    if ($exist->{$field} !== $new_data[$field]) {
                        $exist->{$field} = $new_data[$field];
                        $exist->x_status = null;
                    }
                }

                if ($exist->isDirty()) {
                    $exist->save();
                }
            } else {
                MooApi::create($new_data);
            }

            // break;
        }

        // 1、新增后，由于是自动生成的，可能接口会多，手动删除 controller 中的 action 后，实际的接口变少了
        // 这时: api_status = 'released' , x_status = null
        // 2、查找出，已 push 到 x 远程平台上，但这次不在 routes 中（已删除）的接口
        // 这时：x_status = 'released' , x_status = 'released'
        //
        // 更新: api_status = 'deprecated' , 后续的流程才能重新发布到 x 远程平台
        foreach ($check_controllers as $controller => $ids) {
            if (empty($ids)) {
                continue;
            }

            $destroys = MooApi::where('api_controller', $controller)->whereNotNull('x_created_at')->whereNotIn('id', $ids)->get();
            foreach ($destroys as $destroy) {
                $destroy->api_status = 'deprecated';
                $destroy->x_status   = null;
                $destroy->save();
            }
        }

        return $res_controllers;
    }

    /**
     * 检查 请求参数 是否发生变化
     */
    private function checkParameterChanges(Model $model, array $new_data): bool
    {
        // 请求变量是否发生变化了，变化了，则重置 x 远程平台状态，以便后续发起更新
        $db_parameter_keys = array_keys($model->api_parameters);
        $parameters        = $new_data['api_parameters'];
        $parameter_keys    = array_keys($parameters);

        // 先判断 字段是否有变化
        if (collect($db_parameter_keys)->diff($parameter_keys)->isNotEmpty() or collect($parameter_keys)->diff($db_parameter_keys)->isNotEmpty()) {
            return true;
        }

        // 对比具体的规则是否变化了
        foreach ($model->api_parameters as $field => $rules) {
            if (collect($rules)->diff($parameters[$field])->isNotEmpty()) {
                return true;
            }
        }

        foreach ($parameters as $field => $rules) {
            if (collect($rules)->diff($model->api_parameters[$field])->isNotEmpty()) {
                return true;
            }
        }

        return false;
    }

    /**
     * 通过 控制器动作 指定的 Request 规则，获取 请求参数
     */
    private function getActionParameters(array $route): array
    {
        [$controller, $action] = explode('@', $route['action']);
        $request               = $this->utility->getActionRequestClass($this->getMethod($controller, $action));

        return ($request === null or ! method_exists($request, 'rules'))
            ? []
            : $request->rules();
    }

    /**
     * 获取 新接口数据
     */
    private function getNewApiData(array $route): array
    {
        // dump($route);
        [$controller, $action] = explode('@', $route['action']);

        $PMC_names = $this->utility->parsePMCNames($this->getController($controller));
        $x_folder  = $PMC_names['module']['name']['zh-CN'] . '/'
                    . $PMC_names['controller']['name']['zh-CN'];

        // 如果是多个 api 项目配置，则可以省略以及目录，否则加上顶级目录名称
        if (! $this->checkXConfigMultiple()) {
            $x_folder = $PMC_names['package']['name']['zh-CN'] . '/' . $x_folder;
        }

        $action_info = $this->utility->parseActionInfo($this->getMethod($controller, $action));

        return [
            'app_name'           => $this->app,
            'api_operation_id'   => $route['operation_id'],
            'api_name'           => $this->utility->parseActionName($this->getMethod($controller, $action)),
            'api_uri'            => $route['uri'],
            'api_summary'        => $action_info['whitelist'] ? '<whitelist>' : '',
            'api_description'    => '',
            'api_request_method' => $route['method'],
            'api_route_name'     => $route['name'],
            'api_controller'     => $controller,
            'api_action'         => $action,
            'api_parameters'     => $this->getActionParameters($route),
            'api_status'         => 'released', // 与 x_status 不一致时，需要更新 x 远程平台
            'x_folder'           => trim($x_folder, '/'),
        ];
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
     * 推送 api 数据到 x 远程平台
     */
    private function pushApi(Model $model): void
    {
        $project_id = $this->utility->getConfig("api_fox.{$this->app}.project_id");
        $token      = $this->utility->getConfig("api_fox.{$this->app}.token");
        $url        = "https://api.apifox.cn/api/v1/projects/{$project_id}/import-data";
        // $url = "https://api.apifox.com/api/v1/projects/{$project_id}/http-apis";

        $headers = [
            'X-Apifox-Version' => '2024-01-20',
            'Content-Type'     => 'application/json',
            'Authorization'    => 'Bearer ' . $token,
        ];

        $params = [
            'importFormat' => 'openapi',
            // 'importBasePath'   => 'false', // 是否在接口路径加上basePath, 建议不传，即为 false，推荐将 BasePath 放到环境里的”前置 URL“里
            'importFullPath' => 'false',
            // 'apiFolderId'    => '0', // 导入到目标目录的ID, 不传表示导入到根目录
            // 'schemaFolderId'   => '0',
            'apiOverwriteMode' => 'methodAndPath', // 匹配到相同接口时的覆盖模式，不传表示忽略 {methodAndPath: 覆盖, merge: 智能合并, both: 保存两者}
            'syncApiFolder'    => true, // 是否同步更新接口所在目录
            // 'url'              => '',  // 数据源 URL，当同时传递 url 和 data 的参数时，会优先使用 url 的数据作为导入数据源
            // 'auth'          => '{"username": "admin", "password":"123456"}', // 数据源 URL 授权用户名和密码
        ];

        // $tags = array_map(static fn ($item) => ['name' => $item], explode('/', $model->x_folder));
        // dump($tags);

        $api_data = [
            'openapi' => '3.1.0',
            'paths'   => [
                "/{$model->api_uri}" => [
                    strtolower($model->api_request_method) => [
                        'tags'            => explode('/', $model->x_folder),
                        'operationId'     => $model->api_operation_id,
                        'x-apifox-status' => $model->api_status, // { released, deprecated }
                        'deprecated'      => $model->api_status === 'deprecated',
                        'x-apifox-folder' => $model->x_folder,
                        'summary'         => $model->api_name,
                        'description'     => '',
                        'parameters'      => $this->getParameters($model),
                        'requestBody'     => $this->getRequestBody($model),
                        'responses'       => $this->getResponseConfig($model->api_action),
                    ],
                ],
            ],
        ];
        // dump($api_data);

        try {
            //$api_data['parameters'] = array_merge($api_data['parameters'], $api_header);
            $params['data'] = json_encode($api_data, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->command->error('Json Encode Exception');
        }

        // asForm()->  // 使用 application/x-www-form-urlencoded 作为请求的数据类型
        $response = Http::withHeaders($headers)->post($url, $params);
        if ($response->successful()) {
            $model->x_version       = $headers['X-Apifox-Version'];
            $model->x_import_format = $params['importFormat'];
            $model->x_status        = $model->api_status;
            if ($model->x_created_at !== null) {
                $model->x_updated_at = now();
                $model->x_updated_times++;
            } else {
                $model->x_created_at = now();
            }
            $model->save();

            $this->command->info("+ {$model->api_name}: {$model->api_uri}");
        }
        // dump($response->status());
        // dump($response->headers());
        // dump($response->body());
        // dump(json_decode($response->body(), false, 512, JSON_THROW_ON_ERROR));
    }

    private function getRequestBody(Model $model): array
    {
        // type: { application/x-www-form-urlencoded | multipart/form-data }
        // multipart/form-data 不支持 PUT 和 PATCH
        // 用 multipart/form-data 则需要在参数中增加一个 _method = 'PUT' 来指定为 PUT
        $type = 'application/x-www-form-urlencoded';
        $res  = [
            'content' => [
                $type => [
                    'schema' => [
                        'type'       => 'object',
                        'properties' => [],
                        'required'   => [],
                    ],
                ],
            ],
        ];
        $properties = [];
        $required   = [];

        if ($model->api_request_method !== 'GET') { // in_array($model->api_request_method, ['POST', 'PUT', 'DELETE'])
            foreach ($model->api_parameters as $field => $rules) {
                $value_type         = $this->getParameterType($field, $rules);
                $properties[$field] = [
                    'description' => __('validation.attributes.' . $field),
                    'type'        => $value_type,
                    'example'     => $this->getParameterExample($field, $value_type, $rules),
                ];

                if (! in_array('nullable', $rules, true)) {
                    $required[] = $field;
                }
            }

            $res['content'][$type]['schema']['properties'] = $properties;
            $res['content'][$type]['schema']['required']   = $required;
        }

        return empty($properties) ? [] : $res;
    }

    /**
     * 获取 api 调试平台的请求参数的数据格式 openapi 3.1.0
     */
    private function getParameters(Model $model): array
    {
        $res = [];

        // 除了制定排除的外，都要加 header Authorization
        if (! in_array("{$model->api_controller}@{$model->api_action}", $this->utility->getConfig('authorization.exclude_actions'), true)) {
            $res[] = [
                'name'        => 'Authorization',
                'in'          => 'header',
                'description' => 'Bearer 个人访问令牌',
                'required'    => true,
                'schema'      => ['type' => 'string'],
                'example'     => 'Bearer {{access_token}}',
            ];
        }

        // edit, update, delete 的网址中包含 id 变量值
        if (str_contains($model->api_uri, '{id}')) {
            $res[] = [
                'name'        => 'id',
                'in'          => 'path',
                'description' => 'ID',
                'required'    => true,
                'schema'      => ['type' => 'integer'],
            ];
        }

        if ($model->api_request_method === 'GET') {
            foreach ($model->api_parameters as $field => $rules) {
                $value_type = $this->getParameterType($field, $rules);
                $res[]      = [
                    'name'        => $field,
                    'in'          => 'query',
                    'description' => __('validation.attributes.' . $field),
                    'required'    => ! in_array('nullable', $rules, true),
                    'schema'      => ['type' => $value_type],
                    'example'     => $this->getParameterExample($field, $value_type, $rules),
                ];
            }
        }

        return $res;
    }

    /**
     * 获取 api 调试平台的请求参数的数据类型
     */
    private function getParameterType(string $field, array $rules): mixed
    {
        if (in_array('integer', $rules, true) or in_array('numeric', $rules, true)) {
            return 'integer';
        }

        if (in_array('string', $rules, true)) {
            return 'string';
        }

        return 'string';
    }

    /**
     * 获取 api 调试平台的请求参数的默认值/示例值
     */
    private function getParameterExample(string $field, string $value_type, array $rules): mixed
    {
        $min = 3;
        $max = 16;
        foreach ($rules as $rule) {
            if (Str::startsWith($rule, 'in:')) {
                // {% mock 'pick', '[1,2,3,4]' %}
                return "{% mock 'pick', '[" . str_replace('in:', '', $rule) . "]' %}";
            }

            if (Str::endsWith($rule, 'min:')) {
                $min = str_replace('min:', '', $rule);
            }

            if (Str::endsWith($rule, 'min:')) {
                $max = str_replace('max:', '', $rule);
            }
        }

        if (str_contains($field, 'password')) {
            return "{% mock 'string', '', 6, 12 %}";
        }

        if (Str::endsWith($field, '_code')) {
            //return "{% mock 'string', 'lower/upper', {$min}, {$max} %}";
            return "{% mock 'title', {$min}, {$max} %}";
        }

        if ($field === 'language') {
            return 'zh-CN';
        }

        if ($field === 'page') {
            return 1;
        }

        if ($field === 'page_limit') {
            return "{% mock 'integer', 5, 15 %}";
        }

        // TODO: get some ids ？
        if ($field === 'ids') {
            return '';
        }

        // TODO: get one id ？
        // 解析 controller 构造器的 model，random 一条
        if (Str::endsWith($field, '_id')) {
            return '';
        }

        // 中文字符串
        return "{% mock 'ctitle', {$min}, {$max} %}";
    }

    /**
     * 获取 api 调试平台的请求结构的数据结构
     */
    private function getResponseConfig(string $action): array
    {
        $codes = ['index' => 200, 'store' => 201];
        $code  = $codes[$action] ?? 200;

        return [
            $code => [
                'description' => '成功',
                'content'     => [
                    'application/json' => [
                        'schema' => [
                            'type'       => 'object',
                            'properties' => [
                                // 'data' => [
                                //     'anyOf' => [
                                //         [
                                //             'type'  => 'array',
                                //             'items' => [
                                //                 'type' => ['string', 'integer', 'boolean', 'array', 'object', 'number', 'null'],
                                //             ],
                                //         ],
                                //         [
                                //             'type'                       => 'object',
                                //             'additionalProperties'       => false,
                                //             'x-apifox-orders'            => [],
                                //             'properties'                 => [],
                                //             'x-apifox-ignore-properties' => [],
                                //         ],
                                //     ],
                                // ],
                                // links
                                // meta
                                // columns
                                // form_widgets
                            ],
                            'x-apifox-ignore-properties' => [],
                            'x-apifox-orders'            => [],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * 检查 多个 app 的 x 远程平台是否为同一个 project
     */
    private function checkXConfigMultiple(): bool
    {
        $count = collect($this->utility->getConfig('api_fox'))->pluck('project_id')->unique()->count();

        return $count > 1;
    }

    /**
     * 获取默认的 action 用于排序输出
     */
    private function getDefaultActions(): array
    {
        return ['create', 'edit', 'index', 'trashed', 'store', 'update', 'show', 'destroy', 'destroyBatch', 'restore'];
    }
}
