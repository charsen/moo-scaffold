<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Controllers;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Mooeen\Monitor\Cloud\CloudClient;
use Mooeen\Scaffold\Designer\SchemaLoader;
use Mooeen\Scaffold\Support\ApiSchemaService;
use Mooeen\Scaffold\Utility;

/**
 * Class     ScaffoldController
 *
 * @author Charsen
 */
class ScaffoldController extends Controller
{
    private const PUBLISH_HISTORY_PER_PAGE = 10;

    public function __construct(
        Utility $utility,
        Filesystem $filesystem,
        private readonly ApiSchemaService $apiSchemaService,
    ) {
        parent::__construct($utility, $filesystem);
    }

    /**
     * Show the dashboard.
     *
     *
     * @return View
     */
    public function index(Request $req)
    {
        $data         = [];
        $data['uri']  = $req->getPathInfo();
        $data['apps'] = $this->utility->getApps();
        $data         = array_merge($data, $this->getDashboardStats($req, $data['apps']));
        $data         = array_merge($data, $this->getCloudPanelData());

        return $this->view('dashboard', $data);
    }

    /** 缓存键:首页云端汇总(手动推送成功后由 CloudController 清掉,立即反映最新)。 */
    public const CLOUD_SUMMARY_CACHE_KEY = 'scaffold.cloud.summary';

    /**
     * 首页「云端汇聚」面板数据:接入状态 + 云端拉回的汇总 + 控制台入口。
     *
     * 故意不进 getDashboardStats —— 那条是纯本地、必出;这条依赖外部云端、可缺省。
     *
     * @return array{cloud_configured:bool,cloud_summary:?array<string,mixed>,cloud_console_url:?string}
     */
    private function getCloudPanelData(): array
    {
        $cfg        = (array) config('moo-monitor.cloud', []);
        $configured = (bool) ($cfg['enabled'] ?? false) && ! empty($cfg['base_url']) && ! empty($cfg['token']);
        $summary    = $configured ? $this->getCloudSummary() : null;

        return [
            'cloud_configured'  => $configured,
            'cloud_summary'     => $summary,
            'cloud_console_url' => $this->cloudConsoleUrl($cfg, $summary),
        ];
    }

