<?php

declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2026-04-11 10:00
 * @LastEditors: Charsen
 * @LastEditTime: 2026-05-06 09:56
 * @Description: Api Documentation & Debugging Controller
 */

namespace Mooeen\Scaffold\Http\Controllers;

use Faker\Factory as Faker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Mooeen\Scaffold\Http\Requests\Api\CacheRequest;
use Mooeen\Scaffold\Http\Requests\Api\EndpointRequest;
use Mooeen\Scaffold\Http\Requests\Api\IndexRequest;
use Mooeen\Scaffold\Support\AclActionResolver;
use Mooeen\Scaffold\Support\ActionDoc;
use Mooeen\Scaffold\Support\ActionMeta;
use Mooeen\Scaffold\Support\ApiParameterFormatter;
use Mooeen\Scaffold\Support\ApiSchemaService;
use Mooeen\Scaffold\Support\Paths;
use Mooeen\Scaffold\Support\StorageRegistry;
use Mooeen\Scaffold\Utility;

class ApiController extends Controller
{
    private array $latestModelIds = [];

    private ?array $parameterMetadata = null;

    private AclActionResolver $aclActionResolver;

    public function __construct(
        Utility $utility,
        Filesystem $filesystem,
        private readonly ApiSchemaService $apiSchemaService,
    ) {
        parent::__construct($utility, $filesystem);
        $this->aclActionResolver = new AclActionResolver;
    }

    /**
     * 接口文档列表
     */
    public function index(IndexRequest $req)
    {
        $validated = $req->validated();
        $app       = $validated['app'] ?? null;
        $apps      = $this->utility->getApps();

        // 2026-06-19:取消"选应用"落地页 —— 无 app(或非法 app)直接进默认应用:
        //   优先 cookie 上次选(30 天),否则第一个 app。应用切换走 subnav 的 app-tabs。
        if ($app === null || $app === '' || ! isset($apps[$app])) {
            $lastApp = (string) ($validated['_doc_app'] ?? '');
            $target  = (isset($apps[$lastApp]) && $lastApp !== '') ? $lastApp : array_key_first($apps);
            if ($target !== null) {
                return redirect()->route('api.list', ['app' => $target]);
            }

            // 极端:一个 app 都没配 → 空态(无可跳转目标)
            return $this->view('api.index', [
                'uri'                => $req->getPathInfo(),
                'apps'               => $apps,
                'app_stats'          => [],
                'current_app'        => null,
                'current_folder'     => null,
                'current_controller' => null,
                'current_action'     => null,
                'first_menu_active'  => false,
                'first_table_active' => false,
                'menus'              => [],
                'apis'               => [],
                'menus_transform'    => [],
            ]);
        }

        $data = $this->getApiList($app);

        $data['uri']                = $req->getPathInfo();
        $data['apps']               = $apps;
        $data['current_app']        = $app;
        $data['current_folder']     = $validated['f'] ?? null;
        $data['current_controller'] = $validated['c'] ?? null;
        $data['current_action']     = $validated['a'] ?? null;
        $data['first_menu_active']  = false;
        $data['first_table_active'] = $data['current_controller'] !== null;

        // plan-22 P1-U3: 选定 app 后写 cookie 30 天 — scaffold routes 默认不进 EncryptCookies,raw 对称读写
        return response()->view('scaffold::api.index', $data)
            ->cookie('scaffold_api_doc_app', $app, 60 * 24 * 30, '/', null, null, true, false);
    }

    /**
     * 接口详情 (AJAX)
     */
    public function show(EndpointRequest $req)
    {
        $data = $this->getOneApi($req->validated());

        return $this->view('api.show', $data);
    }

