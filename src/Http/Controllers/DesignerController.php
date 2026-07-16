<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Http\Controllers;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Mooeen\Scaffold\Designer\AiNotConfiguredException;
use Mooeen\Scaffold\Designer\AiUpstreamErrorException;
use Mooeen\Scaffold\Designer\CompactBlockedException;
use Mooeen\Scaffold\Designer\EmptyDiffException;
use Mooeen\Scaffold\Designer\MigrationCompacter;
use Mooeen\Scaffold\Designer\MigrationWriter;
use Mooeen\Scaffold\Designer\SchemaDiffService;
use Mooeen\Scaffold\Designer\SchemaLoader;
use Mooeen\Scaffold\Designer\SchemaLoadException;
use Mooeen\Scaffold\Designer\SnapshotStore;
use Mooeen\Scaffold\Designer\TranslationService;
use Mooeen\Scaffold\Generator\FreshStorageGenerator;
use Mooeen\Scaffold\Support\AccountStore;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;

/**
 * 数据库设计器 HTTP 端点。
 */
class DesignerController
{
    public function __construct(
        private SchemaLoader $loader,
        private SchemaDiffService $diff,
        private MigrationWriter $writer,
        private TranslationService $translator,
        private MigrationCompacter $compacter,
        private Utility $utility,
        private AccountStore $accountStore,
        private SnapshotStore $snapshot,
    ) {}

    /**
     * 当前登录用户能否设计数据库(designer 写权限):admin / 有 can_design_db 的 member → true。
     * auth 关闭 / 无登录用户(单用户开放模式)→ true(不锁)。决定 designer_locked 的 per-user 维度。
     */
    private function userCanDesign(): bool
    {
        $user = request()->attributes->get('scaffold_auth_user');
        if (! is_string($user) || $user === '') {
            return true;
        }

        return $this->accountStore->canDesignDb($user);
    }

    // ─── GET /scaffold/db/designer ────────────────────────────────────
    public function index(): View
    {
        // 2026-05-23:index 也喂 is_prod/is_readonly,避免"新建 Schema"按钮在 production 还能点
        $designerIsProd     = function_exists('app') && app()->environment('production');
        $designerIsReadonly = (bool) config('scaffold.config_ui.readonly', false);
        $designerCanDesign  = $this->userCanDesign();     // per-user 设计权限(admin / can_design_db),无权限 = 只读

        // plan-53 出身分块:host 一块 + 每个扩展包一块(schema 属于谁一眼可辨;仅 host 时不渲染块标题,视觉不变)
        $modules = $this->loader->listModules();
        $groups  = [];
        foreach ($modules as $key => $mod) {
            $origin = $mod['origin'] ?? null;
            $gKey   = $origin        ?? '';
            if (! isset($groups[$gKey])) {
                $groups[$gKey] = [
                    'origin'   => $origin,
                    'label'    => $origin ?? '宿主项目',
                    'writable' => $origin === null || $this->utility->targetContext($origin)->writable,
                    'modules'  => [],
                ];
            }
            $groups[$gKey]['modules'][$key] = $mod;
        }
        ksort($groups);     // '' (host) 天然排最前,包按 key 升序

        return view('scaffold::db.designer.index', [
            'designer_modules'       => $modules,
            'designer_module_groups' => $groups,
            'designer_stats'         => $this->loader->loadStats(),
            // 2026-05-30:字典卡片底部 stat 行(装饰 + 引导),口径同字典页
            'designer_dict_stats' => $this->utility->dictionaryStats(),
            // plan 19 v9 F2:首屏需要的 dbDesigner state(只 newSchema modal 用得到)
            'designer_initial' => [
                'csrfToken'            => csrf_token(),
                'createSchemaEndpoint' => route('db.designer.create_schema'),
            ],
            'designer_is_prod'     => $designerIsProd,
            'designer_is_readonly' => $designerIsReadonly,
            'designer_no_perm'     => ! $designerCanDesign,
            'designer_locked'      => $designerIsProd || $designerIsReadonly || ! $designerCanDesign,
        ]);
    }

