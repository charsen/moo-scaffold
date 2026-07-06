<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Controllers;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Mooeen\Scaffold\Support\AclActionResolver;
use Mooeen\Scaffold\Support\AclDocumentLoader;
use Mooeen\Scaffold\Utility;
use ReflectionClass;

class RouteController extends Controller
{
    /** plan-29:每个 app 的 ACL 文档索引,按 controllerFQCN → method → actionInfo */
    private array $aclIndexCache = [];

    /** plan-29 #3 C3:跨 app normalize key 反向索引(整 controller 生命周期 cache) */
    private ?array $crossAppIndex = null;

    public function __construct(
        Utility $utility,
        Filesystem $filesystem,
        private readonly Router $router,
        private readonly AclActionResolver $aclActionResolver,
        private readonly AclDocumentLoader $aclDocumentLoader,
    ) {
        parent::__construct($utility, $filesystem);
    }

    public function index(Request $req)
    {
        $apps       = $this->utility->getApps();
        $currentApp = (string) $req->input('app', '');

        // plan-22 P1-U3:ACL/routes picker 加 cookie 30 天,跟 api doc/debug 两 picker 对齐
        // 入口无 ?app= 且 cookie 命中 → redirect 让 URL 反映上次选(sidebar / sub-nav active 才能对)
        if ($currentApp === '' && isset($apps[$lastApp = (string) $req->cookie('scaffold_routes_app', '')]) && $lastApp !== '') {
            return redirect()->route('route.list', ['app' => $lastApp]);
        }

        if ($currentApp === '' || ! isset($apps[$currentApp])) {
            $currentApp = array_key_first($apps) ?: '';
        }

        $appRoutes = [];
        foreach ($apps as $app => $name) {
            $appRoutes[$app] = [
                'name'    => $name,
                'modules' => $this->getAppRoutes($app),
            ];
        }

        $currentModule = (string) $req->input('m', '');
        $keyword       = trim((string) $req->input('keyword', ''));

        // plan-22 P1-U3:选定 app 后写 cookie 30 天(raw 对称读写,scaffold routes 不进 EncryptCookies)
        return response()->view('scaffold::route.index', [
            'uri'            => $req->getPathInfo(),
            'apps'           => $apps,
            'app_routes'     => $appRoutes,
            'current_app'    => $currentApp,
            'current_module' => $currentModule,
            'keyword'        => $keyword,
        ])->cookie('scaffold_routes_app', $currentApp, 60 * 24 * 30, '/', null, null, true, false);
    }

    private array $apiSchemaCache = [];

    private array $controllerMethodsCache = [];