    /**
     * 接口调试页面
     */
    public function request(IndexRequest $req)
    {
        $validated = $req->validated();
        $app       = $validated['app'] ?? null;
        $apps      = $this->utility->getApps();

        // 2026-06-20:取消"选应用"落地页 —— 无 app(或非法)直接进默认应用(cookie 上次/首个),切换走 subnav app-tabs。
        if ($app === null || $app === '' || ! isset($apps[$app])) {
            $lastApp = (string) ($validated['_debug_app'] ?? '');
            $target  = (isset($apps[$lastApp]) && $lastApp !== '') ? $lastApp : array_key_first($apps);
            if ($target !== null) {
                return redirect()->route('api.request', ['app' => $target]);
            }

            // 极端:一个 app 都没配 → 空态(无可跳转目标)
            return $this->view('api.request', [
                'uri'                => $req->getPathInfo(),
                'apps'               => $apps,
                'app_stats'          => [],
                'current_app'        => null,
                'api_index'          => 1,
                'current_folder'     => null,
                'current_controller' => null,
                'current_action'     => null,
                'first_menu_active'  => false,
                'current_method'     => false,
                'hosts'              => $this->config('hosts') ?: [],
                'request_url'        => trim(str_replace($req->path(), '', $req->url()), '/'),
                'menus'              => [],
                'apis'               => [],
                'menus_transform'    => [],
            ]);
        }

        $data = $this->getApiList($app);

        $data['uri']                = $req->getPathInfo();
        $data['apps']               = $apps;
        $data['current_app']        = $app;
        $data['api_index']          = 1;
        $data['current_folder']     = $validated['f'] ?? null;
        $data['current_controller'] = $validated['c'] ?? null;
        $data['current_action']     = $validated['a'] ?? null;
        $data['first_menu_active']  = false;
        $data['current_method']     = false;
        $data['hosts']              = $this->config('hosts') ?: [];
        $data['request_url']        = trim(str_replace($req->path(), '', $req->url()), '/');

        if (
            $data['current_controller'] !== null
            && $data['current_action']  !== null
            && isset($data['apis'][$data['current_folder']][$data['current_controller']][$data['current_action']])
        ) {
            $data['current_method'] = $data['apis'][$data['current_folder']][$data['current_controller']][$data['current_action']]['method'];
        }

        // plan-22 P1-U3: 选定 app 后写 cookie 30 天 — scaffold routes 默认不进 EncryptCookies,raw 对称读写
        return response()->view('scaffold::api.request', $data)
            ->cookie('scaffold_api_debug_app', $app, 60 * 24 * 30, '/', null, null, true, false);
    }

    /**
     * 接口调试参数表单 (AJAX)
     */
    public function param(EndpointRequest $req)
    {
        $validated = $req->validated();
        $data      = $this->getOneApi($validated);

        $params = ($data['request'][0] === 'GET') ? $data['url_params'] : $data['body_params'];

        // 从 cache 恢复参数
        $data['cache_key_base'] = md5(implode('|', [
            $data['current_app'],
            $data['current_folder'],
            $data['current_controller'],
            $data['current_action'],
            $data['request'][0],
            $data['request'][1],
        ]));
        $data['cache_key'] = $this->buildScopedDebugCacheKey(
            $data['cache_key_base'],
            (string) ($validated['host_scope'] ?? ''),
            (string) ($validated['client_id'] ?? '')
        );
        $cache_params = Cache::store('file')->get($data['cache_key'] . '_params');

        if ($cache_params !== null) {
            foreach ($cache_params as $key => $val) {
                if (! isset($params[$key]) || ! is_array($val) || ! isset($val['value'])) {
                    continue;
                }
                $params[$key]['value']   = $val['value'];
                $params[$key]['require'] = ($val['checked'] ?? false) === true || ($val['checked'] ?? false) === 'true';
            }
        }

        if ($data['request'][0] === 'GET') {
            $data['url_params'] = $params;
        } else {
            $data['body_params'] = $params;
        }

        return $this->view('api.param', $data);
    }