    // ─── GET /scaffold/db/designer/{schema} ──────────────────────────
    public function show(Request $req, string $schema): View|RedirectResponse
    {
        try {
            $module = $this->loader->loadModule($schema);
            $tables = $this->loader->loadModuleTables($schema);
        } catch (SchemaLoadException $e) {
            // plan-37 后审 P1:schema 不存在 / yaml 损坏 → 重定向到 index 而不是 500 白屏
            return redirect()->route('db.designer.index')
                ->withErrors(['schema' => "schema {$schema} 加载失败：{$e->getMessage()}"]);
        }

        $tableKey = $req->query('table') ?: array_key_first($tables) ?: '';
        if ($tableKey !== '' && ! isset($tables[$tableKey])) {
            $tableKey = array_key_first($tables) ?: '';
        }
        $tableFull = $tableKey !== '' ? $this->loader->loadTableFull($schema, $tableKey) : null;

        // plan 19 v11:Controller / Resource 可选 app 列表(从 scaffold.controller 配置拉出)
        $controllerApps = [];
        foreach ((array) config('scaffold.controller', []) as $appKey => $appConf) {
            $controllerApps[$appKey] = [
                'key'   => $appKey,
                'label' => $appConf['name']['zh-CN'] ?? $appConf['name'] ?? $appKey,
            ];
        }

        // 2026-05-23:跟 ConfigController / AccountController 一致,把环境只读状态透传给 blade
        // 让 UI 层挂只读 banner + 写按钮 disabled,middleware 是后端兜底,这里是用户视觉守护。
        // 双层守护规则:后端中间件兜底 + 前端锁定态置灰。
        $designerIsProd     = function_exists('app') && app()->environment('production');
        $designerIsReadonly = (bool) config('scaffold.config_ui.readonly', false);
        $designerCanDesign  = $this->userCanDesign();     // per-user 设计权限(admin / can_design_db),无权限 = 只读

        // plan-53 出身:包 schema 详情页挂 git 归属高亮;vcs 拷贝包(非软链)整页只读(写权硬线的 UI 层)
        $origin         = $this->loader->originOf($schema);
        $originWritable = $origin === null || $this->utility->targetContext($origin)->writable;

        return view('scaffold::db.designer.show', [
            'schema'                     => $schema,
            'designer_modules'           => $this->loader->listModules(),
            'designer_current_module'    => $module,
            'designer_module_tables'     => $tables,
            'designer_controller_apps'   => $controllerApps,
            'designer_current_table_key' => $tableKey,
            'designer_current_table'     => $tableFull,
            'designer_fields'            => $tableFull['fields']                                 ?? [],
            'designer_enums'             => $tableFull['enums']                                  ?? [],
            'designer_migrations'        => $this->loader->loadMigrationsFor($schema, $tableKey) ?? [],
            'designer_preview'           => null,
            'designer_is_prod'           => $designerIsProd,
            'designer_is_readonly'       => $designerIsReadonly,
            'designer_no_perm'           => ! $designerCanDesign,
            'designer_origin'            => $origin,
            'designer_origin_readonly'   => ! $originWritable,
            'designer_locked'            => $designerIsProd || $designerIsReadonly || ! $designerCanDesign || ! $originWritable,
            // 跟 MigrationWriter::TYPE_TEMPLATES(Designer/MigrationWriter.php:12)对齐;按整数→浮点→字符串→时间→布尔→二进制→JSON 分组
            'designer_type_options' => [
                'bigint', 'int', 'tinyint',
                'decimal', 'double', 'float',
                'varchar', 'char', 'tinytext', 'text', 'mediumtext', 'longtext',
                'datetime', 'date', 'time', 'timestamp',
                'bool', 'binary', 'json', 'jsonb',
            ],
            // plan-51:unique 拆 app/db 两选项;移除老 'unique'(后端 load/save 仍兼容 legacy value)
            'designer_index_options' => [
                ''           => '—',
                'primary'    => 'primary',
                'unique-app' => 'unique（app · 软删过滤）',
                'unique-db'  => 'unique（DB · 强约束）',
                'index'      => 'index',
            ],
            'designer_yaml_raw' => $this->loader->loadRawTableText($schema, $tableKey),
        ]);
    }

    // ─── POST /scaffold/db/designer/{schema}/save ────────────────────
    public function save(Request $req, string $schema): JsonResponse
    {
        $payload = $req->validate([
            'module' => 'nullable|array',
            'tables' => 'required|array',
        ]);

        // 暂存 rename hints 到 session
        $renameHints = [];
        foreach ($payload['tables'] as $tableKey => $tableData) {
            foreach (($tableData['rename_hints'] ?? []) as $from => $to) {
                $renameHints["{$tableKey}.{$from}"] = $to;
            }
        }
        if ($renameHints !== []) {
            $req->session()->put("designer.rename_hints.{$schema}", $renameHints);
        }

        // 写回 yaml(stamp updated_* 用当前登录 scaffold 用户;ScaffoldAuthenticate 中间件已塞 attribute)
        $author = (string) $req->attributes->get('scaffold_auth_user', '');
        try {
            $this->loader->saveModule($schema, [
                'module' => $payload['module'] ?? [],
                'tables' => $payload['tables'],
            ], $author !== '' ? $author : null);
        } catch (Throwable $e) {
            return $this->error('WRITE_FAILED', 'YAML 写入失败：' . $e->getMessage(), 500);
        }

        // plan-40 §四 C-1:save 后清 storage/scaffold cache,避免下游 generator(moo:model / moo:i18n)
        // 读 stale cache。同步走 artisan moo:fresh 一次,失败只 warn(scaffold 单 dev 工具,best effort)。
        $this->refreshSchemaCache($schema);

        return $this->ok([
            'saved_at' => now()->format('Y-m-d H:i:s'),
            'warnings' => [],
        ]);
    }

