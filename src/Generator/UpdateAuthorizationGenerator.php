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
use Mooeen\Scaffold\Support\ActionDoc;
use Mooeen\Scaffold\Support\Paths;
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
        $dangerKeys       = [];   // 危险动作 key 集合（供授权页「全选」排除）
        $controllers      = [];
        $modules          = [];

        foreach ($routes as $route) {
            [$controller, $action] = explode('@', $route['action']);
            $owner                 = $this->registerControllerOwner($controller, $app, $base_namespace, $modules, $controllers);

            $action_info      = ActionDoc::parseActionInfo($this->getMethod($controller, $action));
            $action_name      = ActionDoc::parseActionName($this->getMethod($controller, $action));
            $route_action_key = Controller::aclPlainKey(str_replace('@', '::', $route['action']));
            $acl              = $this->aclResolver()->resolve($controller, $action);
            if (isset($acl['error'])) {
                throw new \RuntimeException("ACL 解析失败，未写入产物：{$controller}::{$action} ({$acl['error']})");
            }
            if (($acl['keys'] ?? []) === []) {
                $acl = [
                    'keys'        => [$this->getMd5($route_action_key)],
                    'plain_keys'  => [$route_action_key],
                    'key'         => $this->getMd5($route_action_key),
                    'plain_key'   => $route_action_key,
                    'targets'     => [$controller . '::' . $action],
                    'target_keys' => [$controller . '::' . $action => $this->getMd5($route_action_key)],
                    'target'      => $controller . '::' . $action,
                    'transformed' => false,
                ];
            }
            $targetInfo = [];
            foreach ($acl['target_keys'] as $target => $targetKey) {
                $methodInfo = $this->aclResolver()->targetMethodInfo($target);
                if ($methodInfo === null) {
                    // 兼容旧 CRUD：默认 create/edit/restore 映射允许目标方法被裁掉，
                    // 此时沿用入口自身的注解。显式自定义的无效目标仍须报错。
                    $defaultTarget = ['create' => 'store', 'edit' => 'update', 'restore' => 'trashed'][$action] ?? null;
                    if (! ($acl['uses_default_transform'] ?? false) || $defaultTarget === null || $target !== $controller . '::' . $defaultTarget) {
                        throw new \RuntimeException("ACL 目标不存在，未写入产物：{$target}");
                    }
                    $info             = $action_info;
                    $targetController = $controller;
                } else {
                    $info             = ActionDoc::parseActionInfo($methodInfo['reflection']);
                    $targetController = ltrim($methodInfo['class'], '\\');
                }
                $targetInfo[$target] = $info;
                if ($info['whitelist']) {
                    $whitelist[] = (string) $targetKey;

                    continue;
                }
                $targetOwner                                                                  = $this->registerControllerOwner($targetController, $app, $base_namespace, $modules, $controllers);
                $config_actions[$targetOwner['module_key']][$targetOwner['controller_key']][] = (string) $targetKey;
                if ($info['danger'] ?? false) {
                    $dangerKeys[(string) $targetKey] = true;
                }
            }

            $meta = [
                ...$owner,
                'controller'        => $controller,
                'action'            => $action,
                'route_plain_key'   => $route_action_key,
                'action_plain_key'  => $acl['plain_key'],
                'action_plain_keys' => $acl['plain_keys'],
                'action_key'        => $acl['key'],
                'action_keys'       => $acl['keys'],
                'acl_targets'       => $acl['targets'],
                'acl_target_keys'   => $acl['target_keys'],
                'acl_transformed'   => $acl['transformed'],
                'name'              => $action_name,
                'lang'              => $action_info['name'],
                'desc'              => $action_info['desc'],
                // 多目标为 OR：任一白名单目标便能使该路由公开，但不能污染其它目标。
                'whitelist'            => in_array(true, array_column($targetInfo, 'whitelist'), true),
                'target_authorization' => $targetInfo,
            ];

            $original_actions[] = $meta;
        }

        $this->buildActions($app, $config_actions, $whitelist, $configuredApps, $dangerKeys);
        $this->buildLangFiles($app, $config, $modules, $controllers, $original_actions, $configuredApps);
        $this->buildACLViewer($app, $config, $original_actions);

        return true;
    }

    /** 路由与跨控制器目标共用模块归属算法，含只有别名路由的目标。 */
    private function registerControllerOwner(string $controller, string $app, string $baseNamespace, array &$modules, array &$controllers): array
    {
        $names                       = ActionDoc::parsePMCNames($this->getController($controller));
        $moduleKey                   = $this->getMd5($app . '-' . Str::snake($names['module']['name']['en'], '-'));
        $controllerKey               = $this->getMd5(str_replace(['\\', $baseNamespace, '-controller'], ['', $app, ''], Str::snake($controller, '-')));
        $modules[$moduleKey]         = $names['module']['name'];
        $controllers[$controllerKey] = $names['controller']['name'];

        return [
            'module_key'      => 'module-' . $moduleKey,
            'module_name'     => $names['module']['name'],
            'controller_key'  => 'controller-' . $controllerKey,
            'controller_name' => $names['controller']['name'],
        ];
    }

    /**
     * 配置文件生成
     */
    private function buildActions(string $app, array $actions, array $whitelist, array $configuredApps, array $dangerKeys = []): void
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

        // 不变式：**任何被非白名单动作校验的 key，永不允许出现在白名单**。
        //
        // 2026-09-21 修「白名单污染」：动作缺 @acl 时上面把 `$meta['action_keys']` 塞进白名单，
        // 而那是 transform **目标**的 key（如 AttachmentController 的 $abilities=['WorkController::show',...]），
        // 于是别的 controller 的**真实权限点**被写进白名单。运行期 Gate 命中白名单即放行
        // （AuthServiceProvider 的 acl_authentication），后果是这些权限点对任何登录者恒真 ——
        // 实测 12 个 key / 90 个路由动作受影响，且 `hasAction('ContractController::show')` 恒真会
        // 短路 ContractAclCheckTrait / ReceivePaymentTrait，**按合同的数据范围 ACL 被整体绕过**。
        //
        // 保留的语义：真正「登录即可、不做授权」的动作（@acl 缺失）其 key 不在 actions 里，
        // 不受本不变式影响（实测 160 个白名单 key 中 148 个属此类）。
        $actionKeySet = [];
        foreach ($actions as $controllers) {
            foreach ($controllers as $actionKeys) {
                foreach ($actionKeys as $actionKey) {
                    $actionKeySet[(string) $actionKey] = true;
                }
            }
        }
        // 注意：$whitelist 此处尚未去重（同一 key 会被每个动作各加一次），故先取唯一再比对与报告，
        // 否则警告里会出现「68 个 key」这种把它们重复计数的误导性数字（实际只有 12 个唯一 key）。
        $whitelist = array_values(array_unique($whitelist));
        $conflicts = array_values(array_intersect($whitelist, array_keys($actionKeySet)));
        if ($conflicts !== []) {
            $whitelist = array_values(array_diff($whitelist, array_keys($actionKeySet)));
            $this->console()->warn(sprintf(
                '白名单与权限点冲突，已剔除 %d 个唯一 key（这些 key 变更为按权限校验，不再登录即放行）：%s',
                count($conflicts),
                implode(', ', $conflicts),
            ));
        }

        $config[$app] = [
            'whitelist' => array_values(array_unique($whitelist)),
            'actions'   => $actions,
            // 危险动作 key（`@acl {..., danger: 1}`）：授权页「全选」排除它们，避免误勾不可逆权限。
            'danger' => array_map('strval', array_keys($dangerKeys)),
        ];

        $file = config_path('actions.php');

        if ($this->requireArray($file) === $config && $this->hasGenerationStamp($file)) {
            $this->console()->unchanged('./config/actions.php');

            return;
        }

        $content = $this->buildPhpArrayFile($config, 'ACL 授权字典：app > whitelist / module > controller > action keys');

        if (! $this->putOrReport($file, './config/actions.php', $content)) {
            return;
        }

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
                foreach ($attr['acl_target_keys'] as $target => $actionKey) {
                    $info = $attr['target_authorization'][$target];
                    if ($info['whitelist']) {
                        continue;
                    }
                    // 文案、白名单和危险标记均取目标本身，别名不能改变授权语义。
                    $data[$app][$actionKey]          = $info['name'][$lang] ?? '';
                    $data[$app]["{$actionKey}-desc"] = $info['desc']        ?? '';
                }
            }

            if ($original === $data && $this->hasGenerationStamp($file_path)) {
                $this->console()->unchanged("./lang/{$lang}/actions.php");

                continue;
            }

            $content = $this->buildPhpArrayFile($data, "ACL 授权文案（{$lang}）：app / module / controller / action 的显示名与描述");

            if (! $this->putOrReport($file_path, "./lang/{$lang}/actions.php", $content)) {
                continue;
            }

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

        $dir = Paths::acl();
        $this->checkDirectory($dir);

        $file         = $dir . $app . '.yaml';
        $relativeFile = Paths::acl(true) . $app . '.yaml';

        if ($this->isAclDocumentUnchanged($file, $document)) {
            $this->console()->unchanged($relativeFile);

            return;
        }

        if (! $this->putOrReport($file, $relativeFile, Yaml::dump($document, 8, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK))) {
            return;
        }

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

    private function aclResolver(): AclActionResolver
    {
        return $this->aclActionResolver ??= new AclActionResolver;
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