    /**
     * 缓存"上次填了啥"的请求参数(用户切换接口后恢复表单状态)。
     * 不缓存响应——调试器永远走真实请求,不让用户看到旧数据。
     *
     * 回执走统一成功信封(旧形态是无 ok 布尔的裸 `{status:'ok'}`)。**调用方不读这个 body**:
     * 唯一的消费者 `public/javascript/pages/api-request.js` 里那句 `$.ajax` 是 fire-and-forget,
     * 既没有 success 也没有 error 回调 ⇒ 形状变化对它零影响。这里**不编造载荷**:
     * 端点没有可返回的数据,`status:'ok'` 与信封的 `ok:true` 语义重复,故给空 data。
     */
    public function cache(CacheRequest $req)
    {
        // plan-40 §五 F1 同精神:key/params 此前零校验 — key 任意串直拼 cache key,
        // params 不限类型不限体积(file cache 30 天过期,反复塞大 payload 可膨胀磁盘)。
        // 正常 key 是 md5|host_scope|client_id(< 150 字符),200 cap 足够。
        $validated = $req->validated();
        $cache_key = $validated['key']    ?? null;
        $params    = $validated['params'] ?? null;

        if ($cache_key !== null && $params !== null) {
            // 单条参数缓存全是表单 kv,64KB 远超正常上限;超限多半是误贴超长值,跳过不落盘
            if (strlen((string) json_encode($params)) <= 65536) {
                Cache::store('file')->put($cache_key . '_params', $params, now()->addDays(30));
            }
        }

        return $this->ok();
    }

    // ---- Private Methods ----

    private function buildScopedDebugCacheKey(string $baseKey, string $hostScope = '', string $clientId = ''): string
    {
        return implode('|', [
            $baseKey,
            trim($hostScope),
            trim($clientId),
        ]);
    }

    /**
     * 获取 API 列表（从 YAML 文件读取）
     */
    private function getApiList(string $app = 'admin'): array
    {
        $apiPath = Paths::api('schema') . $app . '/';

        if (! $this->filesystem->isDirectory($apiPath)) {
            return ['menus' => [], 'apis' => [], 'menus_transform' => []];
        }

        // 读取菜单转换配置（中文名 + 排序）
        $menusTransform = $this->getMenusTransform($apiPath);

        $yamlFiles = $this->filesystem->allFiles($apiPath);
        $menus     = [];
        $apis      = [];
        $taxis     = [];

        foreach ($yamlFiles as $file) {
            $baseName = $file->getBasename();
            // 跳过非 YAML 和特殊文件
            if (! str_ends_with($baseName, '.yaml') || str_starts_with($baseName, '_')) {
                continue;
            }

            $path = empty($file->getRelativePath()) ? 'Index' : $file->getRelativePath();
            $data = $this->utility->parseYamlFile($file->getPathname());

            if (! is_array($data['controller'] ?? null) || ! is_array($data['actions'] ?? null)) {
                continue;
            }

            $data['controller']['api_count']            = 0;
            $menus[$path][$data['controller']['class']] = $data['controller'];

            $temp = [];
            foreach ($data['actions'] as $actionName => $attr) {
                if (! is_array($attr) || ! isset($attr['request'][0], $attr['request'][1])) {
                    continue;
                }

                $actionMeta = ActionMeta::normalize($attr, true);
                $deprecated = ActionMeta::isDeprecated($attr);

                $temp[$actionName] = [
                    // 手写 yaml 可缺 name 字段,裸取 → ErrorException 炸文档/调试页;
                    // 兜底 action 名,与 getOneApi 的 `?? $realActionName` 同口径(2026-06-10 修)
                    'name'       => $attr['name'] ?? (string) $actionName,
                    'desc'       => $attr['desc'] ?? [],
                    'method'     => $attr['request'][0],
                    'url'        => $attr['request'][1],
                    'api_meta'   => $actionMeta,
                    'deprecated' => $deprecated,
                ];
                // deprecated 仍进 $temp(侧栏带「弃用」标签展示),但不进 api_count 计数 ——
                // 与 ApiSchemaService::getAppStats 口径对齐(picker 卡片 vs 侧栏徽章分母一致)
                if (! $deprecated) {
                    $menus[$path][$data['controller']['class']]['api_count']++;
                }
            }

            $apis[$path][$data['controller']['class']]  = $temp;
            $taxis[$path][$data['controller']['class']] = $data['controller']['code'] ?? 1;
        }

        return [
            'menus'           => $this->sortApiMenus($menus, $taxis, $menusTransform),
            'apis'            => $apis,
            'menus_transform' => $menusTransform,
        ];
    }