    // ─── POST /scaffold/db/designer/translate ────────────────────────
    public function translate(Request $req): JsonResponse
    {
        $scene = $req->input('scene');
        try {
            if ($scene === 'fields') {
                $data = $req->validate([
                    'table' => 'required|string',
                    // 2026-05-21 bug:Order/order_plans 等没配 attrs.prefix 的表 frontend 传 prefix=""
                    // → engine 全局 ConvertEmptyStringsToNull 把 "" 转 null → required/string 全拒返 422
                    // → JSON 无 error 字段 → frontend toast "请求失败"。
                    // 改 present + nullable(字段必传 / 允许 null & 空字符串),controller 下方 rtrim((string)...) 把 null 转 ""。
                    // TranslationService::validateFieldsResponse 内部 $prefix !== '' 守卫已经能处理空 prefix(跳过 prefix 拼接整条逻辑)。
                    'prefix'            => 'present|nullable|string',
                    'existing_fields'   => 'array',
                    'existing_fields.*' => 'string|max:64',
                    // plan-37 后审 P1:限 50 项,避免 DDoS DeepSeek + token 烧空
                    'inputs'   => 'required|array|max:50',
                    'inputs.*' => 'string|max:64',
                    'lenient'  => 'sometimes|boolean',
                ]);
                // 2026-05-20 bug:user 表 prefix 含尾下划线(yaml.attrs.prefix=op_)时,
                // backend $prefix.'_' 拼成 op__(双下划线),AI 合法输出 op_xxx 全被误判 invalid。
                // 统一 strip 末尾 `_`,跟 designer.js batch translate(_buildAddPayload line 1388)一致。
                $sanitizedPrefix = rtrim((string) $data['prefix'], '_');
                $results         = $this->translator->translateFieldNames(
                    $data['table'],
                    $sanitizedPrefix,
                    $data['existing_fields'] ?? [],
                    $data['inputs'],
                    $this->loader->collectNamingSamples(30),
                    (bool) ($data['lenient'] ?? false),
                );

                return $this->ok($results);
            }
            if ($scene === 'enums') {
                $data = $req->validate([
                    'field'    => 'required|string',
                    'inputs'   => 'required|array|max:50',     // plan-37 后审 P1
                    'inputs.*' => 'string|max:64',
                ]);
                // 2026-05-21:喂 enum 样本(全仓 enum 条目)— 不再用 field naming samples,维度不对
                $results = $this->translator->translateEnumKeys(
                    $data['field'],
                    $data['inputs'],
                    $this->loader->collectEnumSamples(30),
                );

                return $this->ok($results);
            }
            if ($scene === 'spell_check') {
                $data = $req->validate([
                    'inputs'   => 'required|array|max:200',     // 200 字段上限够大表用,DDoS 守住
                    'inputs.*' => 'string|max:64',
                ]);
                $results = $this->translator->spellCheckFields($data['inputs']);

                return $this->ok($results);
            }
            if ($scene === 'table_short') {
                $data = $req->validate([
                    'module'   => 'required|string',
                    'inputs'   => 'required|array|max:5',
                    'inputs.*' => 'string|max:64',
                ]);
                $result = $this->translator->translateTableShort($data['module'], $data['inputs'][0] ?? '');

                return $this->ok(['result' => $result]);
            }

            return $this->error('VALIDATION_FAILED', 'scene 必须是 fields / enums / spell_check / table_short', 422);
        } catch (AiNotConfiguredException $e) {
            return $this->error('AI_NOT_CONFIGURED', $e->getMessage(), 503);
        } catch (AiUpstreamErrorException $e) {
            return $this->error('AI_UPSTREAM_ERROR', $e->getMessage(), 502);
        } catch (Throwable $e) {
            if (str_contains(strtolower($e->getMessage()), 'timeout')) {
                return $this->error('AI_TIMEOUT', $e->getMessage(), 504);
            }
            throw $e;
        }
    }

