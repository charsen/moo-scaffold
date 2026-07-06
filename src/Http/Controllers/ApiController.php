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
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mooeen\Scaffold\Support\AclActionResolver;
use Mooeen\Scaffold\Support\ApiSchemaService;
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
    public function index(Request $req)
    {
        $app  = $req->input('app');
        $apps = $this->utility->getApps();

        // 2026-06-19:取消"选应用"落地页 —— 无 app(或非法 app)直接进默认应用:
        //   优先 cookie 上次选(30 天),否则第一个 app。应用切换走 subnav 的 app-tabs。
        if ($app === null || $app === '' || ! isset($apps[$app])) {
            $lastApp = (string) $req->cookie('scaffold_api_doc_app', '');
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
        $data['current_folder']     = $req->input('f', null);
        $data['current_controller'] = $req->input('c', null);
        $data['current_action']     = $req->input('a', null);
        $data['first_menu_active']  = false;
        $data['first_table_active'] = $data['current_controller'] !== null;

        // plan-22 P1-U3: 选定 app 后写 cookie 30 天 — scaffold routes 默认不进 EncryptCookies,raw 对称读写
        return response()->view('scaffold::api.index', $data)
            ->cookie('scaffold_api_doc_app', $app, 60 * 24 * 30, '/', null, null, true, false);
    }

    /**
     * 接口详情 (AJAX)
     */
    public function show(Request $req)
    {
        $data = $this->getOneApi($req);

        return $this->view('api.show', $data);
    }

    /**
     * 接口调试页面
     */
    public function request(Request $req)
    {
        $app  = $req->input('app');
        $apps = $this->utility->getApps();

        // 2026-06-20:取消"选应用"落地页 —— 无 app(或非法)直接进默认应用(cookie 上次/首个),切换走 subnav app-tabs。
        if ($app === null || $app === '' || ! isset($apps[$app])) {
            $lastApp = (string) $req->cookie('scaffold_api_debug_app', '');
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
        $data['current_folder']     = $req->input('f', null);
        $data['current_controller'] = $req->input('c', null);
        $data['current_action']     = $req->input('a', null);
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
    public function param(Request $req)
    {
        $data = $this->getOneApi($req);

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
            (string) $req->input('host_scope', ''),
            (string) $req->input('client_id', '')
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
     */
    public function cache(Request $req)
    {
        // plan-40 §五 F1 同精神:key/params 此前零校验 — key 任意串直拼 cache key,
        // params 不限类型不限体积(file cache 30 天过期,反复塞大 payload 可膨胀磁盘)。
        // 正常 key 是 md5|host_scope|client_id(< 150 字符),200 cap 足够。
        $validated = $req->validate([
            'key'    => 'nullable|string|max:200',
            'params' => 'nullable|array',
        ]);
        $cache_key = $validated['key']    ?? null;
        $params    = $validated['params'] ?? null;

        if ($cache_key !== null && $params !== null) {
            // 单条参数缓存全是表单 kv,64KB 远超正常上限;超限多半是误贴超长值,跳过不落盘
            if (strlen((string) json_encode($params)) <= 65536) {
                Cache::store('file')->put($cache_key . '_params', $params, now()->addDays(30));
            }
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * 代理转发接口请求（解决跨域）
     */
    public function proxy(Request $req)
    {
        // plan-40 §五 F3:url + method 上 validate 作为白名单的第二防线
        // (isAllowedProxyUrl 是主防线,但代码迁移 / 异步队列重组时容易绕开,validate 永远先跑)
        $req->validate([
            '_proxy_url'    => 'required|string|url|max:2000',
            '_proxy_method' => 'nullable|string|in:GET,POST,PUT,PATCH,DELETE,get,post,put,patch,delete',
        ]);
        $url            = $req->input('_proxy_url');
        $method         = strtoupper($req->input('_proxy_method', 'GET'));
        $headers        = $req->input('_proxy_headers', []);
        $params         = $req->input('_proxy_params', []);
        $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

        if (empty($url)) {
            return response()->json(['_proxy_status' => 400, 'message' => 'Missing _proxy_url']);
        }

        if (! in_array($method, $allowedMethods, true)) {
            return response()->json(['_proxy_status' => 400, 'message' => 'Unsupported _proxy_method']);
        }

        if (! $this->isAllowedProxyUrl($req, $url)) {
            // plan-22 安全审计 Q3:拒绝时 audit log,便于排查异常流量
            Log::warning('scaffold.api.proxy.denied', [
                'url'    => $url,
                'method' => $method,
                'ip'     => $req->ip(),
                'user'   => $req->attributes->get('scaffold_auth_user', '?'),
            ]);

            return response()->json(['_proxy_status' => 403, 'message' => 'Proxy target is not allowed']);
        }

        try {
            $http = $this->buildProxyClient(is_array($headers) ? $headers : []);

            if ($method === 'GET') {
                $response = $http->get($url, $params);
            } else {
                $response = $http->asForm()->{strtolower($method)}($url, $params);
            }

            // 故意不 follow redirect:server 把 http 跳到 https 这种通常说明 host config 写错了,
            // 直接把 301/302 返回给前端,UI 能立刻看到 Location 头,自己改正确的 hosts 配置。
            //
            // _proxy_status / _proxy_headers 只能挂在「关联数组」上:
            //   - 上游返回标量 JSON("pong" / 123 / true)→ 往标量挂键直接 throw
            //     "Cannot use a scalar value as an array" → 被 catch 误报成 502 Proxy Error;
            //   - 上游返回 JSON 列表([{...},{...}])→ 挂 string key 会把 array 改形成 object
            //     ({"0":...,"1":...}),前端展示 / form-preview 的 Array shape 检测全被破坏。
            // 两类都包进 data 下原样透传(2026-06-10 修);非 JSON body 维持 _raw 约定。
            $json = $response->json();
            if (is_array($json) && ! array_is_list($json)) {
                $body = $json;
            } elseif ($json !== null) {
                $body = ['data' => $json];
            } else {
                $body = ['_raw' => $response->body()];
            }
            $body['_proxy_status'] = $response->status();
            // 多值响应头(典型:登录响应一次性 Set-Cookie 多条)原先只取 [0] → 调试时只能看到第一
            // 个 cookie,其余静默丢失。改为全部值逗号拼接展示(2026-06-10 修)。
            $body['_proxy_headers'] = array_map(
                static fn ($v) => is_array($v) ? implode(', ', $v) : (string) $v,
                array_change_key_case($response->headers(), CASE_LOWER)
            );

            return response()->json($body);
        } catch (\Throwable $e) {
            return response()->json(['_proxy_status' => 502, 'message' => 'Proxy Error: ' . $e->getMessage()]);
        }
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

    private function buildProxyClient(array $headers)
    {
        // 强制校验 TLS + 不 follow redirect:HTTPS 证书 / 协议配错就报真错,不留绕过开关。
        $timeout = (int) ($this->config('proxy.timeout') ?? 30);

        // connectTimeout:不设的话 Guzzle 连接阶段无独立上限,只受总 timeout 约束 → 调试一个
        // 白名单里但已宕机/防火墙黑洞的 host,要干等满 30s 才报错、整个调试器卡死。给连接阶段一个
        // 较短上限(≤10s 且不超过总超时),连不上快速失败(2026-06-10 修)。
        return Http::withHeaders($headers)
            ->timeout($timeout)
            ->connectTimeout(min(10, max(1, $timeout)))
            ->withOptions(['allow_redirects' => false]);
    }

    private function isAllowedProxyUrl(Request $req, string $url): bool
    {
        // plan-22 安全审计 Q3:显式协议白名单(原靠 origin match 隐含挡 file/gopher,显式写出来更稳)
        $scheme = strtolower((string) parse_url(trim($url), PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $targetOrigin = $this->normalizeOrigin($url);
        if ($targetOrigin === null) {
            return false;
        }

        return in_array($targetOrigin, $this->getAllowedProxyOrigins($req), true);
    }

    private function getAllowedProxyOrigins(Request $req): array
    {
        $origins = [];
        foreach (array_values($this->config('hosts') ?: []) as $hostUrl) {
            $origin = $this->normalizeOrigin((string) $hostUrl);
            if ($origin !== null) {
                $origins[] = $origin;
            }
        }

        if (! empty($origins)) {
            return array_values(array_unique($origins));
        }

        return [$this->buildOrigin([
            'scheme' => $req->getScheme(),
            'host'   => $req->getHost(),
            'port'   => $req->getPort(),
        ])];
    }

    private function normalizeOrigin(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        return $this->buildOrigin($parts);
    }

    private function buildOrigin(array $parts): string
    {
        $scheme      = strtolower((string) ($parts['scheme'] ?? 'http'));
        $host        = strtolower((string) ($parts['host'] ?? ''));
        $port        = isset($parts['port']) ? (int) $parts['port'] : null;
        $defaultPort = $scheme === 'https' ? 443 : 80;

        return $scheme . '://' . $host . (($port !== null && $port !== $defaultPort) ? ':' . $port : '');
    }

    /**
     * 获取 API 列表（从 YAML 文件读取）
     */
    private function getApiList(string $app = 'admin'): array
    {
        $apiPath = $this->utility->getApiPath('schema') . $app . '/';

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

                $actionMeta = $this->utility->normalizeApiActionMeta($attr, true);
                $deprecated = $this->utility->isApiActionDeprecated($attr);

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

        return $this->utility->normalizeMenusTransform($this->utility->parseYamlFile($yamlFile));
    }

    /**
     * 获取单个 API 的详细数据（从 YAML 读取 + 合并 FormRequest 规则）
     */
    private function getOneApi(Request $req): array
    {
        $app             = $req->input('app', 'admin');
        $folderName      = $req->input('f', 'Index');
        $folderPath      = $folderName === 'Index' ? '' : $folderName;
        $controllerClass = $req->input('c');
        $actionName      = $req->input('a');

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
        $actionMeta = $this->utility->normalizeApiActionMeta($actionData, true);
        $deprecated = $this->utility->isApiActionDeprecated($actionData);
        $uri        = $actionData['request'][1];

        // 2. 去掉方法后缀，获取真实 action 名（用于 Reflection）
        $realActionName = $this->utility->removeActionNameMethod($actionName);

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
                    if ($this->utility->removeActionNameMethod($key) === $tempName && ! empty($val['prototype'] ?? '')) {
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
        $ruleAction = $actionData['rule_action'] ?? $realActionName;
        $rules      = $this->getRequestRulesForAction($controllerFullClass, (string) $ruleAction);
        $ruleParams = $this->formatRules($realActionName, $rules);

        $data['request'][1] = $this->resolveRequestUri($uri, $controllerFullClass, $rules);

        // 8. 解析 YAML 中用户手动定义的参数
        $yamlUrlParams  = $this->formatYamlParams($actionData['url_params'] ?? []);
        $yamlBodyParams = $this->formatYamlParams($actionData['body_params'] ?? []);

        // 9. 合并参数
        if ($method === 'GET') {
            $data['url_params']  = $this->mergeDebugParams($ruleParams, $yamlUrlParams);
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
            $data['body_params'] = $this->mergeDebugParams(array_merge($methodParam, $ruleParams), $yamlBodyParams);
        }

        // 10. Faker 伪造数据
        $faker               = Faker::create('zh_CN');
        $data['url_params']  = $this->formatToFaker($faker, $data['url_params']);
        $data['body_params'] = $this->formatToFaker($faker, $data['body_params']);

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

        $request = $this->utility->getActionRequestClass($reflectionClass->getMethod($ruleAction));
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
     * 解析 YAML 中手动定义的参数格式
     *
     * 格式：
     * field: [false]                         - 非必填
     * field: []                              - 必填
     * field: [Name, value]                   - 必填，名称，默认值
     * field: [false, Name, value]            - 非必填，名称，默认值
     * field: [false, Name, value, desc]      - 非必填，名称，默认值，描述
     */
    private function formatYamlParams(array $params): array
    {
        if (empty($params)) {
            return [];
        }

        $metadata = $this->getParameterMetadata();
        $enums    = $metadata['enums'];
        $fields   = $metadata['fields'];
        $data     = [];

        foreach ($params as $key => $attr) {
            if ($key === '_method') {
                $data[$key] = [
                    'require' => true, 'name' => '',
                    'value'   => strtoupper($attr[0]), 'desc' => '兼容处理',
                ];

                continue;
            }

            if (! is_array($attr)) {
                continue;
            }

            $attr[0] = $attr[0] ?? true;

            if ($attr[0] === false) {
                $name       = $attr[1] ?? $this->resolveParameterLabel($key, $metadata);
                $data[$key] = [
                    'require'     => false, 'name' => $name,
                    'value'       => $attr[2] ?? '', 'desc' => $attr[3] ?? '',
                    'display_key' => $key, 'send_key' => $key, 'sendable' => true,
                ];
            } else {
                $name       = is_string($attr[0]) ? $attr[0] : $this->resolveParameterLabel($key, $metadata);
                $data[$key] = [
                    'require'     => true, 'name' => $name,
                    'value'       => $attr[1] ?? '', 'desc' => $attr[2] ?? '',
                    'display_key' => $key, 'send_key' => $key, 'sendable' => true,
                ];
            }

            $this->applyParameterFieldMeta($data[$key], $key, $metadata);
        }

        return $data;
    }

    /**
     * 格式化验证规则为 API 参数
     */
    private function formatRules(string $actionName, array $rules): array
    {
        $metadata = $this->getParameterMetadata();
        $ruleKeys = array_keys($rules);

        $data = [];
        foreach ($rules as $key => $attr) {
            // 数组-标量元素(field.*: numeric/string 等,无 field.*.xxx 深层)→ 被父数组吸收,不单列成参数。
            // (原先 field + field.* 各出一个参数 → 调试页一个数组被错解析成两行)
            if ($this->isScalarArrayElement($key, $ruleKeys) && in_array(substr($key, 0, -2), $ruleKeys, true)) {
                continue;
            }
            $sendable   = $this->isRuleParameterSendable($key, $attr, $ruleKeys);
            $displayKey = $this->formatParameterDisplayKey($key);
            $data[$key] = [
                'require' => $this->isRuleParameterRequired($attr),
                'name'    => $this->resolveParameterLabel($key, $metadata),
                'value'   => $sendable ? '' : (str_ends_with($key, '.*') ? '{}' : '[]'),
                // 2026-06-20:desc 与 rules 拆开 —— desc=填写/语义提示(下方 ids/force/最新ID 赋值);
                //   rules=验证约束(长度/必填条件等)。调试表说明列只显 desc,约束移到 VALUE hover;
                //   文档页仍合并 rules+desc 显示(外观不变)。
                'desc'        => '',
                'rules'       => $this->buildRuleParameterDescription($key, $attr, $ruleKeys, $sendable),
                'type'        => $this->resolveRuleParameterType($key, $attr, $metadata),
                'display_key' => $displayKey,
                'send_key'    => $sendable ? $displayKey : '',
                'sendable'    => $sendable,
            ];

            if ($key === 'page') {
                $data[$key]['value'] = 1;
            } elseif ($key === 'page_limit') {
                $data[$key]['value'] = 10;
            } elseif ($key === 'ids') {
                $data[$key]['value'] = '2,3';
                $data[$key]['desc']  = '用,分割为数组';
            } elseif ($key === 'force') {
                $data[$key]['require'] = false;
                $data[$key]['name']    = in_array($actionName, ['destroy', 'destroyBatch']) ? '强制删除' : '强制';
                $data[$key]['value']   = 1;
                $data[$key]['desc']    = '{0: false, 1: true}';
            }

            // 数组-标量(父,有 field.* 标量子键)→ 可单发,提示按数组填(逗号分隔或 [..] JSON)
            if ($sendable && in_array('array', $attr, true) && in_array($key . '.*', $ruleKeys, true)) {
                $data[$key]['desc'] = $this->appendParameterHint($data[$key]['desc'], '数组,逗号分隔或 JSON');
            }

            $latestModelId = $this->resolveLatestModelIdFromRules($attr);
            if ($latestModelId !== null && $latestModelId !== '') {
                $data[$key]['value'] = (string) $latestModelId;
                $data[$key]['desc']  = $this->appendParameterHint($data[$key]['desc'], '默认最新 ID');
            }

            $this->applyParameterFieldMeta($data[$key], $key, $metadata);
        }

        return $data;
    }

    private function mergeDebugParams(array $baseParams, array $overrideParams): array
    {
        if (empty($overrideParams)) {
            return $baseParams;
        }

        $merged = array_merge($baseParams, $overrideParams);

        foreach ($overrideParams as $key => $overrideParam) {
            if (
                ! isset($baseParams[$key], $merged[$key])
                || ! is_array($baseParams[$key])
                || ! is_array($merged[$key])
            ) {
                continue;
            }

            $this->inheritMissingDebugParamMeta($merged[$key], $baseParams[$key]);
        }

        return $merged;
    }

    private function inheritMissingDebugParamMeta(array &$target, array $source): void
    {
        foreach (['value', 'desc', 'name'] as $field) {
            if (($target[$field] ?? '') === '' && ($source[$field] ?? '') !== '') {
                $target[$field] = $source[$field];
            }
        }

        foreach (['type', 'options', 'require', 'display_key', 'send_key', 'sendable'] as $field) {
            if (! isset($target[$field]) && isset($source[$field])) {
                $target[$field] = $source[$field];
            }
        }
    }

    private function getParameterMetadata(): array
    {
        if ($this->parameterMetadata !== null) {
            return $this->parameterMetadata;
        }

        try {
            $enums = $this->utility->getEnums();
        } catch (\Throwable) {
            $enums = [];
        }

        try {
            $fields = $this->utility->getFields();
        } catch (\Throwable) {
            $fields = [];
        }

        return $this->parameterMetadata = [
            'enums'       => $enums,
            'fields'      => $fields,
            'lang_fields' => $this->utility->getLangFields(),
        ];
    }

    private function resolveParameterLabel(string $key, array $metadata): string
    {
        $fieldKey = $this->resolveParameterFieldKey($key, $metadata);

        return $metadata['lang_fields'][$key]['zh-CN']
            ?? ($metadata['fields'][$key]['zh-CN'] ?? null)
            ?? ($metadata['lang_fields'][$fieldKey]['zh-CN'] ?? null)
            ?? ($metadata['fields'][$fieldKey]['zh-CN'] ?? null)
            ?? $this->formatParameterDisplayKey($key);
    }

    private function applyParameterFieldMeta(array &$parameter, string $key, array $metadata): void
    {
        $fieldKey = $this->resolveParameterFieldKey($key, $metadata);

        if (isset($metadata['enums'][$key]) || isset($metadata['enums'][$fieldKey])) {
            $enumKey = isset($metadata['enums'][$key]) ? $key : $fieldKey;

            $parameter['value']   = Arr::random(Arr::pluck($metadata['enums'][$enumKey], 0));
            $parameter['options'] = Arr::pluck($metadata['enums'][$enumKey], 2, 0);
            $parameter['type']    = 'radio';

            return;
        }

        $parameter['type'] = $parameter['type']
            ?? $metadata['fields'][$key]['type']
            ?? $metadata['fields'][$fieldKey]['type']
            ?? null;
    }

    private function resolveParameterFieldKey(string $key, array $metadata): string
    {
        if (
            isset($metadata['fields'][$key])
            || isset($metadata['enums'][$key])
            || isset($metadata['lang_fields'][$key])
        ) {
            return $key;
        }

        $segments = preg_split('/[.\[\]]+/', $key, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $segments = array_values(array_filter($segments, static function (string $segment): bool {
            return $segment !== '*' && ! ctype_digit($segment);
        }));

        return $segments === [] ? $key : (string) end($segments);
    }

    private function formatParameterDisplayKey(string $key): string
    {
        $segments = explode('.', $key);
        if ($segments === []) {
            return $key;
        }

        $displayKey = array_shift($segments) ?: $key;
        foreach ($segments as $segment) {
            $displayKey .= '[' . ($segment === '*' ? '0' : $segment) . ']';
        }

        return $displayKey;
    }

    private function isRuleParameterRequired(array $rules): bool
    {
        if (in_array('required', $rules, true)) {
            return true;
        }

        if (in_array('sometimes', $rules, true) || in_array('nullable', $rules, true)) {
            return false;
        }

        foreach ($rules as $rule) {
            if (! is_string($rule)) {
                continue;
            }

            if (str_starts_with($rule, 'required_')) {
                return false;
            }
        }

        return true;
    }

    private function isRuleParameterSendable(string $key, array $rules, array $allRuleKeys): bool
    {
        if (str_ends_with($key, '.*')) {
            return false;
        }

        if (! in_array('array', $rules, true)) {
            return true;
        }

        // array 类型:有「对象元素」子键(field.*.xxx)或关联子键(field.xxx)→ 父不可单发(改填子字段);
        // 只有「标量元素」子键(field.* 且无更深)→ 数组-标量(如 ids 数组),父可单发(逗号/JSON 一次填),
        // 该 field.* 在 formatRules 里被父吸收、不单列。
        $prefix  = $key . '.';
        $starKey = $key . '.*';
        foreach ($allRuleKeys as $ruleKey) {
            if ($ruleKey === $key || ! str_starts_with($ruleKey, $prefix)) {
                continue;
            }
            if ($ruleKey === $starKey && $this->isScalarArrayElement($starKey, $allRuleKeys)) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * field.*(数组元素规则)是否「标量元素」—— 数组里装 id/数字/字符串等标量,
     * 而非嵌套对象(没有更深的 field.*.xxx 子键)。
     */
    private function isScalarArrayElement(string $key, array $allRuleKeys): bool
    {
        if (! str_ends_with($key, '.*')) {
            return false;
        }
        $deeper = $key . '.';   // field.*.
        foreach ($allRuleKeys as $ruleKey) {
            if ($ruleKey !== $key && str_starts_with($ruleKey, $deeper)) {
                return false;
            }
        }

        return true;
    }

    private function resolveRuleParameterType(string $key, array $rules, array $metadata): ?string
    {
        if (in_array('array', $rules, true)) {
            return 'array';
        }

        if (in_array('integer', $rules, true) || in_array('numeric', $rules, true)) {
            return 'int';
        }

        if (in_array('boolean', $rules, true)) {
            return 'boolean';
        }

        if (in_array('date', $rules, true)) {
            return 'date';
        }

        $fieldKey = $this->resolveParameterFieldKey($key, $metadata);

        return $metadata['fields'][$key]['type']
            ?? $metadata['fields'][$fieldKey]['type']
            ?? null;
    }

    private function buildRuleParameterDescription(string $key, array $rules, array $allRuleKeys, bool $sendable): string
    {
        $parts = [];
        $type  = null;

        if (in_array('array', $rules, true)) {
            $type    = 'array';
            $parts[] = str_ends_with($key, '.*') ? '数组元素对象' : '数组';
        }

        foreach ($rules as $rule) {
            if (! is_string($rule)) {
                continue;
            }

            if (str_starts_with($rule, 'required_with:')) {
                $fields  = array_filter(explode(',', substr($rule, strlen('required_with:'))));
                $parts[] = '传 ' . implode('、', $fields) . ' 时必填';

                continue;
            }

            if (str_starts_with($rule, 'required_without:')) {
                $fields  = array_filter(explode(',', substr($rule, strlen('required_without:'))));
                $parts[] = '缺少 ' . implode('、', $fields) . ' 时必填';

                continue;
            }

            if (str_starts_with($rule, 'max:')) {
                $max     = substr($rule, strlen('max:'));
                $parts[] = in_array($type, ['array'], true) ? '最多 ' . $max . ' 项' : '最大长度 ' . $max;

                continue;
            }

            if (str_starts_with($rule, 'min:')) {
                $min     = substr($rule, strlen('min:'));
                $parts[] = in_array($type, ['array'], true) ? '至少 ' . $min . ' 项' : '最小长度 ' . $min;

                continue;
            }

            if (str_starts_with($rule, 'exists:')) {
                $parts[] = '需为有效 ID';

                continue;
            }
        }

        if (! $sendable) {
            $children = array_values(array_filter($allRuleKeys, static fn (string $ruleKey): bool => str_starts_with($ruleKey, $key . '.')));
            if ($children !== []) {
                $parts[] = '结构说明，调试时请填写子字段';
            }
        }

        $parts = array_values(array_unique(array_filter(array_map('trim', $parts))));

        return implode('；', $parts);
    }

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

    private function appendParameterHint(string $description, string $hint): string
    {
        $description = trim($description);
        if ($description === '') {
            return $hint;
        }

        if (str_contains($description, $hint)) {
            return $description;
        }

        return $description . '；' . $hint;
    }

    /**
     * 用 Faker 伪造参数示例值
     */
    private function formatToFaker($faker, array $params): array
    {
        if (empty($params)) {
            return [];
        }

        foreach ($params as $fieldName => &$attr) {
            if (($attr['sendable'] ?? true) === false || $attr['value'] !== '' || $fieldName === '_method') {
                continue;
            }

            $type = $attr['type'] ?? null;

            if (str_contains($fieldName, '_ids')) {
                $attr['value'] = $faker->numberBetween(1, 3) . ',' . $faker->numberBetween(4, 7);
            } elseif (str_contains($fieldName, 'media_file')) {
                $attr['value'] = 'temp/demo/example.jpg';
            } elseif ($fieldName === 'password' || str_contains($fieldName, '_password')) {
                $attr['value'] = $faker->password;
            } elseif ($fieldName === 'address' || str_contains($fieldName, '_address')) {
                $attr['value'] = $faker->address;
            } elseif ($fieldName === 'mobile' || str_contains($fieldName, '_mobile')) {
                $attr['value'] = $faker->phoneNumber;
            } elseif ($fieldName === 'email' || str_contains($fieldName, '_email')) {
                $attr['value'] = $faker->safeEmail;
            } elseif ($fieldName === 'user_name' || $fieldName === 'nick_name') {
                $attr['value'] = $faker->userName;
            } elseif ($fieldName === 'id_card_number') {
                $attr['value'] = '';
            } elseif ($fieldName === 'real_name') {
                $attr['value'] = $faker->name(Arr::random(['male', 'female']));
            } elseif (str_contains($fieldName, '_code')) {
                $attr['value'] = $faker->numerify('C####');
            } elseif (in_array($type, ['int', 'tinyint', 'bigint'], true)) {
                $attr['value'] = 1;
            } elseif ($type === 'varchar' || $type === 'char') {
                $attr['value'] = implode(' ', $faker->words(2));
            } elseif ($type === 'text') {
                $attr['value'] = $faker->text(100);
            } elseif ($type === 'date') {
                $attr['value'] = $faker->date();
            } elseif ($type === 'datetime' || $type === 'timestamp') {
                $attr['value'] = $faker->date() . ' ' . $faker->time();
            } elseif ($type === 'boolean') {
                $attr['value'] = rand(0, 1);
                $attr['desc']  = '{1: true, 0: false}';
            }
        }

        return $params;
    }
}