    private function sortApiMenus(array $menus, array $taxis, array $menusTransform): array
    {
        $sortedMenus = [];
        foreach ($menus as $path => $controllers) {
            $sortedMenus[$path] = $this->sortApiControllers(
                $controllers,
                $taxis[$path]                         ?? [],
                $menusTransform[$path]['controllers'] ?? []
            );
        }

        if ($menusTransform === []) {
            return $sortedMenus;
        }

        $orderedMenus = [];
        foreach (array_keys($menusTransform) as $key) {
            if (isset($sortedMenus[$key])) {
                $orderedMenus[$key] = $sortedMenus[$key];
            }
        }

        foreach ($sortedMenus as $key => $val) {
            if (! isset($orderedMenus[$key])) {
                $orderedMenus[$key] = $val;
            }
        }

        return $orderedMenus;
    }

    /**
     * 优先尊重 _menus_transform.yaml 的人工排序；未配置时回落到 controller.code。
     */
    private function sortApiControllers(array $controllers, array $codes, array $transformControllers): array
    {
        if ($transformControllers !== []) {
            $sorted = [];
            foreach ($transformControllers as $controllerClass) {
                if (isset($controllers[$controllerClass])) {
                    $sorted[$controllerClass] = $controllers[$controllerClass];
                }
            }

            foreach ($controllers as $controllerClass => $controller) {
                if (! isset($sorted[$controllerClass])) {
                    $sorted[$controllerClass] = $controller;
                }
            }

            return $sorted;
        }

        asort($codes);
        $sorted = [];
        foreach ($codes as $controllerClass => $code) {
            $sorted[$controllerClass] = $controllers[$controllerClass];
        }

        return $sorted;
    }

    /**
     * 获取菜单转换名称数据
     *
     * 支持嵌套格式：
     *   'Index': { name: '根目录', controllers: [Auth, Editor] }
     * 兼容旧扁平格式：
     *   'Index': '根目录'
     */
    private function getMenusTransform(string $apiPath): array
    {
        $yamlFile = $apiPath . '_menus_transform.yaml';

        if (! $this->filesystem->isFile($yamlFile)) {
            return [];
        }

        return ActionMeta::normalizeMenus($this->utility->parseYamlFile($yamlFile));
    }