    // ─── GET /scaffold/db/designer/{schema}/preview ──────────────────
    public function preview(Request $req, string $schema): JsonResponse
    {
        $hints = (array) $req->session()->get("designer.rename_hints.{$schema}", []);

        try {
            $diff = $this->diff->diff($schema, $hints);
        } catch (SchemaLoadException $e) {
            return $this->error('SCHEMA_LOAD_FAILED', $e->getMessage(), 500);
        }

        // 检测疑似 rename
        if (! empty($diff['suspected_renames'])) {
            return $this->error('SUSPECTED_RENAMES', '可能存在改名，请在 UI 上明确点过改名按钮再来', 422, [
                'candidates' => $diff['suspected_renames'],
            ]);
        }

        // 渲染 migration 文件文本(不写盘)
        $rendered = $this->writer->render($diff);

        $summary = [
            'tables_changed' => 0,
            'tables_created' => 0,
            'tables_dropped' => 0,
        ];
        $tables = [];
        foreach ($diff['tables'] as $tableKey => $tableDiff) {
            if ($tableDiff['status'] === 'unchanged') {
                continue;
            }
            if ($tableDiff['status'] === 'created') {
                $summary['tables_created']++;
            } elseif ($tableDiff['status'] === 'dropped') {
                $summary['tables_dropped']++;
            } else {
                $summary['tables_changed']++;
            }
            $tables[$tableKey] = [
                'status'         => $tableDiff['status'],
                'summary_text'   => $this->summarizeTable($tableDiff),
                'field_changes'  => $tableDiff['field_changes'],
                'index_changes'  => $tableDiff['index_changes'],
                'warnings'       => $tableDiff['warnings'],
                'migration_file' => $rendered[$tableKey] ?? null,
            ];
        }

        return $this->ok([
            'schema'   => $diff['schema'],
            'is_empty' => $diff['is_empty'],
            // Round 2 P2:首次进 designer / 删 snapshot 后 UI 显示"baseline 缺失"提示,
            // 让 user 知道"首次 migrate 后会建立"
            'baseline_missing' => $diff['baseline_missing'] ?? false,
            'summary'          => $summary,
            'tables'           => $tables,
        ]);
    }

    // ─── DELETE /scaffold/db/designer/{schema}/migrations/{file} ─────
    // C 方案:user 测试 / 误生成的 migration 文件清理入口。
    // 前置:migrations 表无该 record(prod 没跑过),否则拒删。不动 snapshot —
    // user 自己决定要不要让 designer 重生成(需手动改 .snapshots/{Schema}.yaml)。
    public function deleteMigration(Request $req, string $schema, string $stem): JsonResponse
    {
        // 1) 文件名 stem 严校验(URL 不带 .php 后缀,避免 nginx 拦截走 fastcgi),routes regex 已限 [0-9a-zA-Z_]+
        if (! preg_match('/^[0-9a-zA-Z_]+$/', $stem)) {
            return $this->error('INVALID_FILE', '文件名格式非法', 422);
        }
        $file = $stem . '.php';
        // plan-53:按 schema 出身取 migration 目录;删包内文件同样要过软链写权闸
        try {
            $this->loader->assertOriginWritable($schema);
        } catch (SchemaLoadException $e) {
            return $this->error('READONLY_ORIGIN', $e->getMessage(), 403);
        }
        $migrationPath = $this->loader->migrationDirFor($schema) . '/' . $file;
        if (! is_file($migrationPath)) {
            return $this->error('NOT_FOUND', 'migration 文件不存在', 404);
        }

        // 2) check migrations 表 — 已 ran 拒删(user 必须先 rollback 才能删)
        $migrationName = substr($file, 0, -4); // strip ".php"
        try {
            $ran = \DB::table('migrations')->where('migration', $migrationName)->exists();
        } catch (Throwable $e) {
            // migrations 表不可达 → 安全起见拒删(避免错误判断为"没 ran")
            return $this->error('DB_UNREACHABLE', 'migrations 表不可达：' . $e->getMessage(), 503);
        }
        if ($ran) {
            return $this->error('ALREADY_RAN', '该 migration 已执行（migrations 表有记录）。请先 php artisan migrate:rollback，再删文件。', 409);
        }

        // 3) 删文件
        if (! @unlink($migrationPath)) {
            return $this->error('DELETE_FAILED', '删除失败，检查文件权限', 500);
        }

        // 4) C+ 方案:可选同时清整张表 baseline 子树,让 designer 重新 detect 该表 diff(重生成此 migration)
        //    DB 还有该表时,SchemaDiffService 走 baseline_drift 守护 → 不会误生成 create_table
        //    仅在 user 确认 DB 没跑过此 migration 时勾选(警示已在前端 modal 给)
        $baselineCleared = false;
        if ($req->boolean('clear_baseline')) {
            $tableKey = trim((string) $req->input('table_key', ''));
            if ($tableKey !== '' && preg_match('/^[a-z][a-z0-9_]*$/', $tableKey)) {
                try {
                    app(SnapshotStore::class)->unsetTables($schema, [$tableKey]);
                    $baselineCleared = true;
                } catch (Throwable $e) {
                    // 文件删了但 snapshot 没清 — log warn,返 partial-ok
                    Log::warning(
                        "deleteMigration: snapshot unsetTables failed for {$schema}.{$tableKey}: {$e->getMessage()}"
                    );
                }
            }
        }

        return $this->ok([
            'deleted'          => $file,
            'baseline_cleared' => $baselineCleared,
            'note'             => $baselineCleared
                ? '已删 migration 文件 + 清表 baseline，刷新 designer 可重新看到该表 diff。'
                : '已删 migration 文件。snapshot baseline 不变 — 如需重新生成此 migration，请手动编辑 .snapshots/' . $schema . '.yaml 把对应字段段去掉，再刷新 designer。',
        ]);
    }

