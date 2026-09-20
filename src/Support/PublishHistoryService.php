<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Pagination\LengthAwarePaginator;
use Mooeen\Scaffold\Utility;

/**
 * 接口发布历史的**读侧**：目录扫描 → meta 级条目 → 按 app 分组 → 分页 → 当前页明细补 debug_url。
 *
 * **写侧在别处**：`CreateApiGenerator::writePublishHistory()` 每次 `moo:api` 落一个历史 yaml
 * 到 `Paths::api('history')`；本类只读、从不写那些文件。两边共用同一个目录约定。
 *
 * **从 `Http\Controllers\ScaffoldController` 外迁而来**（7 方法 / 285 行块，2026-09-20 第 7 项）：
 * 该控制器 795 → 约 510 行，「首页渲染」与「发布历史读取」不再混在一个类里。
 * 外迁时**方法体逐字节未动**，只做了 3 处机械替换（`getApiPublishHistory` /
 * `groupApiPublishHistory` / `paginatePublishHistoryGroup` 三个入口 `private` → `public`）；
 * 「没多没少」由两种机器证明兜住（方法集合差集 + 字节级重建比对，见 NOTES 对应条目）。
 *
 * **为什么这一族可以外迁**（判据是三层，不是「行数多」）：
 *   ① 有没有**单入口可达的族**：有 —— 族外只从 `getDashboardStats` 经 3 个入口进来，跨文件调用者 0；
 *   ② 族内是否**内聚**：是 —— 全部围绕「一份历史 yaml → 一条首页记录 → 一个 tab 的一页」；
 *   ③ 拆完两边是否各自成立：是 —— 新家 = 发布历史读取；旧家 `ScaffoldController` = 首页/路由/字典页渲染。
 * ⚠ 与「参数形状归一」（第 4 项）／「字段形状」（第 6 项）不同，这一族**不是零状态**：它要读文件系统、
 * 走 `cache()`、走 `route()`，所以拿不到「逐字 + 分类替换」那种搬法，只能注依赖 + 用行为等价证明。
 *
 * **公开面刻意只有 3 个**（族外真正调用的那 3 个）；`loadPublishHistoryActions` /
 * `summarizePublishOperations` / `resolvePublishHistoryAuthor` / `buildApiDebugUrl` 是族内助手，
 * 保持 `private` —— 搬出去不等于顺手扩大可见面。
 *
 * 三处**有意为之**的设计，别"顺手统一"：
 *   - `getApiPublishHistory` 只产 **meta 级**条目（文件/时间/app/作者/计数/operation 汇总），
 *     **不含 action 明细** —— 明细要 `route()` 构造 URL，全量构建是「文件数 × action 数」的开销，
 *     而视图只展示当前分页 10 条。明细由 `paginatePublishHistoryGroup` 切完页后按需补（懒加载）。
 *   - `$apps`（app key → 显示名）由**调用方传入**，本类不读 `AppTargetRegistry` / `config()`：
 *     这样它只依赖 `Filesystem` + `Utility::parseYamlFile` 两样，依赖面可被结构锚点钉死。
 *   - 两个列表级查询（历史文件集合、schema 文件集合）都按「文件名 + mtime（+ 入参签名）」做
 *     内容签名缓存，**不用 TTL**：发布/删除即换签名立刻反映，`cache` 不可用时退化为现算。
 *     本条与 `ScaffoldController::summarizeAppsCached()` 是同一模式（那处留在旧家，因为它还给
 *     「接口」统计用）。
 *
 * ⚠ 已知遗留（**外迁时保持原样**，属独立过堂项）：`resolvePublishHistoryAuthor` 对
 * `$meta['author']` 只做 `(string)` 强转 —— 若 yaml 里把 `author` 写成数组/映射，会触发
 * PHP 的 Array-to-string；`getApiPublishHistory` 里 `$meta['app']` 同形。现状已由测试钉住，
 * 要修是行为变更，别混进搬家类改动。
 */