    private function getAppRoutes(string $app): array
    {
        $controllerConfig = $this->config('controller.' . $app);
        if ($controllerConfig === null) {
            return [];
        }

        // 配置缺 path 键(手编 config 漏写)→ 裸取 ErrorException 整页 500;按"该 app 无路由"降级
        $configPath = (string) ($controllerConfig['path'] ?? '');
        if ($configPath === '') {
            return [];
        }

        $basePath     = str_replace('/', '\\', ucfirst(rtrim($configPath, '/')));
        $extraModules = $this->utility->getExtraModules($app); // ['System' => 'Mooeen\System\Http\Controllers\Admin']
        $routes       = collect($this->router->getRoutes())->filter(function ($route) use ($basePath, $extraModules) {
            $action = ltrim($route->getActionName(), '\\');
            if (str_contains($action, '\\Traits\\')) {
                return false;
            }
            if (str_starts_with($action, $basePath)) {
                return true;
            }
            foreach ($extraModules as $ns) {
                if ($ns !== '' && str_starts_with($action, $ns)) {
                    return true;
                }
            }

            return false;
        });

        $modules = [];
        foreach ($routes as $route) {
            $action = ltrim($route->getActionName(), '\\');

            // 包提供的额外模块（控制器在 extra_modules 命名空间下）：模块名取 key，FQCN 走包命名空间
            $extraModule = null;
            $extraNs     = '';
            foreach ($extraModules as $mod => $ns) {
                if ($ns !== '' && str_starts_with($action, $ns)) {
                    $extraModule = $mod;
                    $extraNs     = $ns;
                    break;
                }
            }

            if ($extraModule !== null) {
                $moduleName       = $extraModule;
                $controllerAction = substr($action, strlen($extraNs) + 1);
            } else {
                $relative = substr($action, strlen($basePath) + 1);
                $parts    = explode('\\', $relative);

                if (count($parts) >= 2) {
                    $moduleName       = $parts[0];
                    $controllerAction = implode('\\', array_slice($parts, 1));
                } else {
                    $moduleName       = 'Index';
                    $controllerAction = $parts[0];
                }
            }

            [$controllerClass, $method] = str_contains($controllerAction, '@')
                ? explode('@', $controllerAction, 2)
                : [$controllerAction, '__invoke'];

            $fullClass = $extraModule !== null
                ? $extraNs . '\\' . $controllerClass
                : rtrim($basePath, '\\') . ($moduleName !== 'Index' ? '\\' . $moduleName : '') . '\\' . $controllerClass;

            if (! $this->controllerHasMethod($fullClass, $method)) {
                continue;
            }

            $controllerShort = Utility::stripControllerSuffix($controllerClass);

            $methods = array_filter($route->methods(), fn ($m) => $m !== 'HEAD');
            $apiInfo = $this->resolveApiInfo($app, $moduleName, $controllerShort, $method);
            $acl     = $this->aclActionResolver->resolve($fullClass, $method);

            // plan-29:从 ACL yaml 拿中文备注 / 白名单 / 兄弟视角需要的 controller 中文名
            $aclYaml = $this->aclIndexFor($app)[$fullClass][$method] ?? null;

            // plan-29 #2 B:controller 文件路径 + 起始行号(抽屉展示用)
            $ctrlFile = $this->resolveControllerFile($fullClass, $method);

            // plan-29 #3 C3:跨 app 同名对照(自动排除本 app)
            $siblingApps = $this->siblingAppsFor($app, $aclYaml['plain_key'] ?? '');

            $modules[$moduleName]['routes'][] = [
                'methods'          => $methods,
                'method'           => implode('|', $methods),
                'uri'              => '/' . ltrim($route->uri(), '/'),
                'name'             => $route->getName(),
                'controller'       => $controllerShort,
                'controller_class' => $controllerClass,
                'controller_fqcn'  => $fullClass,
                'action'           => $method,
                'api_name'         => $apiInfo['name'],
                'acl_key'          => $acl['plain_key'] ?? '',
                'acl_hash'         => $acl['key']       ?? '',
                'middleware'       => $this->getRouteMiddleware($route),
                'debug_url'        => $apiInfo['debug_url'],
                'doc_url'          => $apiInfo['doc_url'],
                // plan-29:ACL yaml 派生字段(缺 yaml 时为空,UI 自然降级)
                'acl_zh_name'       => ($aclYaml['zh_name'] ?? '') ?: ($aclYaml['title'] ?? ''),
                'acl_module_zh'     => $aclYaml['module_zh']     ?? '',
                'acl_controller_zh' => $aclYaml['controller_zh'] ?? '',
                'acl_desc'          => $aclYaml['desc']          ?? '',
                'is_whitelist'      => (bool) ($aclYaml['whitelist'] ?? false),
                // plan-29 fix:transform_methods 场景透传到前端(create→store / edit→update 等)
                'acl_transformed' => (bool) ($aclYaml['acl_transformed'] ?? false),
                'acl_targets'     => $aclYaml['acl_targets']     ?? [],
                'route_plain_key' => $aclYaml['route_plain_key'] ?? '',
                // plan-29 #2 B
                'controller_file' => $ctrlFile['path'] ?? '',
                'controller_line' => $ctrlFile['line'] ?? null,
                // plan-29 #3 C3
                'sibling_apps' => $siblingApps,
            ];
        }

        ksort($modules);

        foreach ($modules as $key => &$module) {
            $module['name']        = $this->resolveModuleName($app, $key);
            $module['route_count'] = count($module['routes']);
            $controllers           = [];
            foreach ($module['routes'] as $route) {
                $controllers[$route['controller']] = true;
            }
            $module['controller_count'] = count($controllers);

            usort($module['routes'], fn ($a, $b) => strcmp($a['controller'], $b['controller']));
        }

        return $modules;
    }