    // ─── POST /scaffold/db/designer/{schema}/migrations/compact-preview ─
    // plan-49:dry-run,扫文件 + 重渲 create + drift 检测 + git push 检测,不动磁盘
    public function compactMigrationsPreview(Request $req, string $schema): JsonResponse
    {
        $data = $req->validate([
            'table' => 'required|string|max:64|regex:/^[a-z][a-z0-9_]*$/',
        ]);
        try {
            $preview = $this->compacter->preview($schema, $data['table']);

            return $this->ok($preview);
        } catch (CompactBlockedException $e) {
            return $this->error('COMPACT_BLOCKED', $e->getMessage(), 422, ['reason' => $e->reason]);
        } catch (SchemaLoadException $e) {
            return $this->error('SCHEMA_LOAD_FAILED', $e->getMessage(), 404);
        } catch (Throwable $e) {
            return $this->error('UNEXPECTED', $e->getMessage(), 500);
        }
    }

    // ─── POST /scaffold/db/designer/{schema}/migrations/compact ─────
    // plan-49:execute,真删 update 文件 + 真改写 create 文件 + 可选清 migrations 表
    public function compactMigrationsExecute(Request $req, string $schema): JsonResponse
    {
        $data = $req->validate([
            'table'    => 'required|string|max:64|regex:/^[a-z][a-z0-9_]*$/',
            'clean_db' => 'nullable|boolean',
            'force'    => 'nullable|boolean',     // 绕开 git_pushed 兜底:GUI 在已 push 时勾「未部署」确认框后传 true
        ]);
        try {
            $result = $this->compacter->execute($schema, $data['table'], [
                'clean_db' => (bool) ($data['clean_db'] ?? false),
                'force'    => (bool) ($data['force'] ?? false),
            ]);

            return $this->ok($result);
        } catch (CompactBlockedException $e) {
            return $this->error('COMPACT_BLOCKED', $e->getMessage(), 422, ['reason' => $e->reason]);
        } catch (SchemaLoadException $e) {
            return $this->error('SCHEMA_LOAD_FAILED', $e->getMessage(), 404);
        } catch (Throwable $e) {
            return $this->error('UNEXPECTED', $e->getMessage(), 500);
        }
    }

    // ─── POST /scaffold/db/designer/{schema}/migrate ─────────────────
    public function migrate(Request $req, string $schema): JsonResponse
    {
        $data = $req->validate([
            'only_table' => 'nullable|string',
        ]);

        $hints = (array) $req->session()->get("designer.rename_hints.{$schema}", []);

        try {
            $diff = $this->diff->diff($schema, $hints);

            // preview 会拦疑似改名,migrate 也必须拦 —— 否则未确认的改名会被 diff 当成
            // drop + add(删列 + 建列)而非 renameColumn,旧列数据直接丢(2026-06-09 修)。
            // only_table 时只看该表自己的疑似改名,不被其它表挡住。
            $suspected = $diff['suspected_renames'] ?? [];
            if (! empty($data['only_table'])) {
                $suspected = array_values(array_filter(
                    $suspected,
                    static fn ($s) => ($s['table'] ?? '') === $data['only_table'],
                ));
            }
            if (! empty($suspected)) {
                return $this->error('SUSPECTED_RENAMES', '可能存在改名，请在 UI 上明确点过改名按钮再来', 422, [
                    'candidates' => $suspected,
                ]);
            }

            // 只生成当前表的 migration:filter diff['tables'] 只留 only_table
            if (! empty($data['only_table'])) {
                $only           = $data['only_table'];
                $diff['tables'] = isset($diff['tables'][$only]) ? [$only => $diff['tables'][$only]] : [];
                // 重新算 is_empty(可能只剩 unchanged 状态的表)
                $diff['is_empty'] = empty($diff['tables']) || ! array_filter($diff['tables'], fn ($t) => ($t['status'] ?? 'unchanged') !== 'unchanged');
            }
            // Plan 39:GUI 不调 git commit,只写 migration 文件 + 推 baseline 快照
            $result = $this->writer->write($diff);
        } catch (EmptyDiffException $e) {
            return $this->error('EMPTY_DIFF', $e->getMessage(), 422);
        }

        // 落盘后清 rename hints
        // plan-37 后审 P1:only_table 只清匹配的 table.* 前缀,避免顺手清掉其它表用户还没 migrate 的 rename
        if (! empty($data['only_table'])) {
            $only = $data['only_table'];
            $kept = array_filter(
                $hints,
                static fn ($_v, $k) => ! str_starts_with((string) $k, $only . '.'),
                ARRAY_FILTER_USE_BOTH,
            );
            if ($kept === []) {
                $req->session()->forget("designer.rename_hints.{$schema}");
            } else {
                $req->session()->put("designer.rename_hints.{$schema}", $kept);
            }
        } else {
            $req->session()->forget("designer.rename_hints.{$schema}");
        }

        // plan-40 §四 C-1:migrate 后同样刷 cache(yaml 已经被 saveModule 改过 + 现在补 migration)
        $this->refreshSchemaCache($schema);

        return $this->ok([
            'files_written' => $result['files_written'],
        ]);
    }