    /**
     * 获取单个 API 的详细数据（从 YAML 读取 + 合并 FormRequest 规则）
     */
    private function getOneApi(array $validated): array
    {
        $app             = $validated['app'] ?? 'admin';
        $folderName      = $validated['f']   ?? 'Index';
        $folderPath      = $folderName === 'Index' ? '' : $folderName;
        $controllerClass = $validated['c'] ?? null;
        $actionName      = $validated['a'] ?? null;

        // 形状守护:缺 c(控制器)时 null 会传进 isApiFileExist(string $fileName) → 500 TypeError;
        // 畸形 / 缺参请求(如误用 controller= 长参名)应干净 404 而非 500(与本方法其它 abort(404) 同口径)
        if (! is_string($controllerClass) || $controllerClass === '') {
            abort(404, 'API Controller Not Specified');
        }

        // 1. 加载 YAML 文件
        $yamlFolder = $app . (empty($folderPath) ? '' : '/' . $folderPath);
        $file       = $this->utility->isApiFileExist($yamlFolder, $controllerClass, 'schema');
        $yamlData   = $this->utility->parseYamlFile($file);

        if ($yamlData === []) {
            abort(404, 'API Schema Not Found');
        }

        if (! isset($yamlData['actions'][$actionName])) {
            abort(404, 'API Action Not Found');
        }

        $actionData = $yamlData['actions'][$actionName];
        // 与 getApiList 同口径的形状守护:手写 yaml 的 action 值可为 null/字符串(`~`)或缺
        // request —— 裸取 request[0] 会 ErrorException 500(下两行还专门 is_array 防了,
        // 这行在它们前面先炸,2026-06-10 修)
        if (! is_array($actionData) || ! isset($actionData['request'][0], $actionData['request'][1])) {
            abort(404, 'API Action Invalid');
        }
        $method     = strtoupper((string) $actionData['request'][0]);
        $actionMeta = ActionMeta::normalize($actionData, true);
        $deprecated = ActionMeta::isDeprecated($actionData);
        $uri        = $actionData['request'][1];

        // 2. 去掉方法后缀，获取真实 action 名（用于 Reflection）
        $realActionName = ActionMeta::removeMethodSuffix($actionName);

        // 3. 构建完整控制器类名
        $controllerFullClass = $this->resolveControllerClass($app, $folderPath, $controllerClass);

        // 4. 解析 action 信息（ACL）
        $checkAction = $this->resolveCheckAction($controllerFullClass, $realActionName);

        $data = [
            'name'               => $actionData['name'] ?? $realActionName,
            'desc'               => ! empty($actionData['desc']) ? (is_array($actionData['desc']) ? $actionData['desc'] : [$actionData['desc']]) : [],
            'prototype'          => $actionData['prototype'] ?? '',
            'request'            => [$method, $uri],
            'current_app'        => $app,
            'current_action'     => $actionName,
            'current_folder'     => $folderName,
            'current_controller' => $controllerClass,
            'check_action'       => $checkAction,
            'header_params'      => [],
            'api_meta'           => $actionMeta,
            'deprecated'         => $deprecated,
        ];

        // 5. prototype 兼容处理（store/update 使用 create/edit 的）
        if ($realActionName === 'store' || $realActionName === 'update') {
            $tempName = ($realActionName === 'store') ? 'create' : 'edit';
            if (empty($data['prototype'])) {
                foreach ($yamlData['actions'] as $key => $val) {
                    if (ActionMeta::removeMethodSuffix($key) === $tempName && ! empty($val['prototype'] ?? '')) {
                        $data['prototype'] = $val['prototype'];
                        break;
                    }
                }
            }
        }

        // 6. Header params (Token)
        $excludeActions = $this->config('authorization.exclude_actions') ?? [];
        $fullAction     = $controllerFullClass . '@' . $realActionName;

        if (! in_array($fullAction, $excludeActions, true) && $realActionName !== 'authenticate') {
            $data['header_params']['token'] = '';
        }

        // 7. 从 FormRequest 获取验证规则作为参数
        //    （参数形状归一已外迁 `Support\ApiParameterFormatter`；这里只交出**归一好的元数据**与一个
        //      「最新 ID」解算器 —— metadata memo 与 `$latestModelIds` memo 都留在本类，避免跨请求残留）
        $ruleAction = $actionData['rule_action'] ?? $realActionName;
        $rules      = $this->getRequestRulesForAction($controllerFullClass, (string) $ruleAction);
        $metadata   = $this->getParameterMetadata();
        $ruleParams = ApiParameterFormatter::formatRules(
            $realActionName,
            $rules,
            $metadata,
            fn (array $existsRules): int|string|null => $this->resolveLatestModelIdFromRules($existsRules),
        );

        $data['request'][1] = $this->resolveRequestUri($uri, $controllerFullClass, $rules);

        // 8. 解析 YAML 中用户手动定义的参数
        $yamlUrlParams  = ApiParameterFormatter::formatYamlParams($actionData['url_params'] ?? [], $metadata);
        $yamlBodyParams = ApiParameterFormatter::formatYamlParams($actionData['body_params'] ?? [], $metadata);

        // 9. 合并参数
        if ($method === 'GET') {
            $data['url_params']  = ApiParameterFormatter::mergeDebugParams($ruleParams, $yamlUrlParams);
            $data['body_params'] = [];
        } else {
            $methodRest = [
                'update'       => 'PUT',
                'destroy'      => 'DELETE',
                'forceDestroy' => 'DELETE',
                'destroyBatch' => 'DELETE',
                'restore'      => 'PATCH',
            ];

            if ($method !== 'POST') {
                $methodRest[$realActionName] = $method;
            }

            $methodParam = isset($methodRest[$realActionName])
                ? ['_method' => ['require' => true, 'name' => '', 'value' => $methodRest[$realActionName], 'desc' => '']]
                : [];

            $data['url_params']  = [];
            $data['body_params'] = ApiParameterFormatter::mergeDebugParams(array_merge($methodParam, $ruleParams), $yamlBodyParams);
        }

        // 10. Faker 伪造数据
        $faker               = Faker::create('zh_CN');
        $data['url_params']  = ApiParameterFormatter::formatToFaker($faker, $data['url_params']);
        $data['body_params'] = ApiParameterFormatter::formatToFaker($faker, $data['body_params']);

        return $data;
    }

