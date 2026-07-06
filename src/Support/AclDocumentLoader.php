<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Utility;

/**
 * plan-29:把原 AclController::getAclDocument 抽出成单独 service,供
 * RouteController 在抽屉里展示 ACL 详情时复用(白名单/中文名/兄弟 actions 等)。
 *
 * 数据来源:scaffold/acl/{app}.yaml(由 `php artisan moo:acl` 生成)。
 */
class AclDocumentLoader
{
    public function __construct(
        private readonly Utility $utility,
        private readonly Filesystem $filesystem,
    ) {}

    /** 读单个 app 的完整 ACL 文档(meta + modules)。yaml 不存在则返回空 default 结构。 */
    public function loadApp(string $app, string $appName = ''): array
    {
        $default = $this->defaultDocument($app, $appName);
        $file    = $this->utility->getAclPath() . $app . '.yaml';
        if (! $this->filesystem->isFile($file)) {
            return $default;
        }

        $data = $this->utility->parseYamlFile($file);
        if ($data === []) {
            return $default;
        }

        $meta    = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $stats   = is_array($meta['stats'] ?? null) ? $meta['stats'] : [];
        $modules = is_array($data['modules'] ?? null) ? array_values($data['modules']) : [];

        return [
            'meta' => [
                'app'          => (string) ($meta['app'] ?? $default['meta']['app']),
                'app_name'     => (string) ($meta['app_name'] ?? $default['meta']['app_name']),
                'generated_at' => (string) ($meta['generated_at'] ?? ''),
                'generated_by' => (string) ($meta['generated_by'] ?? ''),
                'path'         => $default['meta']['path'],
                'stats'        => [
                    'module_count'     => (int) ($stats['module_count'] ?? 0),
                    'controller_count' => (int) ($stats['controller_count'] ?? 0),
                    'action_count'     => (int) ($stats['action_count'] ?? 0),
                    'whitelist_count'  => (int) ($stats['whitelist_count'] ?? 0),
                ],
            ],
            'modules' => $modules,
        ];
    }

    /**
     * 把 modules → controllers → actions 三层结构压平成
     * `{controllerFQCN => {actionMethod => actionInfo}}` 索引,供 RouteController
     * 按 `class@method` O(1) 查 ACL 详情(中文名/白名单/备注/key 明密)。
     *
     * 同 action 在 yaml 里可能出现多次(如 store 兼 create 别名),取第一条即可。
     */
    /** 同请求内按 app 缓存压平索引:RouteController 的 aclIndexFor 与 crossAppIndex
     *  两条路径都会调这里,不缓存的话每个 app 的 ACL yaml(可达 200KB)每请求解析两遍(2026-06-10 修)。 */
    private array $indexCache = [];

    public function indexByControllerAction(string $app, string $appName = ''): array
    {
        if (array_key_exists($app, $this->indexCache)) {
            return $this->indexCache[$app];
        }

        $doc   = $this->loadApp($app, $appName);
        $index = [];
        foreach ($doc['modules'] as $module) {
            $moduleZh = (string) ($module['name']['zh-CN'] ?? $module['name'] ?? '');
            foreach (($module['controllers'] ?? []) as $controller) {
                $class = (string) ($controller['class'] ?? '');
                if ($class === '') {
                    continue;
                }
                $ctrlZh = (string) ($controller['name']['zh-CN'] ?? $controller['name'] ?? '');
                foreach (($controller['actions'] ?? []) as $action) {
                    $method = (string) ($action['action'] ?? '');
                    if ($method === '' || isset($index[$class][$method])) {
                        continue;
                    }
                    $index[$class][$method] = [
                        'plain_key'     => (string) ($action['plain_key'] ?? ''),
                        'key'           => (string) ($action['key'] ?? ''),
                        'title'         => (string) ($action['title'] ?? ''),
                        'zh_name'       => (string) ($action['name']['zh-CN'] ?? $action['name'] ?? ''),
                        'desc'          => (string) ($action['desc'] ?? ''),
                        'whitelist'     => (bool) ($action['whitelist'] ?? false),
                        'module_zh'     => $moduleZh,
                        'controller_zh' => $ctrlZh,
                        // plan-29 fix:transform_methods 场景(如 resource controller 的 create→store / edit→update)
                        'acl_transformed' => (bool) ($action['acl_transformed'] ?? false),
                        'acl_targets'     => array_values(array_filter(
                            (array) ($action['acl_targets'] ?? []),
                            fn ($t) => is_string($t) && $t !== ''
                        )),
                        'route_plain_key' => (string) ($action['route_plain_key'] ?? ''),
                    ];
                }
            }
        }

        return $this->indexCache[$app] = $index;
    }

    /** 返回 yaml 相对路径(用于抽屉里显示"来源") */
    public function yamlRelativePath(string $app): string
    {
        return 'scaffold/acl/' . $app . '.yaml';
    }

    /**
     * plan-29 #3 C3:把 plain_key 去掉 app 前缀作为"业务 key",跨 app 同业务 key 视为同名候选。
     * 例:`admin-light-book-index` 与 `api-light-book-index` 归一化后都是 `light-book-index`。
     * 单段 plain_key(如 `home`)归一化为自身,但通常这种最多 1 个 app 命中,不会触发对照 UI。
     */
    public function normalizeKey(string $plainKey): string
    {
        if ($plainKey === '') {
            return '';
        }
        $parts = explode('-', $plainKey, 2);

        return count($parts) >= 2 ? $parts[1] : $plainKey;
    }

    /**
     * 把所有 app 的 ACL yaml 一次扫完,返回 `{normalizedKey => [{app, plain_key, ...}]}` 反向索引,
     * 供 RouteController 检测"跨 app 同名"。
     *
     * @param array<string,string> $apps appKey => 中文名
     */
    public function indexCrossAppByNormalizedKey(array $apps): array
    {
        $cross = [];
        foreach ($apps as $appKey => $_) {
            $idx = $this->indexByControllerAction((string) $appKey);
            foreach ($idx as $class => $methods) {
                foreach ($methods as $method => $info) {
                    $plain = $info['plain_key'] ?? '';
                    if ($plain === '') {
                        continue;
                    }
                    $norm = $this->normalizeKey($plain);
                    if ($norm === '') {
                        continue;
                    }
                    $cross[$norm][] = [
                        'app'       => $appKey,
                        'plain_key' => $plain,
                        'zh_name'   => $info['zh_name'] ?? $info['title'] ?? '',
                        'class'     => $class,
                        'method'    => $method,
                    ];
                }
            }
        }

        return $cross;
    }

    /** 检查 app 的 ACL yaml 是否已生成 */
    public function exists(string $app): bool
    {
        return $this->filesystem->isFile($this->utility->getAclPath() . $app . '.yaml');
    }

    private function defaultDocument(string $app, string $appName): array
    {
        return [
            'meta' => [
                'app'          => $app,
                'app_name'     => $appName,
                'generated_at' => '',
                'generated_by' => '',
                'path'         => $this->yamlRelativePath($app),
                'stats'        => [
                    'module_count'     => 0,
                    'controller_count' => 0,
                    'action_count'     => 0,
                    'whitelist_count'  => 0,
                ],
            ],
            'modules' => [],
        ];
    }
}