    /**
     * plan-40 §四 C-1:designer save / migrate 后清下游 generator cache(storage/scaffold/*.php)。
     *
     * **不走 Artisan::call** — ScaffoldProvider 把所有 moo:* 命令限定在 `runningInConsole()`(line 97,
     * 注释明示"web endpoint 触发 → 攻击面放大"policy)。Web HTTP context 下 Artisan::call('moo:fresh')
     * 会 throw "does not exist",log 噪声大且 cache 实际没刷。
     *
     * 改成直接 inline FreshStorageGenerator(scaffold 自家 command 都已经这么干 — 见
     * CreateApi/CreateView/UpdateMultilingualCommand),走 NullOutput 静音。
     */
    private function refreshSchemaCache(string $schema): void
    {
        try {
            $gen = new FreshStorageGenerator(
                new NullOutput,                                  // 吸收所有 console output
                app(Filesystem::class),
                app(Utility::class),
            );
            $gen->start(clean: false, silence: true);
        } catch (Throwable $e) {
            Log::warning(
                "designer save/migrate cache refresh failed for {$schema}: {$e->getMessage()}",
            );
        }
    }

    // ─── GET /scaffold/db/designer/{schema}/migration-content?file=xxx.php ─
    public function migrationContent(Request $req, string $schema): JsonResponse
    {
        $filename = (string) $req->query('file', '');
        // 安全:只允许 basename + .php,禁 path traversal
        if ($filename === '' || basename($filename) !== $filename || ! str_ends_with($filename, '.php')) {
            return $this->error('INVALID_FILENAME', '文件名非法', 400);
        }
        // plan-37 后审 P1:文件必须属于该 schema 的 migration 列表,避免跨 schema 读
        $allowedFiles = array_column($this->loader->loadMigrationsFor($schema, $req->query('table', '') ?: ''), 'file');
        if (! in_array($filename, $allowedFiles, true)) {
            // 如果没指定 table,扫该 schema 所有 table 的 migration 文件
            $tables       = $this->loader->loadModuleTables($schema);
            $allowedFiles = [];
            foreach ($tables as $tk => $_) {
                foreach ($this->loader->loadMigrationsFor($schema, (string) $tk) as $m) {
                    $allowedFiles[] = $m['file'];
                }
            }
            if (! in_array($filename, $allowedFiles, true)) {
                return $this->error('NOT_FOUND', "migration 不属于 schema {$schema}:{$filename}", 404);
            }
        }
        // plan-53:按 schema 出身取 migration 目录(host / 扩展包)
        $abs = $this->loader->migrationDirFor($schema) . '/' . $filename;
        if (! is_file($abs)) {
            return $this->error('NOT_FOUND', "migration 文件不存在：{$filename}", 404);
        }

        return $this->ok([
            'filename' => $filename,
            'php_code' => (string) file_get_contents($abs),
        ]);
    }

    // ─── POST /scaffold/db/designer/{schema}/tables ───────────────────
    public function createTable(Request $req, string $schema): JsonResponse
    {
        // plan-40 §五 F4:跟 routes.php where regex / SchemaLoader 抛 throw 三处一致,
        // controller validate 早 reject 422 比 SchemaLoadException 更友好
        $data = $req->validate([
            'table_key' => 'required|string|regex:/^[a-z][a-z0-9_]*$/|max:64',
            'name'      => 'required|string|max:100',
            'desc'      => 'nullable|string|max:500',
            'prefix'    => 'nullable|string|max:30',     // plan 19 v8 D4
        ]);
        $author = (string) $req->attributes->get('scaffold_auth_user', '');
        try {
            $this->loader->createTable(
                $schema,
                $data['table_key'],
                $data['name'],
                $data['desc']   ?? '',
                $data['prefix'] ?? '',
                $author !== '' ? $author : null,
            );
        } catch (SchemaLoadException $e) {
            return $this->error('CREATE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok([
            'table_key'    => $data['table_key'],
            'redirect_url' => route('db.designer.show', ['schema' => $schema]) . '?table=' . $data['table_key'],
        ]);
    }