    private function getRequestRulesForAction(string $controllerFullClass, string $ruleAction): array
    {
        if (! class_exists($controllerFullClass)) {
            return [];
        }

        $reflectionClass = new \ReflectionClass($controllerFullClass);
        if (! $reflectionClass->hasMethod($ruleAction)) {
            return [];
        }

        $request = ActionDoc::getActionRequestClass($reflectionClass->getMethod($ruleAction));
        if ($request === null || ! method_exists($request, 'rules')) {
            return [];
        }

        $rules = $request->rules();
        if (! is_array($rules)) {
            return [];
        }

        $normalizedRules = [];
        foreach ($rules as $field => $fieldRules) {
            if (is_string($fieldRules)) {
                $fieldRules = explode('|', $fieldRules);
            }

            if (! is_array($fieldRules)) {
                continue;
            }

            // 调试表单只展示字符串规则，Rule 对象仍由业务请求类自己处理。
            $normalizedRules[$field] = array_values(array_filter($fieldRules, 'is_string'));
        }

        return $normalizedRules;
    }

    /**
     * 构建完整的控制器类名
     */
    private function resolveControllerClass(string $app, string $folderPath, string $controllerClass): string
    {
        $config = $this->config('controller.' . $app);
        if ($config === null) {
            return '';
        }

        // 包提供的额外模块（folderPath 命中 extra_modules key）：FQCN 走包命名空间，
        // 否则下面按 host 约定 basePath\{folder}\{X}Controller 拼装会得到不存在的类，导致
        // getRequestRulesForAction 反射不到 Request → url/body params 全空。
        $folderKey = ucfirst(trim(str_replace('/', '\\', $folderPath), '\\'));
        $extra     = $this->utility->getExtraModules($app);
        if ($folderKey !== '' && isset($extra[$folderKey])) {
            return $extra[$folderKey] . '\\' . $controllerClass . 'Controller';
        }

        $basePath = str_replace('/', '\\', ucfirst(rtrim($config['path'], '/')));
        $folder   = empty($folderPath) ? '' : '\\' . str_replace('/', '\\', $folderPath);

        return $basePath . $folder . '\\' . $controllerClass . 'Controller';
    }

    /**
     * 根据 controller 对应的 model 自动补全 URL 中的主键参数
     */
    private function resolveRequestUri(string $uri, string $controllerFullClass, array $rules = []): string
    {
        return (string) preg_replace_callback(
            '/\{([a-z_]+)\}/i',
            fn (array $matches): string => $this->resolveRouteParamValue($matches[1], $controllerFullClass, $rules),
            $uri
        );
    }

    /**
     * 解析路由参数默认值
     */
    private function resolveRouteParamValue(string $paramName, string $controllerFullClass, array $rules = []): string
    {
        if ($paramName === 'id') {
            $latestId = $this->getLatestModelId($controllerFullClass);
            if ($latestId !== null && $latestId !== '') {
                return (string) $latestId;
            }
        }

        if (isset($rules[$paramName])) {
            $latestId = $this->resolveLatestModelIdFromRules($rules[$paramName]);
            if ($latestId !== null && $latestId !== '') {
                return (string) $latestId;
            }
        }

        return '2';
    }

    /**
     * 获取 controller 对应 model 的最后一条记录主键
     */
    private function getLatestModelId(string $controllerFullClass): int|string|null
    {
        if (array_key_exists($controllerFullClass, $this->latestModelIds)) {
            return $this->latestModelIds[$controllerFullClass];
        }

        try {
            if (! class_exists($controllerFullClass)) {
                return $this->latestModelIds[$controllerFullClass] = null;
            }

            $reflectionClass = new \ReflectionClass($controllerFullClass);
            $modelClass      = $this->resolveControllerModelClass($reflectionClass);
            if ($modelClass === null || ! is_subclass_of($modelClass, Model::class)) {
                return $this->latestModelIds[$controllerFullClass] = null;
            }

            return $this->latestModelIds[$controllerFullClass] = $this->getLatestModelIdByClass($modelClass);
        } catch (\Throwable) {
            return $this->latestModelIds[$controllerFullClass] = null;
        }
    }