    private function getRouteMiddleware(Route $route): array
    {
        $middleware = $route->gatherMiddleware();

        return array_values(array_filter($middleware, fn ($m) => is_string($m)));
    }

    /** plan-29:懒加载 + 缓存某个 app 的 ACL yaml 索引 */
    private function aclIndexFor(string $app): array
    {
        if (! array_key_exists($app, $this->aclIndexCache)) {
            $this->aclIndexCache[$app] = $this->aclDocumentLoader->indexByControllerAction($app);
        }

        return $this->aclIndexCache[$app];
    }

    /**
     * plan-29 #3 C3:基于 plain_key 去 app 前缀后的 normalized key,找其他 app 同名 action。
     * 返回每条带 display_label(避开前端模板字符串拼接)。
     */
    private function siblingAppsFor(string $currentApp, string $plainKey): array
    {
        if ($plainKey === '' || ! $this->aclDocumentLoader->exists($currentApp)) {
            return [];
        }
        if ($this->crossAppIndex === null) {
            $this->crossAppIndex = $this->aclDocumentLoader->indexCrossAppByNormalizedKey(
                $this->utility->getApps()
            );
        }
        $norm = $this->aclDocumentLoader->normalizeKey($plainKey);
        if ($norm === '' || ! isset($this->crossAppIndex[$norm])) {
            return [];
        }
        $apps = $this->utility->getApps();
        $hits = [];
        foreach ($this->crossAppIndex[$norm] as $hit) {
            if (($hit['app'] ?? '') === $currentApp) {
                continue;
            }
            $appLabel = $apps[$hit['app']] ?? $hit['app'];
            $zh       = $hit['zh_name'] !== '' ? $hit['zh_name'] : $hit['method'];
            $hits[]   = [
                'app'           => $hit['app'],
                'plain_key'     => $hit['plain_key'],
                'zh_name'       => $zh,
                'display_label' => $appLabel . ' · ' . $hit['plain_key'],
                'display_zh'    => $zh,
                'href'          => route('route.list', ['app' => $hit['app']]),
            ];
        }

        return $hits;
    }

    private function controllerHasMethod(string $controllerClass, string $method): bool
    {
        if (! array_key_exists($controllerClass, $this->controllerMethodsCache)) {
            try {
                if (! class_exists($controllerClass)) {
                    $this->controllerMethodsCache[$controllerClass] = [];
                } else {
                    $methods                                        = (new ReflectionClass($controllerClass))->getMethods(\ReflectionMethod::IS_PUBLIC);
                    $this->controllerMethodsCache[$controllerClass] = array_map(
                        fn ($m) => $m->getName(),
                        $methods
                    );
                }
            } catch (\Throwable) {
                $this->controllerMethodsCache[$controllerClass] = [];
            }
        }

        return in_array($method, $this->controllerMethodsCache[$controllerClass], true);
    }

    /** 反射结果按 class@method 缓存:每条路由一次 ReflectionClass+ReflectionMethod,
     *  400 条路由 = 800 个反射对象/请求;旁边 controllerHasMethod 早有缓存,这里补齐(2026-06-10 修)。 */
    private array $controllerFileCache = [];