    // #4:POST /scaffold/db/designer/schemas — 新建 schema
    public function createSchema(Request $req): JsonResponse
    {
        // plan-40 §五 F4:schema PascalCase 跟 routes.php where regex 一致
        $data = $req->validate([
            'schema' => 'required|string|regex:/^[A-Z][A-Za-z0-9]*$/|max:64',
            'name'   => 'required|string|max:100',
            'desc'   => 'nullable|string|max:500',
        ]);
        try {
            $this->loader->createSchema($data['schema'], $data['name'], $data['desc'] ?? '');
        } catch (SchemaLoadException $e) {
            return $this->error('CREATE_SCHEMA_FAILED', $e->getMessage(), 422);
        }

        return $this->ok([
            'schema'       => $data['schema'],
            'redirect_url' => route('db.designer.show', ['schema' => $data['schema']]),
        ]);
    }

    // DELETE /scaffold/db/designer/schemas/{schema} — 删 schema(只草稿态,锁定态拒绝)
    public function deleteSchema(Request $req, string $schema): JsonResponse
    {
        $data = $req->validate([
            'confirm_key' => 'required|string',
        ]);
        if ($data['confirm_key'] !== $schema) {
            return $this->error('CONFIRM_MISMATCH', '确认输入的 schema 名跟当前不一致，删除取消', 422);
        }
        try {
            $this->loader->deleteSchema($schema);
            $this->refreshSchemaCache($schema);
        } catch (SchemaLoadException $e) {
            return $this->error('DELETE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok([
            'redirect_url' => route('db.designer.index'),
        ]);
    }

    // PUT /scaffold/db/designer/schemas/{schema} — 改名 schema(只草稿态)
    public function renameSchema(Request $req, string $schema): JsonResponse
    {
        $data = $req->validate([
            'new_name' => 'required|string|regex:/^[A-Z][A-Za-z0-9]*$/|max:64',
        ]);
        try {
            $this->loader->renameSchema($schema, $data['new_name']);
            $this->refreshSchemaCache($data['new_name']);
        } catch (SchemaLoadException $e) {
            return $this->error('RENAME_FAILED', $e->getMessage(), 422);
        }

        return $this->ok([
            'schema'       => $data['new_name'],
            'redirect_url' => route('db.designer.show', ['schema' => $data['new_name']]),
        ]);
    }

    // ─── PUT /scaffold/db/designer/{schema}/tables/{table}/rename ──────
    // 表 key 改名。loader rename yaml 节点 + refreshSchemaCache 重建缓存;
    // controller / acl 命名不源于表 key,不受影响。若已生成 Model,其 $table 下次 moo:model 重生成对齐。
    // 2026-07-04 闭环(ship 清单 #10):已生成 migration 的表不再拒绝 —— 接力写 Schema::rename
    // migration + captureTables 迁 snapshot baseline(旧 key 移出 / 新 key 吸入,防 diff 误判成删表+建表)。
    public function renameTable(Request $req, string $schema, string $table): JsonResponse
    {
        $data = $req->validate([
            'new_key' => 'required|string|regex:/^[a-z][a-z0-9_]*$/|max:64',
        ]);

        // 改名前先记录旧表是否已有 migration(改完 yaml 后旧 key 查不到了)
        $hadMigration = (bool) ($this->loader->loadModuleTables($schema)[$table]['locked'] ?? false);

        try {
            $this->loader->renameTable($schema, $table, $data['new_key']);
        } catch (SchemaLoadException $e) {
            return $this->error('RENAME_FAILED', $e->getMessage(), 422);
        }

        // 接力闭环:失败不影响 yaml 改名本身,log + note 提示手动兜底(同 deleteTable 模式)
        $migrationFile = null;
        $note          = '';
        if ($hadMigration) {
            try {
                $migrationFile = $this->writer->writeRename($schema, $table, $data['new_key']);
                // 迁 baseline:旧 key 在 current yaml 已不存在 → 从 snapshot 移除;新 key 吸入
                $this->snapshot->captureTables($schema, [$table, $data['new_key']]);
                $note = "已生成 rename migration:{$migrationFile}。跑 `php artisan migrate` 真改 DB 表名。";
            } catch (Throwable $e) {
                Log::warning(
                    "renameTable auto-migration failed for {$schema}.{$table} → {$data['new_key']}: {$e->getMessage()}"
                );
                $note = '⚠ rename migration 自动生成失败 —— 请手写 Schema::rename 迁移并同步 snapshot,否则下次 diff 会把改名当删表+建表。';
            }
        }
        $this->refreshSchemaCache($schema);

        return $this->ok([
            'table'          => $data['new_key'],
            'redirect_url'   => route('db.designer.show', ['schema' => $schema, 'table' => $data['new_key']]),
            'migration_file' => $migrationFile,
            'note'           => $note,
        ]);
    }