    /**
     * 获取指定 model class 的最后一条记录主键
     */
    private function getLatestModelIdByClass(string $modelClass): int|string|null
    {
        if (array_key_exists($modelClass, $this->latestModelIds)) {
            return $this->latestModelIds[$modelClass];
        }

        try {
            if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
                return $this->latestModelIds[$modelClass] = null;
            }

            /** @var Model $model */
            $model    = new $modelClass;
            $keyName  = $model->getKeyName();
            $latestId = $modelClass::query()->latest($keyName)->value($keyName);

            return $this->latestModelIds[$modelClass] = $latestId;
        } catch (\Throwable) {
            return $this->latestModelIds[$modelClass] = null;
        }
    }

    /**
     * 解析 controller 绑定的 model class
     */
    private function resolveControllerModelClass(\ReflectionClass $reflectionClass): ?string
    {
        if ($reflectionClass->hasProperty('model')) {
            $property = $reflectionClass->getProperty('model');
            $type     = $property->getType();
            if ($type instanceof \ReflectionNamedType && ! $type->isBuiltin()) {
                return $type->getName();
            }
        }

        $constructor = $reflectionClass->getConstructor();
        if ($constructor === null) {
            return null;
        }

        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && ! $type->isBuiltin()) {
                return $type->getName();
            }
        }

        return null;
    }

    /**
     * 使用运行时一致的逻辑解析 ACL 名称，避免调试页展示出错
     */
    private function resolveCheckAction(string $controllerFullClass, string $realActionName): string
    {
        $acl = $this->aclActionResolver->resolve($controllerFullClass, $realActionName);

        return $acl['plain_key'] ?? '';
    }

    /**
     * 参数归一用的元数据（枚举 / 字段 / 多语言字段）。
     *
     * **刻意留在本类、没跟 `Support\ApiParameterFormatter` 一起走** —— 它持有两样「跨请求敏感」的东西：
     * ① memo（`$this->parameterMetadata`）；② `Utility` 依赖（`getLangFields()`）。
     * 迁到那个全静态类里，memo 只能变 `static` ⇒ **跨请求残留**（`StorageRegistryTest` 正有一条守卫挡这个：
     * 重写缓存文件后下一次必须读到新值）；把 `Utility` 注进去又会让那个类从「纯计算」变成「有依赖」。
     * 故本方法留在原地，**只把归一好的数组**交给外迁方。
     */
    private function getParameterMetadata(): array
    {
        if ($this->parameterMetadata !== null) {
            return $this->parameterMetadata;
        }

        try {
            $enums = StorageRegistry::enums();
        } catch (\Throwable) {
            $enums = [];
        }

        try {
            $fields = StorageRegistry::fields();
        } catch (\Throwable) {
            $fields = [];
        }

        return $this->parameterMetadata = [
            'enums'       => $enums,
            'fields'      => $fields,
            'lang_fields' => $this->utility->getLangFields(),
        ];
    }

    /**
     * 同上，也留在本类：`formatRules` 里「`exists:Model,id` ⇒ 默认填最新 ID」这一步要 Eloquent 查询 +
     * `$latestModelIds` memo（同样跨请求敏感），且**另有调用方** `resolveRouteParamValue()` 在用 ——
     * 外迁方只收一个解算器回调。
     */
    private function resolveLatestModelIdFromRules(array $rules): int|string|null
    {
        foreach ($rules as $rule) {
            if (! is_string($rule)) {
                continue;
            }

            $modelClass = $this->resolveExistsModelClass($rule);
            if ($modelClass === null) {
                continue;
            }

            return $this->getLatestModelIdByClass($modelClass);
        }

        return null;
    }

    private function resolveExistsModelClass(string $rule): ?string
    {
        if (! preg_match('/^exists:([^,]+),id(?:,|$)/', trim($rule), $matches)) {
            return null;
        }

        $modelClass = trim($matches[1]);
        if ($modelClass === '' || ! class_exists($modelClass)) {
            return null;
        }

        return is_subclass_of($modelClass, Model::class)
            ? $modelClass
            : null;
    }
}