final class PublishHistoryService
{
    private const PUBLISH_HISTORY_PER_PAGE = 10;

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Utility $utility,
    ) {}

    /**
     * 获取接口发布历史
     */
    public function getApiPublishHistory(array $apps, ?int $limit = null): array
    {
        $historyPath = rtrim(Paths::api('history'), '/') . '/';
        if (! $this->filesystem->isDirectory($historyPath)) {
            return [];
        }

        $files = array_filter(
            $this->filesystem->files($historyPath),
            static fn ($file): bool => str_ends_with($file->getBasename(), '.yaml')
        );

        // 列表/分组/分页只需 meta 级数据,而历史文件随每次 moo:api 无限增长(H1 半月已 187 个,
        // 全量 yaml parse ≈ 600ms/请求)。按「文件名+mtime+apps」签名缓存:发布/删除即换签名,
        // 立刻反映新数据,无 TTL 等待;cache 不可用时退化为现算,首页不受牵连(2026-06-10 修)。
        $sig = [];
        foreach ($files as $file) {
            $sig[] = $file->getBasename() . ':' . $file->getMTime();
        }
        sort($sig);
        $cacheKey = 'scaffold.publish_history.' . md5(implode('|', $sig) . '|' . serialize($apps));
        try {
            $cached = cache()->get($cacheKey);
            if (is_array($cached)) {
                return ($limit !== null && $limit > 0) ? array_slice($cached, 0, $limit) : $cached;
            }
        } catch (\Throwable) {
            // cache 不可用 → 现算
        }

        $data = [];
        // 只列 3+1 个真信号:新增(整文件)/ 追加(已有文件加新 action)/ 删除 / 弃用。
        // 'overwrite' 已经从 generator 路径砍掉(re-publish 改写没信号量),
        // 旧历史文件里残留的 'overwrite' 会在 summarizePublishOperations 里被过滤,
        // 不再在 badge 里露出。
        $operationLabels = [
            'create'     => '新增',
            'append'     => '追加',
            'delete'     => '删除',
            'deprecated' => '弃用',
        ];

        foreach ($files as $file) {
            $yamlData = $this->utility->parseYamlFile($file->getPathname());

            $meta       = is_array($yamlData['meta'] ?? null) ? $yamlData['meta'] : [];
            $rawActions = is_array($yamlData['actions'] ?? null) ? $yamlData['actions'] : [];
            $actions    = array_values(array_filter($rawActions, 'is_array'));

            if (empty($meta) && empty($actions)) {
                continue;
            }

            $operations = $this->summarizePublishOperations($actions, $operationLabels);

            $actionCount = (int) ($meta['action_count'] ?? count($actions));
            $author      = $this->resolvePublishHistoryAuthor($meta, $file->getPathname());
            $publishedAt = $meta['published_at'] ?? date('Y-m-d H:i:s', $file->getMTime());
            // action 明细(含 debug_url,每条一次 route())不在这里构建 —— 全量构建是
            // 文件数 × action 数(H1 已 1 万+)的开销,而视图只展示当前分页 10 条。
            // 改为 paginatePublishHistoryGroup 切完页后按需 loadPublishHistoryActions。

            $data[] = [
                'file'             => $file->getBasename(),
                'published_at'     => $publishedAt,
                'app'              => $meta['app']              ?? '',
                'app_name'         => $apps[$meta['app'] ?? ''] ?? ($meta['app'] ?? '-'),
                'namespace'        => $meta['namespace']        ?? 'Index',
                'author'           => $author,
                'controller_count' => (int) ($meta['controller_count'] ?? count(array_unique(array_column($actions, 'controller')))),
                'action_count'     => $actionCount,
                'operations'       => $operations,
                'relative_path'    => str_replace(base_path() . '/', '', $file->getPathname()),
                '_sort_time'       => strtotime((string) $publishedAt) ?: $file->getMTime(),
            ];
        }

        usort($data, static function (array $a, array $b): int {
            return ($b['_sort_time'] <=> $a['_sort_time'])
                ?: strcmp((string) $b['file'], (string) $a['file']);
        });

        $data = array_map(static function (array $item): array {
            unset($item['_sort_time']);

            return $item;
        }, $data);

        try {
            cache()->put($cacheKey, $data, 600);
        } catch (\Throwable) {
            // 缓存写失败不影响本次渲染
        }

        if ($limit !== null && $limit > 0) {
            $data = array_slice($data, 0, $limit);
        }

        return $data;
    }

    /**
     * 单个历史文件的 action 明细(含 debug_url)。只对当前分页展示的条目调用,
     * 把 route() 构建成本从「全部文件 × 全部 action」降到「10 个文件」。
     */
    private function loadPublishHistoryActions(array $entry): array
    {
        $relative = (string) ($entry['relative_path'] ?? '');
        if ($relative === '') {
            return [];
        }

        $yamlData = $this->utility->parseYamlFile(base_path($relative));
        $meta     = is_array($yamlData['meta'] ?? null) ? $yamlData['meta'] : [];
        $actions  = array_values(array_filter((array) ($yamlData['actions'] ?? []), 'is_array'));

        return array_map(
            fn (array $item): array => [
                ...$item,
                'debug_url' => $this->buildApiDebugUrl($meta, $item),
            ],
            $actions
        );
    }

    private function summarizePublishOperations(array $actions, array $labels): array
    {
        $counts = [];
        foreach ($actions as $item) {
            $operation = $item['operation'] ?? 'append';
            // 旧历史里残留的 'overwrite' 不进 badge 汇总,避免污染头部摘要
            if (! isset($labels[$operation])) {
                continue;
            }
            $counts[$operation] = ($counts[$operation] ?? 0) + 1;
        }

        return array_map(
            static fn (string $key, int $count): array => [
                'key'   => $key,
                'label' => $labels[$key] ?? $key,
                'count' => $count,
            ],
            array_keys($counts),
            $counts
        );
    }

    /**
     * 获取发布历史中的作者信息
     */
    private function resolvePublishHistoryAuthor(array $meta, string $path): string
    {
        $author = trim((string) ($meta['author'] ?? ''));
        if ($author !== '') {
            return $author;
        }

        try {
            $content = $this->filesystem->get($path);
        } catch (\Throwable) {
            return '';
        }

        if (preg_match('/^# @author\s+(.+)$/m', $content, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }

        return '';
    }

    /**
     * 按应用分组接口发布历史
     */
    public function groupApiPublishHistory(array $publishHistory): array
    {
        $groups = [];

        foreach ($publishHistory as $item) {
            $appKey = $item['app'] ?: 'unknown';

            if (! isset($groups[$appKey])) {
                $groups[$appKey] = [
                    'key'      => $appKey,
                    'pane_key' => preg_replace('/[^a-z0-9_-]+/i', '-', $appKey) ?: 'unknown',
                    'name'     => $item['app_name'] ?: $appKey,
                    'count'    => 0,
                    'items'    => [],
                ];
            }

            $groups[$appKey]['items'][] = $item;
            $groups[$appKey]['count']++;
        }

        return array_values($groups);
    }

    /**
     * 按 app 对首页发布历史分页
     */
    public function paginatePublishHistoryGroup(array $validated, array $publishHistoryGroups): array
    {
        if (empty($publishHistoryGroups)) {
            return [
                'group'     => null,
                'paginator' => null,
            ];
        }

        $groupsByKey = [];
        foreach ($publishHistoryGroups as $group) {
            $groupsByKey[(string) $group['key']] = $group;
        }

        $defaultKey = (string) ($publishHistoryGroups[0]['key'] ?? 'unknown');
        // query 参数可被构造成数组(?history_app[]=x),(string) 强转数组 → ErrorException
        // 整页 500 —— 非字符串一律回落默认 tab(2026-06-10 修)
        $rawApp    = $validated['history_app'] ?? null;
        $activeKey = is_string($rawApp) ? $rawApp : $defaultKey;
        if (! isset($groupsByKey[$activeKey])) {
            $activeKey = $defaultKey;
        }

        $activeGroup = $groupsByKey[$activeKey];
        $items       = $activeGroup['items'] ?? [];
        $perPage     = self::PUBLISH_HISTORY_PER_PAGE;
        $total       = count($items);
        $lastPage    = max(1, (int) ceil($total / $perPage));
        $rawPage     = $validated['history_page'] ?? 1;
        $currentPage = min(max(1, (int) (is_scalar($rawPage) ? $rawPage : 1)), $lastPage);

        $paginator = new LengthAwarePaginator(
            array_slice($items, ($currentPage - 1) * $perPage, $perPage),
            $total,
            $perPage,
            $currentPage,
            [
                'path'     => route('scaffold.home', [], false),
                'pageName' => 'history_page',
            ]
        );

        $query                = $validated;
        $query['history_app'] = $activeKey;
        unset($query['history_page']);
        $paginator->appends($query);

        // 懒加载:action 明细只为当前页的条目构建(见 loadPublishHistoryActions)
        $activeGroup['items'] = array_map(
            fn (array $entry): array => array_merge($entry, ['items' => $this->loadPublishHistoryActions($entry)]),
            $paginator->items()
        );

        return [
            'group'     => $activeGroup,
            'paginator' => $paginator,
        ];
    }

    /**
     * 构建接口调试地址
     */
    private function buildApiDebugUrl(array $meta, array $action): ?string
    {
        $debug      = $action['debug']     ?? [];
        $app        = $debug['app']        ?? ($meta['app'] ?? '');
        $folder     = $debug['folder']     ?? ($meta['namespace'] ?? 'Index');
        $controller = $debug['controller'] ?? ($action['controller'] ?? '');
        $actionKey  = $debug['action']     ?? ($action['action_key'] ?? '');

        if ($app === '' || $folder === '' || $controller === '' || $actionKey === '') {
            return null;
        }

        return route('api.request', [
            'app' => $app,
            'f'   => $folder,
            'c'   => $controller,
            'a'   => $actionKey,
        ], false);
    }
}