    // v6.2 round 7:DELETE /scaffold/db/designer/{schema}/tables/{table}
    // 2026-05-22:删 yaml 节点后自动跑 diff+write 流程生成 drop migration(等同 moo:migration),
    // 跟 MigrationWriter::ship captureTables 联动自动清 snapshot 里的此表。user 跑 migrate 真删 DB。
    public function deleteTable(Request $req, string $schema, string $table): JsonResponse
    {
        $data = $req->validate([
            'confirm_key' => 'required|string',
        ]);
        if ($data['confirm_key'] !== $table) {
            return $this->error('CONFIRM_MISMATCH', '确认输入的表 key 跟当前不一致，删除取消', 422);
        }
        try {
            $this->loader->deleteTable($schema, $table);
        } catch (SchemaLoadException $e) {
            return $this->error('DELETE_FAILED', $e->getMessage(), 422);
        }

        // 接力生成 drop migration + 联动清 snapshot(MigrationWriter::ship 内部 captureTables)。
        // 失败不影响 yaml 删除本身(yaml 已删),log warning + UI note 提示 user 手动跑。
        $migrationFiles = [];
        $migrationNote  = '';
        try {
            $migDiff = $this->diff->diff($schema);
            if (empty($migDiff['suspected_renames'])) {
                $result         = $this->writer->write($migDiff);
                $migrationFiles = $result['files_written'] ?? [];
            } else {
                // schema 内有未确认的疑似改名 → 不能安全 write(会把改名当 drop+add)。
                // 之前这里静默跳过、note 还说"snapshot 已同步",误导用户(2026-06-09 修)。
                $migrationNote = '⚠ schema 内有未确认的疑似改名，未自动生成 drop migration —— 请先到 designer 确认改名，再手动跑 `php artisan moo:migration ' . $schema . '`';
            }
        } catch (EmptyDiffException $e) {
            // 无变更(snapshot 跟 yaml 同步过):正常 path
        } catch (Throwable $e) {
            Log::warning(
                "deleteTable auto-migration failed for {$schema}.{$table}: {$e->getMessage()}"
            );
            $migrationNote = '⚠ 自动生成 drop migration 失败 — 手动跑 `php artisan moo:migration ' . $schema . '`';
        }

        $note = '已删 yaml 节点。物理 DB 表 ' . $table . ' 仍存在。';
        if ($migrationFiles) {
            $note .= '已生成 ' . count($migrationFiles) . ' 个 migration 文件：' . implode(', ', $migrationFiles) . '。跑 `php artisan migrate` 真删 DB 表。';
        } elseif ($migrationNote) {
            $note .= $migrationNote;
        } else {
            $note .= '无新增 migration（snapshot 已同步）。';
        }

        return $this->ok([
            'redirect_url'    => route('db.designer.show', ['schema' => $schema]),
            'note'            => $note,
            'migration_files' => $migrationFiles,
        ]);
    }

    // ─── helpers ──────────────────────────────────────────────────────
    private function ok(array $data): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => $data]);
    }

    private function error(string $code, string $msg, int $http, array $detail = []): JsonResponse
    {
        return response()->json([
            'ok'    => false,
            'error' => ['code' => $code, 'msg' => $msg, 'detail' => $detail],
        ], $http);
    }

    private function summarizeTable(array $tableDiff): string
    {
        $counts = ['add' => 0, 'drop' => 0, 'modify' => 0, 'rename' => 0];
        foreach ($tableDiff['field_changes'] as $ch) {
            $counts[$ch['op']] = ($counts[$ch['op']] ?? 0) + 1;
        }
        $idxCount = count($tableDiff['index_changes'] ?? []);
        $parts    = [];
        if ($counts['add']) {
            $parts[] = "+{$counts['add']} 字段";
        }
        if ($counts['modify']) {
            $parts[] = "~{$counts['modify']} 修改";
        }
        if ($counts['rename']) {
            $parts[] = "R {$counts['rename']} 改名";
        }
        if ($counts['drop']) {
            $parts[] = "-{$counts['drop']} 删除";
        }
        if ($idxCount) {
            $parts[] = "{$idxCount} 索引变更";
        }
        // 新表 created 时,字段列表故意跳过 system field(SchemaDiffService::createdTableDiff L496),
        // 但 migration 文件会用 Laravel helper 自动生成 id/softDeletes/timestamps;
        // summary 加缀提示防 user 误以为 migration 不完整(2026-05-20 反馈)
        if (($tableDiff['status'] ?? null) === 'created') {
            $current       = $tableDiff['current_definition'] ?? [];
            $hasId         = isset($current['fields']['id']);
            $hasSoftDelete = isset($current['fields']['deleted_at']);
            $hasTimestamps = isset($current['fields']['created_at'], $current['fields']['updated_at']);
            $framework     = [];
            if ($hasId) {
                $framework[] = 'id 主键';
            }
            if ($hasSoftDelete) {
                $framework[] = 'softDeletes';
            }
            if ($hasTimestamps) {
                $framework[] = 'timestamps';
            }
            if ($framework !== []) {
                $parts[] = '另含 ' . implode(' / ', $framework) . '（Laravel 自动生成）';
            }
        }

        return $parts !== [] ? implode(', ', $parts) : '无字段变更';
    }
}