    /**
     * 拉云端汇总,带缓存。成功缓存 60s;失败塞 15s 短 sentinel —— 云端挂时不每次刷新都干等、
     * 又能快速恢复。任何异常(含 cache 不可用)一律吞掉返回 null,首页绝不受牵连。
     *
     * @return array<string,mixed>|null
     */
    private function getCloudSummary(): ?array
    {
        $key = self::CLOUD_SUMMARY_CACHE_KEY;

        try {
            $cached = cache()->get($key);
            if (is_array($cached)) {
                return ($cached['__miss'] ?? false) ? null : $cached;
            }

            $r = (new CloudClient)->fetchSummary();
            if ($r['ok'] && is_array($r['data'])) {
                cache()->put($key, $r['data'], 60);

                return $r['data'];
            }

            cache()->put($key, ['__miss' => true], 15);

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 云端控制台入口:有 slug 深链到本项目(/app/{slug}),否则落 /app。base_url 未配 → null。
     */
    private function cloudConsoleUrl(array $cfg, ?array $summary): ?string
    {
        $base = rtrim((string) ($cfg['base_url'] ?? ''), '/');
        if ($base === '') {
            return null;
        }

        // slug 来自外部云端,形状可漂移:数组插字符串 → Array-to-string ErrorException
        // 整页 500(且坏 summary 被缓存 60s 连环炸)。非空字符串才深链,编码防特殊字符
        // 弄坏 URL(2026-06-10 修,补上轮只防了 Blade 侧的缺口)。
        $slug = $summary['project']['slug'] ?? null;
        if (! is_string($slug) || $slug === '') {
            return "{$base}/app";
        }

        return "{$base}/app/" . rawurlencode($slug);
    }

    /**
     * 数据字典
     *
     * 2026-05-24 二轮 audit C4:从 DatabaseController 合并过来。
     * 原 DatabaseController 在 2026-05-23 数据库文档功能砍掉(5f8250d)后只剩这一 method,
     * 整 file 69 行托一个 method 不划算 → 合并到 ScaffoldController(同 extends Controller)。
     */
    public function dictionaries(Request $req)
    {
        $menus    = $this->utility->getTables();
        $allEnums = $this->utility->getEnums(false);

        $data            = [];   // [moduleKey => [tableName => dictionaries]]
        $moduleSummaries = [];   // [moduleKey => {name, table_count, field_count, value_count}]
        $stats           = ['table_count' => 0, 'field_count' => 0, 'value_count' => 0];

        foreach ($menus as $moduleKey => $folder) {
            $moduleData       = [];
            $moduleFieldCount = 0;
            $moduleValueCount = 0;

            foreach (array_keys($folder['tables'] ?? []) as $tableName) {
                $dictionaries = $allEnums[$tableName] ?? [];
                if (empty($dictionaries)) {
                    continue;
                }
                $moduleData[$tableName] = $dictionaries;
                $moduleFieldCount += count($dictionaries);
                foreach ($dictionaries as $rows) {
                    $moduleValueCount += count($rows);
                }
            }

            if (empty($moduleData)) {
                continue;
            }

            $data[$moduleKey]            = $moduleData;
            $moduleSummaries[$moduleKey] = [
                'name'        => $folder['folder_name'] ?? $moduleKey,
                'origin'      => $folder['origin']      ?? null,   // plan-53:包模块视觉标注用
                'table_count' => count($moduleData),
                'field_count' => $moduleFieldCount,
                'value_count' => $moduleValueCount,
            ];
            $stats['table_count'] += count($moduleData);
            $stats['field_count'] += $moduleFieldCount;
            $stats['value_count'] += $moduleValueCount;
        }

        return $this->view('db.dictionaries', [
            'menus'            => $menus,
            'uri'              => $req->getPathInfo(),
            'data'             => $data,
            'module_summaries' => $moduleSummaries,
            'stats'            => $stats,
        ]);
    }

    /**
     * 数据库文档(只读)
     *
     * 2026-06-20:重新引入(2026-05-23 砍掉,designer 替代;但 designer 是编辑器外壳,纯查阅体验不如
     * 旧的只读 doc)。读 SchemaLoader(yaml 源,跟 designer 同一份,始终是当前设计),3 栏:
     * 模块(aside)→ 表(middle)→ 表详情 doc(right,字段/索引/枚举)。?schema/?table 服务端选中。
     */
    public function dbDocs(Request $req, SchemaLoader $loader)
    {
        $modules = $loader->listModules();   // [schema => {name, tables_count, fields_count, desc, ...}]

        $schema = (string) $req->query('schema', '');
        if ($schema === '' || ! isset($modules[$schema])) {
            $schema = (string) (array_key_first($modules) ?? '');
        }

        $tables = $schema !== '' ? $loader->loadModuleTables($schema) : [];

        $tableKey = (string) $req->query('table', '');
        $detail   = null;
        if ($tableKey !== '' && isset($tables[$tableKey])) {
            try {
                $detail = $loader->loadTableFull($schema, $tableKey);
            } catch (\Throwable) {
                $detail = null;   // yaml 坏/表不存在 → 退回"请选择数据表"空态
            }
        }

        return $this->view('db.docs', [
            'modules'        => $modules,
            'current_schema' => $schema !== '' ? $schema : null,
            'tables'         => $tables,
            'current_table'  => $detail ? $tableKey : null,
            'detail'         => $detail,
            'uri'            => $req->getPathInfo(),
        ]);
    }

    /**
     * 首页统计数据
     */
    private function getDashboardStats(Request $request, array $apps): array
    {
        $tables               = $this->getTablesSafely();
        $controllers          = $this->getControllersSafely();
        $apiStats             = $this->summarizeAppsCached($apps);
        $publishHistory       = $this->getApiPublishHistory($apps);
        $publishHistoryGroups = $this->groupApiPublishHistory($publishHistory);
        $paginatedHistory     = $this->paginatePublishHistoryGroup($request, $publishHistoryGroups);

        $tableCount = 0;
        foreach ($tables as $item) {
            $tableCount += (int) ($item['tables_count'] ?? count($item['tables'] ?? []));
        }

        return [
            'stats' => [
                ['label' => '应用',    'value' => count($apps)],
                ['label' => '模块',    'value' => count($tables)],
                // 按模块求和:getControllers(true) 的扁平合并按短类名作 key,跨模块同名
                // (如 Solution/WorkTask 各有 CategoryController)互相覆盖 → 少计(2026-06-10 修)
                ['label' => '控制器',  'value' => array_sum(array_map('count', $controllers))],
                ['label' => '接口',    'value' => $apiStats['api_count']],
                ['label' => '数据表',  'value' => $tableCount],
            ],
            // publish_history 整包(全部文件 × 全部 action)从视图数据里移除 —— 视图只用
            // groups / active group / paginator,整包只在内存里分组用,传出去是纯浪费。
            'publish_history_groups'       => $publishHistoryGroups,
            'active_publish_history_group' => $paginatedHistory['group'],
            'publish_history_paginator'    => $paginatedHistory['paginator'],
            'command_shortcut'             => $this->getCommandShortcut(),
            'command_guides'               => $this->getCommandGuides(),
        ];
    }

    /**
     * 「接口」等 app 级统计带签名缓存:summarizeApps 为算首页几个数字,每请求全量
     * parse 所有 app 的全部 schema yaml(wn 142 个 ≈ 90ms,随接口数线性涨)。
     * 按「文件名+mtime+apps」签名缓存 —— moo:api 发布/手改 schema 即换签名立即
     * 失效;cache 不可用退化为现算(2026-06-10 修,同 getApiPublishHistory 模式)。
     */
    private function summarizeAppsCached(array $apps): array
    {
        $basePath = rtrim($this->utility->getApiPath('schema'), '/') . '/';
        $sig      = [];
        foreach (array_keys($apps) as $app) {
            $appPath = $basePath . $app;
            if (! $this->filesystem->isDirectory($appPath)) {
                continue;
            }
            foreach ($this->filesystem->allFiles($appPath) as $file) {
                $sig[] = $app . '/' . $file->getRelativePathname() . ':' . $file->getMTime();
            }
        }
        sort($sig);
        $cacheKey = 'scaffold.app_stats.' . md5(implode('|', $sig) . '|' . serialize($apps));

        try {
            $cached = cache()->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable) {
            // cache 不可用 → 现算
        }

        $stats = $this->apiSchemaService->summarizeApps($apps);

        try {
            cache()->put($cacheKey, $stats, 600);
        } catch (\Throwable) {
            // 缓存写失败不影响本次渲染
        }

        return $stats;
    }

    /**
     * 首页快捷命令
     */
    private function getCommandShortcut(): array
    {
        return [
            'title'   => '一键生成',
            'desc'    => '后台模块最常见的一条龙生成；会串起 fresh、model、resource、controller、i18n、auth、migration，带 -a 时再补 api。',
            'command' => 'php artisan moo:free [app] [schema] [-f] [-a]',
            'example' => 'php artisan moo:free admin Light -a',
        ];
    }

    /**
     * 首页命令速查（按分组组织）
     *
     * 主流程：moo:free 串起来的一条龙生成命令(13 步)。
     * 运维：日常不会动,但需要时找得到的辅助命令(账号引导 / designer baseline / yaml↔DB 对账)。
     * 故意不列：moo:scaffold:merge-yaml(scaffold-sync.sh 内部调用,人类不直接跑)。
     */
    private function getCommandGuides(): array
    {
        $author = trim((string) $this->config('author'));
        $author = $author === '' ? 'Charsen' : str_replace('"', '\"', $author);

        return [
            [
                'name'  => '主流程',
                'hint'  => '一条龙生成 / 13 步',
                'items' => [
                    [
                        'step'    => '01',
                        'title'   => '初始化 Scaffold',
                        'desc'    => '首次接入时执行一次，写入作者并准备基础目录。',
                        'command' => 'php artisan moo:init "{author}"',
                        'example' => 'php artisan moo:init "' . $author . '"',
                    ],
                    [
                        'step'    => '02',
                        'title'   => '发布配置与静态资源',
                        'desc'    => '首次接入或资源更新后执行；先发布 config，再发布 public 静态资源。',
                        'command' => 'php artisan vendor:publish --provider="Mooeen\\Scaffold\\ScaffoldProvider" --tag={config|public} [--force]',
                        'example' => 'php artisan vendor:publish --provider="Mooeen\\Scaffold\\ScaffoldProvider" --tag=public --force',
                    ],
                    [
                        'step'    => '03',
                        'title'   => '创建模块 Schema',
                        'desc'    => '先定义模块、表、字段和控制器结构。',
                        'command' => 'php artisan moo:schema {schema_name} [-f]',
                        'example' => 'php artisan moo:schema Light',
                    ],
                    [
                        'step'    => '04',
                        'title'   => '刷新 Schema 缓存',
                        'desc'    => '让 storage/scaffold 中的缓存和 schema 保持同步。',
                        'command' => 'php artisan moo:fresh [-c]',
                        'example' => 'php artisan moo:fresh',
                    ],
                    [
                        'step'    => '05',
                        'title'   => '生成 Model',
                        'desc'    => '生成 Model / Trait / Enum；-F 生成 Factory，-T 生成前端 TypeScript 模型。Trait 和 Enum 每次强制刷新。',
                        'command' => 'php artisan moo:model [schema_name] [-f] [-F] [-T]',
                        'example' => 'php artisan moo:model Light -F -T',
                    ],
                    [
                        'step'    => '06',
                        'title'   => '生成 Resource',
                        'desc'    => '把列表、表单和资源输出结构补齐。',
                        'command' => 'php artisan moo:resource [schema_name] [-f]',
                        'example' => 'php artisan moo:resource Light',
                    ],
                    [
                        'step'    => '07',
                        'title'   => '生成 Controller / Request',
                        'desc'    => '同步创建控制器、请求类并更新路由插槽。',
                        'command' => 'php artisan moo:controller [schema_name] [-f]',
                        'example' => 'php artisan moo:controller Light',
                    ],
                    [
                        'step'    => '08',
                        'title'   => '生成路由契约测',
                        'desc'    => '给每个控制器吐一个 Pest 路由契约冒烟测（class_exists + 路由注册，纯静态、不碰 DB/auth，永不烂），已并入 moo:free。',
                        'command' => 'php artisan moo:test [schema_name] [-f]',
                        'example' => 'php artisan moo:test Light',
                    ],
                    [
                        'step'    => '09',
                        'title'   => '生成前端页面',
                        'desc'    => '生成 Vue 页面骨架（index / trashed / show）；这一步不在 moo:free 的流程里。',
                        'command' => 'php artisan moo:view [schema_name] [-f]',
                        'example' => 'php artisan moo:view Light',
                    ],
                    [
                        'step'    => '10',
                        'title'   => '生成迁移文件',
                        'desc'    => '产出 migration 后，再决定是否继续执行 migrate。',
                        'command' => 'php artisan moo:migration [schema_name]',
                        'example' => 'php artisan moo:migration Light',
                    ],
                    [
                        'step'    => '11',
                        'title'   => '更新多语言',
                        'desc'    => '同步字段、表名、枚举对应的 i18n 内容。',
                        'command' => 'php artisan moo:i18n',
                        'example' => 'php artisan moo:i18n',
                    ],
                    [
                        'step'    => '12',
                        'title'   => '更新 ACL',
                        'desc'    => '根据真实路由和控制器方法重新整理权限动作。',
                        'command' => 'php artisan moo:auth [app] [-r]',
                        'example' => 'php artisan moo:auth admin',
                    ],
                    [
                        'step'    => '13',
                        'title'   => '生成 API 文档',
                        'desc'    => '输出接口 YAML 并记录发布历史。-a 处理所有 namespace；--stale 控制已删除路由的处理方式（deprecate / keep / delete）。',
                        'command' => 'php artisan moo:api [app] [namespace] [-f] [-a] [--stale=deprecate]',
                        'example' => 'php artisan moo:api admin -a',
                    ],
                    [
                        'step'    => '14',
                        'title'   => '增量追加 Action',
                        'desc'    => '后续新增接口动作时，用这个命令补齐路由和控制器。',
                        'command' => 'php artisan moo:adder [app] [folder]',
                        'example' => 'php artisan moo:adder admin Light',
                    ],
                ],
            ],
            [
                'name'  => '运维 / 数据',
                'hint'  => '账号 · 快照 · 对账 · 体检',
                'items' => [
                    [
                        'step'    => 'A1',
                        'title'   => '新增开发账号',
                        'desc'    => '首账号引导 / 临时加账号都走这条；其它账号管理都在 Web 上。',
                        'command' => 'php artisan moo:account:add [username] [--password=] [--role=admin|member] [--disabled]',
                        'example' => 'php artisan moo:account:add charsen --role=admin',
                    ],
                    [
                        'step'    => 'A2',
                        'title'   => 'Designer baseline 快照',
                        'desc'    => '给现有 schema 一次性落 baseline；后续 designer diff 以此为起点。一个 schema 跑一次就够。',
                        'command' => 'php artisan moo:snapshot:init [--schema=] [--dry-run] [--force]',
                        'example' => 'php artisan moo:snapshot:init --schema=Light --dry-run',
                    ],
                    [
                        'step'    => 'A3',
                        'title'   => 'Schema ↔ DB 对账',
                        'desc'    => '随手查 yaml 跟实际 DB 是否漂移（列类型 / varchar 长度 / 单列 unique 索引）；纯只读、任何环境可跑（含核对生产），有漂移退出码 1，可当 pre-commit / CI 闸门。',
                        'command' => 'php artisan moo:db:audit [--schema=]',
                        'example' => 'php artisan moo:db:audit --schema=Light',
                    ],
                ],
            ],
        ];
    }

    /**
     * 获取接口发布历史
     */
    private function getApiPublishHistory(array $apps, ?int $limit = null): array
    {
        $historyPath = rtrim($this->utility->getApiPath('history'), '/') . '/';
        if (! $this->filesystem->isDirectory($historyPath)) {
            return [];
        }

        $files = array_filter(
            $this->filesystem->files($historyPath),
            static fn ($file): bool => str_ends_with($file->getBasename(), '.yaml')
        );

        // 列表/分组/分页只需 meta 级数据,而历史文件随每次 moo:api 无限增长(wn 半月已 187 个,
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
            // 文件数 × action 数(wn 已 1 万+)的开销,而视图只展示当前分页 10 条。
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
    private function groupApiPublishHistory(array $publishHistory): array
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
    private function paginatePublishHistoryGroup(Request $request, array $publishHistoryGroups): array
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
        $rawApp    = $request->query('history_app');
        $activeKey = is_string($rawApp) ? $rawApp : $defaultKey;
        if (! isset($groupsByKey[$activeKey])) {
            $activeKey = $defaultKey;
        }

        $activeGroup = $groupsByKey[$activeKey];
        $items       = $activeGroup['items'] ?? [];
        $perPage     = self::PUBLISH_HISTORY_PER_PAGE;
        $total       = count($items);
        $lastPage    = max(1, (int) ceil($total / $perPage));
        $rawPage     = $request->query('history_page', 1);
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

        $query                = $request->query();
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

    /**
     * 读取数据表缓存
     */
    private function getTablesSafely(): array
    {
        try {
            return $this->utility->getTables();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 读取控制器缓存(按模块分组形态,供首页统计无损求和)
     */
    private function getControllersSafely(): array
    {
        try {
            return $this->utility->getControllers(false);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * plan-22 安全审计 Q5:CSP 违规上报端点(从 routes.php closure 挪进 controller)
     *
     * 公开无登录(浏览器发 CSP 报告不带 cookie),已挂 throttle:60,1 防 DDoS。
     * 这里再加 payload size cap 8KB,防有人灌大包炸 fpm worker。
     */
    public function cspReport(Request $req)
    {
        if (strlen($req->getContent()) > 8192) {
            return response('payload too large', 413);
        }

        $payload = $req->isJson() ? $req->json()->all() : $req->all();
        Log::channel(config('logging.default'))
            ->warning('scaffold.csp.violation', [
                'report' => $payload,
                'ua'     => $req->userAgent(),
                'ip'     => $req->ip(),
            ]);

        return response()->noContent();
    }
}
