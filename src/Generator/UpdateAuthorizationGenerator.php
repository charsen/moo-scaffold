<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2025-08-13 10:16
 * @Description: Update Authorization Files
 */

namespace Mooeen\Scaffold\Generator;

use Brick\VarExporter\VarExporter;
use Illuminate\Support\Str;
use Mooeen\Scaffold\Foundation\Controller;
use Mooeen\Scaffold\Support\AclActionResolver;
use Symfony\Component\Yaml\Yaml;

class UpdateAuthorizationGenerator extends Generator
{
    private array $reflectionClasses = [];

    private array $reflectionMethods = [];

    private string $generatedAt = '';

    private string $generatedBy = '';

    private ?AclActionResolver $aclActionResolver = null;

    /**
     * 依据路由全量重算 config/actions.php、lang/{lang}/actions.php 和 scaffold/acl/{app}.yaml；
     * 内容完全由路由决定，不支持手动润色，但三类产物都在内容无变化时跳过写入(不刷生成戳)
     */
    public function start(string $app, array $routes): bool
    {
        $configuredApps    = array_keys($this->utility->getAppTargets());
        $this->generatedAt = date('Y-m-d H:i:s');
        $this->generatedBy = $this->utility->resolveCurrentLoginUser();

        $config         = $this->utility->getConfig('controller.' . $app);
        $base_namespace = ucfirst(str_replace('/', '', $config['path']));
        $base_namespace = Str::snake($base_namespace, '-');

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

            $controller_key               = str_replace(['\\', $base_namespace, '-controller'], ['', $app, ''], Str::snake($controller, '-'));
            $controller_key               = $this->getMd5($controller_key);
            $controllers[$controller_key] = $PMC_names['controller']['name'];

            $action_info      = $this->utility->parseActionInfo($this->getMethod($controller, $action));
            $action_name      = $this->utility->parseActionName($this->getMethod($controller, $action));
            $route_action_key = Controller::aclPlainKey(str_replace('@', '::', $route['action']));
            $acl              = $this->aclResolver()->resolve($controller, $action);
            if (($acl['keys'] ?? []) === []) {
                $acl = [
                    'keys'        => [$this->getMd5($route_action_key)],
                    'plain_keys'  => [$route_action_key],
                    'key'         => $this->getMd5($route_action_key),
                    'plain_key'   => $route_action_key,
                    'targets'     => [$controller . '::' . $action],
                    'target'      => $controller . '::' . $action,
                    'transformed' => false,
                ];
            }
            $auth_info = $this->resolveAuthorizationInfo($acl, $action_info);

            $meta = [
                'module_key'        => 'module-' . $module_key,
                'module_name'       => $PMC_names['module']['name'],
                'controller'        => $controller,
                'controller_key'    => 'controller-' . $controller_key,
                'controller_name'   => $PMC_names['controller']['name'],
                'action'            => $action,
                'route_plain_key'   => $route_action_key,
                'action_plain_key'  => $acl['plain_key'],
                'action_plain_keys' => $acl['plain_keys'],
                'action_key'        => $acl['key'],
                'action_keys'       => $acl['keys'],
                'acl_targets'       => $acl['targets'],
                'acl_transformed'   => $acl['transformed'],
                'name'              => $action_name,
                'lang'              => $action_info['name'],
                'auth_lang'         => $auth_info['name'],
                'desc'              => $action_info['desc'],
                'auth_desc'         => $auth_info['desc'],
                'whitelist'         => $auth_info['whitelist'],
            ];

            if ($meta['whitelist']) {
                foreach ($meta['action_keys'] as $actionKey) {
                    $whitelist[] = $actionKey;
                }
            } elseif ($this->isCrossControllerTransform($meta)) {
                // transform_methods 跨 controller 复用 key 表达的是"运行时 ACL 校验复用"，
                // 不是"该 controller 真有此 action"——若仍写入当前 controller 的 actions 列表，
                // 权限树渲染时会用全局 key=>label 字典的 label（target controller 的 @acl 文案），
                // 导致与当前 controller 名错位（如"通知机器人管理 > 个人中心"）。
                // 同 controller 内部 transform（如 create→store / logins→index）不属于此类，正常展示。
            } else {
                foreach ($meta['action_keys'] as $actionKey) {
                    $config_actions[$meta['module_key']][$meta['controller_key']][] = $actionKey;
                }
            }

            $original_actions[] = $meta;
        }