    /** plan-29 #2 B:反射 controller 文件路径 + action 起始行号(抽屉里展示) */
    private function resolveControllerFile(string $fqcn, string $method): ?array
    {
        $cacheKey = $fqcn . '@' . $method;
        if (array_key_exists($cacheKey, $this->controllerFileCache)) {
            return $this->controllerFileCache[$cacheKey];
        }

        return $this->controllerFileCache[$cacheKey] = $this->doResolveControllerFile($fqcn, $method);
    }

    private function doResolveControllerFile(string $fqcn, string $method): ?array
    {
        try {
            if (! class_exists($fqcn)) {
                return null;
            }
            $rc   = new ReflectionClass($fqcn);
            $line = null;
            $file = null;
            if ($rc->hasMethod($method)) {
                $rm   = $rc->getMethod($method);
                $file = $rm->getFileName() ?: $rc->getFileName();
                $line = $rm->getStartLine() ?: $rc->getStartLine();
            } else {
                $file = $rc->getFileName();
                $line = $rc->getStartLine();
            }
            if (! $file) {
                return null;
            }
            // 转成相对 base_path(),抽屉展示更简洁
            $base     = base_path();
            $relative = str_starts_with($file, $base . DIRECTORY_SEPARATOR)
                ? substr($file, strlen($base) + 1)
                : $file;

            return [
                'path' => str_replace('\\', '/', $relative),
                'line' => $line ? (int) $line : null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveModuleName(string $app, string $moduleKey): string
    {
        $apiPath  = $this->utility->getApiPath('schema') . $app . '/';
        $yamlFile = $apiPath . '_menus_transform.yaml';

        if (! $this->filesystem->isFile($yamlFile)) {
            return $moduleKey;
        }

        $data = $this->utility->normalizeMenusTransform($this->utility->parseYamlFile($yamlFile));

        return $data[$moduleKey]['name'] ?? $moduleKey;
    }

    private function buildDebugUrl(string $app, string $folder, string $controller, string $actionKey): string
    {
        return route('api.request', [
            'app' => $app,
            'f'   => $folder,
            'c'   => $controller,
            'a'   => $actionKey,
        ], false);
    }

    private function buildDocUrl(string $app, string $folder, string $controller, string $actionKey): string
    {
        return route('api.list', [
            'app' => $app,
            'f'   => $folder,
            'c'   => $controller,
            'a'   => $actionKey,
        ], false);
    }

    private function resolveApiInfo(string $app, string $folder, string $controller, string $action): array
    {
        $default = ['name' => '', 'debug_url' => null, 'doc_url' => null];

        $cacheKey = $app . '/' . $folder . '/' . $controller;
        if (! array_key_exists($cacheKey, $this->apiSchemaCache)) {
            $folderPath = $folder === 'Index' ? '' : $folder;
            $yamlFolder = $app . (empty($folderPath) ? '' : '/' . $folderPath);

            try {
                $file                            = $this->utility->isApiFileExist($yamlFolder, $controller, 'schema');
                $this->apiSchemaCache[$cacheKey] = $this->utility->parseYamlFile($file);
            } catch (\Throwable) {
                $this->apiSchemaCache[$cacheKey] = null;
            }
        }

        $yamlData = $this->apiSchemaCache[$cacheKey];
        if ($yamlData === null || ! is_array($yamlData['actions'] ?? null)) {
            return $default;
        }

        foreach ($yamlData['actions'] as $key => $actionData) {
            $realAction = $this->utility->removeActionNameMethod((string) $key);
            if ($realAction === $action) {
                return [
                    'name'      => is_array($actionData) ? (string) ($actionData['name'] ?? '') : '',
                    'debug_url' => $this->buildDebugUrl($app, $folder, $controller, (string) $key),
                    'doc_url'   => $this->buildDocUrl($app, $folder, $controller, (string) $key),
                ];
            }
        }

        return $default;
    }
}