        $this->buildActions($app, $config_actions, $whitelist, $configuredApps);
        $this->buildLangFiles($app, $config, $modules, $controllers, $original_actions, $configuredApps);
        $this->buildACLViewer($app, $config, $original_actions);

        return true;
    }

    /**
     * 配置文件生成
     */
    private function buildActions(string $app, array $actions, array $whitelist, array $configuredApps): void
    {
        $config = config('actions', []);

        foreach (array_diff(array_keys($config), $configuredApps) as $staleApp) {
            $section = $config[$staleApp] ?? null;
            if (is_array($section)
                && array_key_exists('whitelist', $section)
                && array_key_exists('actions', $section)) {
                unset($config[$staleApp]);
            }
        }

        foreach ($actions as $moduleKey => $controllers) {
            foreach ($controllers as $controllerKey => $actionKeys) {
                $actions[$moduleKey][$controllerKey] = array_values(array_unique($actionKeys));
            }
        }

        $config[$app] = [
            'whitelist' => array_values(array_unique($whitelist)),
            'actions'   => $actions,
        ];

        $file = config_path('actions.php');

        if ($this->requireArray($file) === $config && $this->hasGenerationStamp($file)) {
            $this->console()->unchanged('./config/actions.php');

            return;
        }

        $this->filesystem->put($file, $this->buildPhpArrayFile($config, 'ACL 授权字典：app > whitelist / module > controller > action keys'));
        $this->console()->updated('./config/actions.php');
    }

    /**
     * 生成多语言文件
     */
    private function buildLangFiles(string $app, array $controller, array $modules, array $controllers, array $actions, array $configuredApps): void
    {
        $languages = $this->utility->getConfig('languages');
        foreach ($languages as $lang) {
            $file_path = lang_path($lang . '/actions.php');

            $data     = $this->requireArray($file_path) ?? [];
            $original = $data;

            foreach (array_diff(array_keys($data), $configuredApps) as $staleApp) {
                if (is_array($data[$staleApp] ?? null)
                    && array_key_exists("app-{$staleApp}", $data[$staleApp])) {
                    unset($data[$staleApp]);
                }
            }
            $data[$app]               = [];
            $data[$app]["app-{$app}"] = $controller['name'][$lang];

            foreach ($modules as $key => $val) {
                $data[$app]["module-{$key}"] = $val[$lang];
            }

            foreach ($controllers as $key => $val) {
                $data[$app]["controller-{$key}"] = $val[$lang];
            }

            foreach ($actions as $attr) {
                if ($attr['whitelist']) {
                    continue;
                }
                foreach ($attr['action_keys'] as $actionKey) {
                    // 下游走 VarExporter::export(本就正确转义引号和反斜杠),原来这里把撇号替换成
                    // &apos; 字面量,多此一举且有害:权限树里所有撇号永久变成 &apos;(Tom's→Tom&apos;s)。
                    // 直接存原始值,转义交给 VarExporter(2026-06-11 修)。
                    $data[$app][$actionKey]          = $attr['auth_lang'][$lang] ?? $attr['lang'][$lang] ?? '';
                    $data[$app]["{$actionKey}-desc"] = $attr['auth_desc']        ?? $attr['desc'] ?? '';
                }
            }

            if ($original === $data && $this->hasGenerationStamp($file_path)) {
                $this->console()->unchanged("./lang/{$lang}/actions.php");

                continue;
            }

            $this->filesystem->put($file_path, $this->buildPhpArrayFile($data, "ACL 授权文案（{$lang}）：app / module / controller / action 的显示名与描述"));
            $this->console()->updated("./lang/{$lang}/actions.php");
        }
    }

    /**
     * 渲染「带生成戳的 PHP 数组产物」。
     *
     * 头部注释只记录本次运行的信息，**不参与**「有没有变化」的判定 —— 判定只比 return 的
     * 数组本身（见 requireArray 的调用点）。所以内容没变时既不重写文件，也不会刷新时间戳。
     * 开头形态按 host Pint 口径（declare_strict_types + linebreak_after_opening_tag=false）
     * 写死，避免产物一落地就被格式化工具改一遍。
     */
    private function buildPhpArrayFile(array $data, string $description): string
    {
        return '<?php declare(strict_types=1);' . PHP_EOL
            . PHP_EOL
            . '/*' . PHP_EOL
            . ' * ' . $description . PHP_EOL
            . ' *' . PHP_EOL
            . ' * 由 moo:auth 依据路由与 controller 的 @acl 注解生成，请勿手改。' . PHP_EOL
            . ' *' . PHP_EOL
            . ' * @generated_by ' . $this->generatedBy . PHP_EOL
            . ' * @generated_at ' . $this->generatedAt . PHP_EOL
            . ' */' . PHP_EOL
            . PHP_EOL
            . 'return ' . VarExporter::export($data) . ';' . PHP_EOL;
    }

    /**
     * 读取 PHP 数组产物用于内容比对；文件缺失、损坏或返回非数组时给 null，交由调用方重写
     */
    private function requireArray(string $file): ?array
    {
        if (! $this->filesystem->isFile($file)) {
            return null;
        }

        try {
            $data = $this->filesystem->getRequire($file);
        } catch (\Throwable) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * 产物是否已带生成戳头部。
     *
     * 头部不参与内容比对，所以 2.1.16 之前留下的无头部产物光靠比数组永远补不上；
     * 这个条件让它们被重写一次补齐，补完之后就一直命中，不会反复刷时间戳。
     */
    private function hasGenerationStamp(string $file): bool
    {
        if (! $this->filesystem->isFile($file)) {
            return false;
        }

        return str_contains((string) $this->filesystem->get($file), '@generated_at ');
    }

    /**
     * 生成 acl 可视化文件，用于检查对比
     */
    private function buildACLViewer(string $app, array $config, array $actions): void
    {
        $modules         = [];
        $controllerCount = 0;
        $whitelistCount  = 0;
        foreach ($actions as $item) {
            $moduleKey     = $item['module_key'];
            $controllerKey = $item['controller_key'];

            if (! isset($modules[$moduleKey])) {
                $modules[$moduleKey] = [
                    'key'              => $moduleKey,
                    'name'             => $item['module_name'],
                    'controller_count' => 0,
                    'action_count'     => 0,
                    'whitelist_count'  => 0,
                    'controllers'      => [],
                ];
            }

            if (! isset($modules[$moduleKey]['controllers'][$controllerKey])) {
                $modules[$moduleKey]['controllers'][$controllerKey] = [
                    'key'             => $controllerKey,
                    'class'           => $item['controller'],
                    'name'            => $item['controller_name'],
                    'action_count'    => 0,
                    'whitelist_count' => 0,
                    'actions'         => [],
                ];
                $modules[$moduleKey]['controller_count']++;
                $controllerCount++;
            }

            $actionPayload = [
                'key'         => $item['action_key'],
                'plain_key'   => $item['action_plain_key'],
                'action'      => $item['action'],
                'title'       => $item['name'],
                'name'        => $item['lang'],
                'desc'        => $item['desc'],
                'whitelist'   => $item['whitelist'],
                'acl_targets' => $item['acl_targets'],
            ];

            if ($item['acl_transformed'] || count($item['action_keys']) > 1) {
                $actionPayload['keys']            = $item['action_keys'];
                $actionPayload['plain_keys']      = $item['action_plain_keys'];
                $actionPayload['route_plain_key'] = $item['route_plain_key'];
                $actionPayload['acl_transformed'] = $item['acl_transformed'];
            }

            $modules[$moduleKey]['controllers'][$controllerKey]['actions'][] = $actionPayload;

            $modules[$moduleKey]['action_count']++;
            $modules[$moduleKey]['controllers'][$controllerKey]['action_count']++;

            if ($item['whitelist']) {
                $modules[$moduleKey]['whitelist_count']++;
                $modules[$moduleKey]['controllers'][$controllerKey]['whitelist_count']++;
                $whitelistCount++;
            }
        }

        foreach ($modules as &$module) {
            $module['controllers'] = array_values($module['controllers']);
        }
        unset($module);

        $document = [
            'meta' => [
                'app'          => $app,
                'app_name'     => $config['api_name'] ?? ($config['name']['zh-CN'] ?? $app),
                'generated_at' => $this->generatedAt,
                'generated_by' => $this->generatedBy,
                'stats'        => [
                    'module_count'     => count($modules),
                    'controller_count' => $controllerCount,
                    'action_count'     => count($actions),
                    'whitelist_count'  => $whitelistCount,
                ],
            ],
            'modules' => array_values($modules),
        ];

        $dir = $this->utility->getAclPath();
        $this->checkDirectory($dir);

        $file         = $dir . $app . '.yaml';
        $relativeFile = $this->utility->getAclPath(true) . $app . '.yaml';

        if ($this->isAclDocumentUnchanged($file, $document)) {
            $this->console()->unchanged($relativeFile);

            return;
        }

        $this->filesystem->put($file, Yaml::dump($document, 8, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        $this->console()->updated($relativeFile);
    }

    /**
     * ACL 文档除生成戳外是否与磁盘上的一致。
     *
     * `generated_at` / `generated_by` 记的是「谁在什么时候跑了命令」，不是 ACL 内容本身；
     * 无条件重写会让每次 moo:auth 都刷出一条只有时间戳变化的假 diff。文件缺失或解析失败
     * 时返回 false，走正常重写。
     */
    private function isAclDocumentUnchanged(string $file, array $document): bool
    {
        if (! $this->filesystem->isFile($file)) {
            return false;
        }

        try {
            $existing = Yaml::parse((string) $this->filesystem->get($file));
        } catch (\Throwable) {
            return false;
        }

        if (! is_array($existing)) {
            return false;
        }

        return $this->stripAclGenerationStamp($existing) === $this->stripAclGenerationStamp($document);
    }

    /**
     * 去掉只反映「本次运行」的 meta 字段，供内容比对使用
     */
    private function stripAclGenerationStamp(array $document): array
    {
        if (! is_array($document['meta'] ?? null)) {
            return $document;
        }

        unset($document['meta']['generated_at'], $document['meta']['generated_by']);

        return $document;
    }

    private function resolveAuthorizationInfo(array $acl, array $fallback): array
    {
        $targets = $acl['targets'] ?? [];
        if (count($targets) !== 1) {
            return $fallback;
        }

        $methodInfo = $this->aclResolver()->targetMethodInfo((string) $targets[0]);
        if ($methodInfo === null) {
            return $fallback;
        }

        return $this->utility->parseActionInfo($methodInfo['reflection']);
    }

    private function aclResolver(): AclActionResolver
    {
        return $this->aclActionResolver ??= new AclActionResolver;
    }

    /**
     * 判断 action 是否通过 transform_methods 把 ACL key 复用到了"别的 controller"上
     */
    private function isCrossControllerTransform(array $meta): bool
    {
        if (! ($meta['acl_transformed'] ?? false)) {
            return false;
        }

        $targets    = $meta['acl_targets'] ?? [];
        $controller = $meta['controller']  ?? '';
        if ($targets === [] || $controller === '') {
            return false;
        }

        foreach ($targets as $target) {
            if (! str_starts_with((string) $target, $controller . '::')) {
                return true;
            }
        }

        return false;
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

    private function getController(string $name): \ReflectionClass
    {
        return $this->reflectionClasses[$name] ??= new \ReflectionClass($name);
    }

    private function getMethod(string $controller, string $action): \ReflectionMethod
    {
        return $this->reflectionMethods["{$controller}@{$action}"] ??= new \ReflectionMethod($controller, $action);
    }
}
