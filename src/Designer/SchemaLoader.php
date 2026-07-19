<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Designer;

use Illuminate\Support\Facades\DB;
use Mooeen\Scaffold\Support\PackageRegistry;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Load / normalize / write-back schema YAML files.
 *
 * 内部表示形态（normalize 后）:
 *   [
 *     'module' => ['name' => ..., 'folder' => ..., 'desc' => ...],
 *     'tables' => [
 *        '<table_key>' => [
 *           'name','desc','locked','model','controller',
 *           'fields' => ['<field>' => [...], ...],
 *           'index'  => ['<name>' => ['type','fields'], ...],
 *           'enums'  => [...],
 *           'attrs'  => [...],
 *        ],
 *     ],
 *     'warnings' => [...],
 *     'raw' => <full original yaml array>,
 *   ]
 */
class SchemaLoader
{
    // plan-51:新增 db_unique 显式语义("DB 层强约束" 跟 "app 层 soft-aware" 区分)
    private const FIELD_LEGAL_KEYS = ['name', 'type', 'size', 'min_size', 'required', 'unique', 'db_unique', 'default', 'unsigned', 'desc', 'comment', 'index', 'precision', 'format'];

    /** @var array<string, array> module cache, key = schema basename */
    private array $cache = [];

    /** Round 2 P2 cache:listModules 单 request 多次复用(index 页 + side-tree 都会调) */
    private ?array $listModulesCache = null;

    /** @var array<string,string>|null schema => 出身包 key(host schema 不在内);listSchemaFiles 时重建 */
    private ?array $originMap = null;

    public function __construct(
        private readonly Utility $utility,
    ) {}

    // ---------------------------------------------------------------
    // Public API (deliverables list 1.1 - 1.7)
    // ---------------------------------------------------------------

    /**
     * @return array<string, array{folder:string,name:string,tables_count:int,fields_count:int,last_migration:?string,locked:bool,desc:?string}>
     */
    public function listModules(): array
    {
        // Round 2 P2:同 request 内复用(每次循环里 latestMigrationFor 已 batch cache,
        // 但外部 callsite 多次 listModules() 仍有 yaml parse 开销)
        if ($this->listModulesCache !== null) {
            return $this->listModulesCache;
        }
        $out = [];
        foreach ($this->listSchemaFiles() as $schema => $path) {
            try {
                $data = $this->loadRaw($schema);
            } catch (\Throwable) {
                continue;
            }
            $tables     = $data['tables'] ?? [];
            $fieldCount = 0;
            $locked     = false;
            $lastMig    = null;
            foreach ($tables as $tableKey => $tableRaw) {
                $fieldCount += count($tableRaw['fields'] ?? []);
                $mig = $this->latestMigrationFor((string) $tableKey, $schema);
                if ($mig !== null) {
                    $locked = true;
                    if ($lastMig === null || $mig > $lastMig) {
                        $lastMig = $mig;
                    }
                }
            }
            $out[$schema] = [
                'folder'         => $data['module']['folder'] ?? $schema,
                'name'           => $data['module']['name']   ?? $schema,
                'tables_count'   => count($tables),
                'fields_count'   => $fieldCount,
                'last_migration' => $lastMig,
                'locked'         => $locked,
                'desc'           => $data['module']['desc'] ?? null,
                // plan-53 出身:null = host,否则扩展包 key(UI 分块 + 详情高亮的依据)
                'origin' => $this->originOf($schema),
            ];
        }
        $this->listModulesCache = $out;

        return $out;
    }

    /**
     * @return array{name:string,folder:string,desc:?string}
     */
    public function loadModule(string $schema): array
    {
        $data = $this->loadNormalized($schema);

        return [
            'name'   => $data['module']['name']   ?? $schema,
            'folder' => $data['module']['folder'] ?? $schema,
            'desc'   => $data['module']['desc']   ?? null,
        ];
    }

    /**
     * @return array<string, array{key:string,name:string,locked:bool,fields:int}>
     */
    public function loadModuleTables(string $schema): array
    {
        $data = $this->loadNormalized($schema);
        $out  = [];
        foreach ($data['tables'] as $key => $t) {
            $out[$key] = [
                'key'    => $key,
                'name'   => $t['name'],
                'locked' => $t['locked'],
                'fields' => count($t['fields']),
            ];
        }

        return $out;
    }

    /**
     * @return array{key:string,name:string,desc:?string,locked:bool,fields:array,index:array,enums:array}
     */
    public function loadTableFull(string $schema, string $tableKey): array
    {
        $data = $this->loadNormalized($schema);
        if (! isset($data['tables'][$tableKey])) {
            throw new SchemaLoadException("table '{$tableKey}' not found in schema '{$schema}'");
        }
        $t = $data['tables'][$tableKey];

        $fields = [];
        foreach ($t['fields'] as $name => $attr) {
            $fields[] = $this->shapeField($name, $attr, $t['locked']);
        }

        // 反向映射:把表级 index 块里的单字段索引落到对应字段的 index 列
        // 多字段索引(如 {type: index, fields: [a, b]})不映射,只在独立"索引"卡里展示
        // plan-51:type:unique 映射为 'unique-db'(明确 DB 强约束语义,跟新 dropdown 选项对齐)
        foreach ($t['index'] as $idxName => $idx) {
            $idxType   = $idx['type']   ?? null;
            $idxFields = $idx['fields'] ?? null;
            $fieldKey  = null;
            if (is_string($idxFields)) {
                $fieldKey = $idxFields;
            } elseif (is_array($idxFields) && count($idxFields) === 1) {
                $fieldKey = (string) reset($idxFields);
            }
            if ($fieldKey === null || $idxType === null) {
                continue;
            }
            $clientIdxType = $idxType === 'unique' ? 'unique-db' : $idxType;
            foreach ($fields as $i => $f) {
                if ($f['key'] === $fieldKey) {
                    $fields[$i]['index'] = $clientIdxType;
                    break;
                }
            }
        }

        // plan-51:attr.unique=true(app-level)且没在 index 块 → 派生 'unique-app'
        // (legacy 同时有 attr.unique + index 块 type:unique 的场景已由上方 loop 设为 'unique-db',
        //  优先 db-level 让 GUI 跟现状对齐;用户可手动改为 app-app 显式表达 app-level)
        foreach ($t['fields'] as $name => $attr) {
            if (! is_array($attr) || empty($attr['unique'])) {
                continue;
            }
            foreach ($fields as $i => $f) {
                if ($f['key'] === $name && ($f['index'] ?? 'none') === 'none') {
                    $fields[$i]['index'] = 'unique-app';
                    break;
                }
            }
        }

        // CSP-friendly:index 反向映射完成后,补 index_disabled(view 模板里要属性访问而不是 method call)
        foreach ($fields as $i => $f) {
            $fields[$i]['index_disabled'] = $f['row_readonly'] || $f['index'] === 'primary';
        }

        $enums = [];
        foreach ($t['enums'] as $field => $rows) {
            $shaped = [];
            foreach ($rows as $key => $row) {
                $keyStr = (string) $key;
                // 2026-05-21:sentinel __pending_<n> → designer UI 显示空 key,等 AI 翻译填
                if (str_starts_with($keyStr, '__pending_')) {
                    $keyStr = '';
                }
                $shaped[] = [
                    'key'      => $keyStr,
                    'value'    => is_array($row) ? ($row[0] ?? '') : $row,
                    'label_en' => is_array($row) ? ($row[1] ?? '') : '',
                    'label_zh' => is_array($row) ? ($row[2] ?? '') : '',
                ];
            }
            $enums[$field] = $shaped;
        }

        return [
            'key'        => $tableKey,
            'name'       => $t['name'],
            'desc'       => $t['desc'],
            'locked'     => $t['locked'],
            'model'      => $t['model'],
            'controller' => $t['controller'],
            'fields'     => $fields,
            'index'      => $t['index'],
            'enums'      => $enums,
            'remark'     => $t['attrs']['remark']     ?? null,
            'prefix'     => $t['attrs']['prefix']     ?? '',      // F29 字段前缀持久化到 yaml.attrs.prefix
            'created_by' => $t['attrs']['created_by'] ?? null,
            'created_at' => $t['attrs']['created_at'] ?? null,
            'updated_by' => $t['attrs']['updated_by'] ?? null,
            'updated_at' => $t['attrs']['updated_at'] ?? null,
        ];
    }

    /**
     * @return array{modules:int,tables:int,fields:int,migrations:int}
     */
    public function loadStats(): array
    {
        $modules = $this->listModules();
        $tables  = 0;
        $fields  = 0;
        foreach ($modules as $m) {
            $tables += $m['tables_count'];
            $fields += $m['fields_count'];
        }

        // 2026-05-30:模型数 — models.php 按模块分组,汇总各模块模型数(缓存缺失则 0,不炸首屏)
        $models = 0;
        try {
            foreach ($this->utility->getModels() as $moduleModels) {
                $models += is_array($moduleModels) ? count($moduleModels) : 0;
            }
        } catch (\Throwable) {
            $models = 0;
        }

        return [
            'modules'    => count($modules),
            'tables'     => $tables,
            'fields'     => $fields,
            'models'     => $models,
            'migrations' => $this->countMigrations(),
        ];
    }

    /**
     * Write back YAML. Simple Yaml::dump for MVP — won't preserve original
     * formatting / comments precisely.
     *
     * @param array $data Same shape as loadRaw() returns (top-level: module, tables).
     */
    /**
     * Partial-merge save: client 传的 {module, tables} 只覆盖明确的字段,
     * 保留 yaml 原 model/controller/index/enums + 字段中文 name 等 client 不识别的 attrs。
     *
     * 已知 trade-off(同意上线):Symfony Yaml::dump 不保留注释 / inline-flow / quote 风格,
     * 第一次 save 后 yaml 格式会被 normalize(数据语义不丢)。
     */
    /**
     * 创建新表(MVP):写一个 minimal yaml 节点 {attrs:{name,desc}, fields:{id:{}}}。
     * 后续 app/class/index 等用户在 designer 改字段时 saveModule 会逐步加,或手编 yaml。
     *
     * @throws SchemaLoadException
     */
    public function createTable(string $schema, string $tableKey, string $name, string $desc = '', string $prefix = '', ?string $author = null): void
    {
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $tableKey)) {
            throw new SchemaLoadException("table key 必须 snake_case（小写字母开头，字母数字下划线）：{$tableKey}");
        }
        if ($name === '') {
            throw new SchemaLoadException('表显示名必填');
        }
        $this->assertOriginWritable($schema);
        $path = $this->yamlPath($schema);
        if (! file_exists($path)) {
            throw new SchemaLoadException("schema not found: {$schema}");
        }
        $originalText = (string) file_get_contents($path);
        try {
            $raw = Yaml::parse($originalText) ?: [];
        } catch (\Throwable $e) {
            throw new SchemaLoadException("YAML parse failed: {$e->getMessage()}");
        }
        $raw['tables'] = (array) ($raw['tables'] ?? []);
        if (isset($raw['tables'][$tableKey])) {
            throw new SchemaLoadException("table key 已存在：{$tableKey}");
        }
        // plan 19 v8 D4:新建表时可选携带 prefix(F29 字段前缀,持久化到 attrs.prefix)
        // 审计 metadata(schema 元数据,不是行字段):新建表 stamp created_*,updated_* 留空 — 首次 saveModule 才补
        $node = [
            'attrs' => array_filter([
                'name'       => $name,
                'desc'       => $desc   !== '' ? $desc : null,
                'prefix'     => $prefix !== '' ? $prefix : null,
                'created_by' => $author !== null && $author !== '' ? $author : null,
                'created_at' => $author !== null && $author !== '' ? date('Y-m-d H:i:s') : null,
            ], static fn ($v) => $v !== null),
            // 默认字段:
            //   - id / timestamps:系统字段,空 {} 走 normalize 派生(bigint unsigned / timestamp)
            //   - creator_id / updater_id:通用审计字段(非框架自动维护,controller 在 store/update 时 auth()->id())
            //     显式 yaml attrs,user 可改可删;位置 id 之后、deleted_at 之前
            // 用户不要 soft delete / 审计字段时在 yaml 删对应 row 即可
            'fields' => [
                'id'         => [],
                'creator_id' => ['name' => '创建人ID', 'type' => 'bigint', 'unsigned' => true],
                'updater_id' => ['name' => '更新人ID', 'type' => 'bigint', 'unsigned' => true],
                'deleted_at' => [],
                'created_at' => [],
                'updated_at' => [],
            ],
        ];
        $raw['tables'][$tableKey] = $node;
        $yaml                     = YamlFormatter::dumpPreservingComments($raw, $originalText);
        // plan-40 §三 R-1:LOCK_EX 防 multi-tab 同 schema save 互覆
        if (file_put_contents($path, $yaml, LOCK_EX) === false) {
            throw new SchemaLoadException("write failed: {$path}");
        }
        unset($this->cache[$schema]);
        $this->listModulesCache = null;     // Round 2 P2:invalidate listModules cache(写改了字段数 / last_migration)
    }

    // #4:新建 schema(.yaml 文件),写最小 stub 节点
    public function createSchema(string $schemaName, string $displayName, string $desc = ''): void
    {
        if (! preg_match('/^[A-Z][A-Za-z0-9]*$/', $schemaName)) {
            throw new SchemaLoadException('schema 名必须 PascalCase（大写字母开头，字母数字）：' . $schemaName);
        }
        if ($displayName === '') {
            throw new SchemaLoadException('显示名必填');
        }
        $path = $this->yamlPath($schemaName);
        if (file_exists($path)) {
            throw new SchemaLoadException("schema 已存在：{$schemaName}");
        }
        // 最小 stub:仅 module 块 + 空 tables(后续在 designer 新建表)
        $headerComment = "###\n# {$schemaName}\n#\n# @date   " . date('Y-m-d H:i:s') . "\n##\n";
        $module        = [
            'name'   => $displayName,
            'folder' => $schemaName,
        ];
        if ($desc !== '') {
            $module['desc'] = $desc;
        }
        $body = YamlFormatter::dump([
            'module' => $module,
            'tables' => [],
        ]);
        if (file_put_contents($path, $headerComment . $body, LOCK_EX) === false) {
            throw new SchemaLoadException("write failed: {$path}");
        }
        $this->listModulesCache = null;     // Round 2 P2:新 schema 出现,invalidate
        $this->originMap        = null;     // plan-53:出身表同步失效
    }

    // 草稿态判断 — schema 任何表都没生成 migration = 草稿(可改名 / 删)
    public function isSchemaDraft(string $schema): bool
    {
        foreach ($this->loadModuleTables($schema) as $t) {
            if (! empty($t['locked'])) {
                return false;
            }
        }

        return true;
    }

    // 删 schema(只草稿态;删 yaml 文件 + cache invalidate)
    // 锁定态拒绝 — 下游 controller / model / API / migration 都挂旧名,删 yaml 等于半残;让 user 走 git 流程
    public function deleteSchema(string $schema): void
    {
        $this->assertOriginWritable($schema);
        $path = $this->yamlPath($schema);
        if (! file_exists($path)) {
            throw new SchemaLoadException("schema not found: {$schema}");
        }
        if (! $this->isSchemaDraft($schema)) {
            throw new SchemaLoadException("schema 已生成 migration，不能删：{$schema}（请走 git 流程）");
        }
        if (! @unlink($path)) {
            throw new SchemaLoadException("delete failed: {$path}");
        }
        unset($this->cache[$schema]);
        $this->listModulesCache = null;
        $this->originMap        = null;     // plan-53:出身表同步失效
    }

    // 改名 schema(只草稿态;rename yaml 文件 + 更新 yaml 内 module.folder + cache invalidate)
    public function renameSchema(string $oldName, string $newName): void
    {
        if (! preg_match('/^[A-Z][A-Za-z0-9]*$/', $newName)) {
            throw new SchemaLoadException('新 schema 名必须 PascalCase（大写字母开头，字母数字）：' . $newName);
        }
        if ($oldName === $newName) {
            return;
        } // no-op
        $this->assertOriginWritable($oldName);
        // 跨源全局重名闸(2026-07-03 复盘审查 #1):新名在任何源(host / 各包)已存在都拒 ——
        // 只查同目录会让 rename 制造出跨源重名,下一次 listSchemaFiles fail-fast 把整个设计器打死
        if (isset($this->listSchemaFiles()[$newName])) {
            throw new SchemaLoadException("schema 已存在：{$newName}（schema 名跨源全局唯一）");
        }
        $oldPath = $this->yamlPath($oldName);
        // 新名跟旧名同目录(保出身):yamlPath(新名) 会因"未知名"回落 host,包 schema 改名会被搬进 host — 必须用旧文件所在目录拼
        $newPath = dirname($oldPath) . '/' . $newName . '.yaml';
        if (! file_exists($oldPath)) {
            throw new SchemaLoadException("schema not found: {$oldName}");
        }
        if (file_exists($newPath)) {
            throw new SchemaLoadException("schema 已存在：{$newName}");
        }
        if (! $this->isSchemaDraft($oldName)) {
            throw new SchemaLoadException("schema 已生成 migration，不能改名：{$oldName}（请走 git 流程）");
        }
        // 读 + 改 module.folder(跟 schema 名同步)+ 写新 path,成功后再删旧
        $originalText = (string) file_get_contents($oldPath);
        try {
            $raw = Yaml::parse($originalText) ?: [];
        } catch (\Throwable $e) {
            throw new SchemaLoadException("YAML parse failed: {$e->getMessage()}");
        }
        $raw['module']['folder'] = $newName;
        $yaml                    = YamlFormatter::dumpPreservingComments($raw, $originalText);
        if (file_put_contents($newPath, $yaml, LOCK_EX) === false) {
            throw new SchemaLoadException("write failed: {$newPath}");
        }
        if (! @unlink($oldPath)) {
            @unlink($newPath); // rollback
            throw new SchemaLoadException("rename failed (cannot remove old): {$oldPath}");
        }
        unset($this->cache[$oldName]);
        $this->listModulesCache = null;
        $this->originMap        = null;     // plan-53:出身表同步失效
    }

    // v6.2 round 7:删表(只删 yaml 节点;物理表 drop 走正常 migration 流程,user 手动跑)
    public function deleteTable(string $schema, string $tableKey): void
    {
        $this->assertOriginWritable($schema);
        $path = $this->yamlPath($schema);
        if (! file_exists($path)) {
            throw new SchemaLoadException("schema not found: {$schema}");
        }
        $originalText = (string) file_get_contents($path);
        try {
            $raw = Yaml::parse($originalText) ?: [];
        } catch (\Throwable $e) {
            throw new SchemaLoadException("YAML parse failed: {$e->getMessage()}");
        }
        if (! isset($raw['tables'][$tableKey])) {
            throw new SchemaLoadException("table not found: {$tableKey}");
        }
        unset($raw['tables'][$tableKey]);
        $yaml = YamlFormatter::dumpPreservingComments($raw, $originalText);
        // plan-40 §三 R-1:LOCK_EX 防 multi-tab 同 schema save 互覆
        if (file_put_contents($path, $yaml, LOCK_EX) === false) {
            throw new SchemaLoadException("write failed: {$path}");
        }
        unset($this->cache[$schema]);
        $this->listModulesCache = null;     // Round 2 P2:invalidate listModules cache(写改了字段数 / last_migration)
    }

    /**
     * 表 key 改名:rename yaml `tables.<old>` 节点 → `<new>`(保序,非合并新增),
     * 不动 controller / acl(命名不源于表 key)。cache 由 controller 接力 moo:fresh 重建。
     *
     * 2026-07-04:去掉「已生成 migration 拒绝改名」的锁 —— 与 ship 清单 #10「单步操作闭环 codegen
     * 副作用」对齐:已有 migration 的表改名由 DesignerController 接力生成 Schema::rename migration
     * + captureTables 迁 snapshot baseline(否则 diff 会把改名误判成删表+建表)。本方法只管 yaml。
     */
    public function renameTable(string $schema, string $oldKey, string $newKey): void
    {
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $newKey)) {
            throw new SchemaLoadException('新表 key 必须 snake_case（小写字母开头，小写字母 / 数字 / 下划线）：' . $newKey);
        }
        if ($oldKey === $newKey) {
            return;
        }
        $this->assertOriginWritable($schema);
        $path = $this->yamlPath($schema);
        if (! file_exists($path)) {
            throw new SchemaLoadException("schema not found: {$schema}");
        }
        $originalText = (string) file_get_contents($path);
        try {
            $raw = Yaml::parse($originalText) ?: [];
        } catch (\Throwable $e) {
            throw new SchemaLoadException("YAML parse failed: {$e->getMessage()}");
        }
        if (! isset($raw['tables'][$oldKey])) {
            throw new SchemaLoadException("table not found: {$oldKey}");
        }
        if (isset($raw['tables'][$newKey])) {
            throw new SchemaLoadException("表 key 已存在：{$newKey}");
        }
        // 原位换 key(保序):遍历重建,oldKey 处替成 newKey,其余原样
        $newTables = [];
        foreach ($raw['tables'] as $k => $v) {
            $newTables[(string) $k === $oldKey ? $newKey : (string) $k] = $v;
        }
        $raw['tables'] = $newTables;
        $yaml          = YamlFormatter::dumpPreservingComments($raw, $originalText);
        if (file_put_contents($path, $yaml, LOCK_EX) === false) {
            throw new SchemaLoadException("write failed: {$path}");
        }
        unset($this->cache[$schema]);
        $this->listModulesCache = null;
    }

    // v6.3 #3:plan §4 C-2 saveModule 从 200 行平铺拆成 5 个 sub-method:
    //   applyModuleBlock / applyTableAttrs / applyRenameHints / rebuildFieldRows /
    //   rebuildTableIndex / applyEnums。saveModule 自身降为 30 行 orchestration。
    //   行为零变化(只搬代码,$writable / $allowNew / allowed 列表均原样保留)。
    public function saveModule(string $schema, array $client, ?string $author = null): void
    {
        $this->assertOriginWritable($schema);
        $path = $this->yamlPath($schema);
        if (! file_exists($path)) {
            throw new SchemaLoadException("schema not found: {$schema}");
        }
        $originalText = (string) file_get_contents($path);
        try {
            $raw = Yaml::parse($originalText) ?: [];
        } catch (\Throwable $e) {
            throw new SchemaLoadException("YAML parse failed: {$e->getMessage()}");
        }

        $this->applyModuleBlock($raw, $client);

        foreach (($client['tables'] ?? []) as $tableKey => $cTable) {
            $yamlTable          = (array) ($raw['tables'][$tableKey] ?? []);
            $yamlTable['attrs'] = (array) ($yamlTable['attrs'] ?? []);

            // 审计 metadata:before snapshot 用于 stamp updated_* 前判定真改动(无改动 round-trip save 不刷)
            $beforeSnapshot = $this->changeSnapshot($yamlTable);

            $yamlTable = $this->applyTableAttrs($yamlTable, $cTable);
            // plan 19 v11:Model / Controller / Resource 配置可编辑
            $yamlTable = $this->applyTableModel($yamlTable, $cTable);
            $yamlTable = $this->applyTableController($yamlTable, $cTable);

            if (array_key_exists('fields', $cTable) && is_array($cTable['fields'])) {
                $yamlFields = (array) ($yamlTable['fields'] ?? []);
                $this->applyRenameHints($yamlFields, $yamlTable, (array) ($cTable['rename_hints'] ?? []));
                $yamlTable['fields'] = $this->rebuildFieldRows($yamlFields, $cTable['fields']);

                $newIndex = $this->rebuildTableIndex(
                    (array) ($yamlTable['index'] ?? []),
                    $cTable['fields'],
                    $cTable
                );
                if (empty($newIndex)) {
                    unset($yamlTable['index']);
                } else {
                    $yamlTable['index'] = $newIndex;
                }
            }

            $yamlTable = $this->applyEnums($yamlTable, $cTable['enums'] ?? null);

            // 真改动才 stamp updated_*。author 为空时(CLI / test 路径)跳过 stamp,不污染 yaml
            if ($author !== null && $author !== '' && $beforeSnapshot !== $this->changeSnapshot($yamlTable)) {
                $now = date('Y-m-d H:i:s');
                // 老 yaml 还没回填 created_* 的 case:首次 save 时一并补 created_*
                if (empty($yamlTable['attrs']['created_by'])) {
                    $yamlTable['attrs']['created_by'] = $author;
                    $yamlTable['attrs']['created_at'] = $now;
                }
                $yamlTable['attrs']['updated_by'] = $author;
                $yamlTable['attrs']['updated_at'] = $now;
            }

            $raw['tables'][$tableKey] = $yamlTable;
        }

        $yaml = YamlFormatter::dumpPreservingComments($raw, $originalText);
        // plan-40 §三 R-1:LOCK_EX 防 multi-tab 同 schema save 互覆
        if (file_put_contents($path, $yaml, LOCK_EX) === false) {
            throw new SchemaLoadException("write failed: {$path}");
        }
        unset($this->cache[$schema]);
        $this->listModulesCache = null;     // Round 2 P2:invalidate listModules cache(写改了字段数 / last_migration)
    }

    /** module 块:仅当 client 传了非空内容才 merge */
    private function applyModuleBlock(array &$raw, array $client): void
    {
        if (is_array($client['module'] ?? null) && ! empty($client['module'])) {
            $raw['module'] = array_merge((array) ($raw['module'] ?? []), $client['module']);
        }
    }

    /**
     * 表语义快照 — 剔除 stamp 字段自身,用于 saveModule 判断"真改动"(避免 round-trip save 刷 updated_*)。
     * 包含 attrs(扣 audit)/ model / controller / fields / index / enums 全部业务语义块。
     */
    private function changeSnapshot(array $yamlTable): array
    {
        $attrs = $yamlTable['attrs'] ?? [];
        unset($attrs['created_by'], $attrs['created_at'], $attrs['updated_by'], $attrs['updated_at']);

        return [
            'attrs'      => $attrs,
            'model'      => $yamlTable['model']      ?? null,
            'controller' => $yamlTable['controller'] ?? null,
            'fields'     => $yamlTable['fields']     ?? null,
            'index'      => $yamlTable['index']      ?? null,
            'enums'      => $yamlTable['enums']      ?? null,
        ];
    }

    /** 表 attrs:覆盖 name / desc / prefix(F29 字段前缀持久化) */
    private function applyTableAttrs(array $yamlTable, array $cTable): array
    {
        if (array_key_exists('name', $cTable)) {
            $yamlTable['attrs']['name'] = $cTable['name'];
        }
        foreach (['desc', 'prefix'] as $attr) {
            if (! array_key_exists($attr, $cTable)) {
                continue;
            }
            $val = $cTable[$attr];
            if ($val === null || $val === '') {
                unset($yamlTable['attrs'][$attr]);
            } else {
                $yamlTable['attrs'][$attr] = $val;
            }
        }

        return $yamlTable;
    }

    /**
     * plan 19 v11:写 yaml.model.class(若 client 传)。class 为空 → 整个 model 节点删除。
     */
    private function applyTableModel(array $yamlTable, array $cTable): array
    {
        if (! array_key_exists('model', $cTable) || ! is_array($cTable['model'])) {
            return $yamlTable;
        }
        $class = trim((string) ($cTable['model']['class'] ?? ''));
        // plan-37 后审 P1:class 清空时只删 class 这一个 key,保留 app/resource/factory 等子配置,
        // 避免「编辑 model 配置 modal 把 class 清空」这种操作 silently 把整节点 unset 导致数据丢失。
        $existing = (array) ($yamlTable['model'] ?? []);
        if ($class === '') {
            unset($existing['class']);
            $yamlTable['model'] = $existing;
            if ($yamlTable['model'] === []) {
                unset($yamlTable['model']);
            }
        } else {
            $existing['class']  = $class;
            $yamlTable['model'] = $existing;
        }

        return $yamlTable;
    }

    /**
     * plan 19 v11:写 yaml.controller.{class, app, resource}(若 client 传)。
     * - class 为空 → 整个 controller 节点删除
     * - app 数组(必填,空数组也保留)
     * - resource 数组(可选,空数组 → 删除 resource key)
     *
     * plan-37 后审 P1:class 清空时只删 class 这一个 key,保留 app/resource 等子配置,
     * 不再静默 unset 整个 controller 节点(数据丢失风险)。
     */
    private function applyTableController(array $yamlTable, array $cTable): array
    {
        if (! array_key_exists('controller', $cTable) || ! is_array($cTable['controller'])) {
            return $yamlTable;
        }
        $cCtrl    = $cTable['controller'];
        $class    = trim((string) ($cCtrl['class'] ?? ''));
        $existing = (array) ($yamlTable['controller'] ?? []);
        // 2026-05-20 用户反馈:user 选了「生成到:后台管理/接口」+「Resource 到」toggle 后 save,
        // 但 controller class 暂时为空 → 之前路径整段 unset,刷新后 toggle 状态丢失。
        // 修法:class 不再门控 app/resource — user 可以先选 app/resource(意图持久化),
        // 之后填 class 时直接生成。仅 class+app+resource 都空时整个 unset。
        if ($class === '') {
            unset($existing['class']);
        } else {
            // 2026-05-21 归一化:controller class 必带 Controller 后缀,跟 generator 端一致 ——
            // 收口到 Utility::ensureControllerSuffix 单一真源。
            // designer GUI 用户漏写后缀(只填 "Memo")会让 routes 引用 MemoController 但文件名 Memo.php → 类找不到 → 接口调试 sidebar 缺该模块。
            $existing['class'] = Utility::ensureControllerSuffix($class);
        }
        if (array_key_exists('app', $cCtrl) && is_array($cCtrl['app'])) {
            $appList = array_values(array_filter(
                array_map('strval', $cCtrl['app']),
                static fn ($s) => $s !== '',
            ));
            if ($appList === []) {
                unset($existing['app']);
            } else {
                $existing['app'] = $appList;
            }
        }
        if (array_key_exists('resource', $cCtrl)) {
            $resource = is_array($cCtrl['resource']) ? array_values(array_filter(
                array_map('strval', $cCtrl['resource']),
                static fn ($s) => $s !== '',
            )) : [];
            if ($resource === []) {
                unset($existing['resource']);
            } else {
                $existing['resource'] = $resource;
            }
        }
        // 整段 controller 都空才 unset
        if ($existing === []) {
            unset($yamlTable['controller']);

            return $yamlTable;
        }
        $yamlTable['controller'] = $existing;

        return $yamlTable;
    }

    /**
     * client.confirmRename 把 {oldKey: newKey} 塞进 rename_hints。
     * 必须先把 yamlFields[oldKey] 搬到 newKey(继承 type / required 等 attrs),
     * 否则后续 attr 合并 newKey 查不到 row=[],原 attrs 丢光。
     * 同时 table.index 块里引用 oldKey 的 entry 同步改 newKey + idx name(若 name==oldKey)。
     */
    private function applyRenameHints(array &$yamlFields, array &$yamlTable, array $renameHints): void
    {
        foreach ($renameHints as $oldKey => $newKey) {
            $oldKey = (string) $oldKey;
            $newKey = (string) $newKey;
            if ($oldKey === '' || $newKey === '' || $oldKey === $newKey) {
                continue;
            }
            // plan-38 P0-SEC-4 / plan-40 §二:rename_hints 后端零校验是 PHP/SQL 注入入口,
            // newKey 必须 ^[a-z_][a-z0-9_]*$ + 长度 <= 64,否则静默跳过(防恶意 session 污染)
            // 首 `_` 放行:Laravel-NestedSet `_lft / _rgt` 工业惯例字段名
            if (! preg_match('/^[a-z_][a-z0-9_]*$/', $newKey) || strlen($newKey) > 64) {
                continue;
            }
            // 字段真改名了才动:目标名已存在(撞名)或源名不存在 → 整条 hint 跳过。
            // 原先字段改名守在 if 里、索引重写却无条件执行 → 撞名时字段保留旧名但索引被指到新名
            // (一个已存在的别的字段)→ 索引落在错字段上(2026-06-10 修,防 client/DevTools 绕过)。
            $renamed = isset($yamlFields[$oldKey]) && ! isset($yamlFields[$newKey]);
            if (! $renamed) {
                continue;
            }
            $yamlFields[$newKey] = $yamlFields[$oldKey];
            unset($yamlFields[$oldKey]);

            $yamlIndex = (array) ($yamlTable['index'] ?? []);
            if ($yamlIndex === []) {
                continue;
            }
            $rewritten = [];
            foreach ($yamlIndex as $idxName => $entry) {
                $f = is_array($entry) ? ($entry['fields'] ?? null) : null;
                if ($f === $oldKey) {
                    // 单字段索引(string 形式)
                    $entry['fields']       = $newKey;
                    $nameAfter             = ($idxName === $oldKey) ? $newKey : $idxName;
                    $rewritten[$nameAfter] = $entry;
                } elseif (is_array($f) && in_array($oldKey, $f, true)) {
                    // 数组形式(单或多字段复合索引)—— 原来只处理 count===1,多字段复合索引会保留旧字段名
                    // → 改名后 yaml 索引引用不存在的列、破坏下次 diff/migration(2026-06-09 修)。
                    $mapped                = array_map(static fn ($c) => $c === $oldKey ? $newKey : $c, $f);
                    $entry['fields']       = count($mapped) === 1 ? reset($mapped) : array_values($mapped);
                    $nameAfter             = ($idxName === $oldKey) ? $newKey : $idxName;
                    $rewritten[$nameAfter] = $entry;
                } else {
                    $rewritten[$idxName] = $entry;
                }
            }
            $yamlTable['index'] = $rewritten;
        }
    }

    /**
     * 字段:client.fields 决定字段集 + 顺序,每个 field 保留 yaml 原 row,
     * 只覆盖 client 显式传的 attrs;client 传 null/'' 视为 unset。
     *
     * $writable = $allowNew(currently identical):index 走表级 table.index 块回写(F12);
     *   type 必须允许新增(F22),否则新字段无 type;
     *   required 允许新增(F26):client 用 dirty-tracking 只在 user 改过 nullable 时才发,
     *   所以这里加 required 不会被 shape 派生值污染 yaml。
     */
    private function rebuildFieldRows(array $yamlFields, array $clientFields): array
    {
        $writable  = ['type', 'size', 'required', 'default', 'comment', 'unsigned', 'precision', 'format'];
        $newFields = [];
        foreach ($clientFields as $cField) {
            $key = $cField['name'] ?? null;
            if (! $key) {
                continue;
            }
            $keyStr = (string) $key;
            // plan-38 P0-SEC-1 / plan-40 §二:防字段名注入入口 — client.fields[].name
            // 必须 ^[a-z][a-z0-9_]*$ + 长度 <= 64,否则丢弃(后端兜底,防 DevTools 绕过 GUI 校验)。
            // 系统字段 id / created_at / updated_at / deleted_at:client 改动忽略,但要分两种:
            //   ① yaml 原本有 → 保留 yaml entry(plan-40 P1 Round 2 bugfix:之前 continue 跳过
            //      会让 saveModule 整行覆盖丢失 system field — platform_visitor_logs 2026-05-20 翻车)
            //   ② yaml 原本没,但 client 给了(user 在 GUI 加字段如 deleted_at)→ 写空 entry,
            //      normalize 阶段会派生 _system + type:timestamp(避免 user 加完 silent skip,
            //      yaml 不变 + 下次 load 又消失 — 2026-05-22 platform_regions 翻车)
            // client 给的位置序保留(rebuild 按 client.fields 遍历顺序)。
            if (in_array($keyStr, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                $newFields[$keyStr] = array_key_exists($keyStr, $yamlFields)
                    ? (array) $yamlFields[$keyStr]
                    : [];

                continue;
            }
            // 首 `_` 放行:Laravel-NestedSet `_lft / _rgt` 工业惯例字段名,
            // 严过滤会 silent drop 整字段(2026-05-22 platform_regions round-trip 翻车根因)
            if (! preg_match('/^[a-z_][a-z0-9_]*$/', $keyStr) || strlen($keyStr) > 64) {
                continue;
            }
            $row           = (array) ($yamlFields[$keyStr] ?? []);
            $effectiveType = $cField['type'] ?? ($row['type'] ?? null);

            foreach ($writable as $attr) {
                if (! array_key_exists($attr, $cField)) {
                    continue;
                }
                $val     = $cField[$attr];
                $yamlHas = array_key_exists($attr, $row);

                // 2026-05-23 P0 bug 修:client `_buildFieldEntry` 用 `f[attr] ?? null` 永远发 null
                // (无 dirty tracking),后端无法区分"未改"vs"清空" → 必须把 `null` 当"未改"保留
                // yaml 原值,只把 `''` 当"显式清空"unset。否则 user 改 A 字段会牵动 B 字段
                // 已有的 default: null / unsigned: false 等被 unset(P0 plan_priority bug 根因 #1)。
                if ($val === null) {
                    continue;     // "未改"信号 — yaml 原值原样保留
                }
                if (! $yamlHas && ($val === '' || $val === '__CLEAR__')) {
                    continue;
                }

                // 2026-05-23 P0 bug 修(round 2):Laravel 全局 ConvertEmptyStringsToNull 中间件把
                // POST body 内 `default: ""` 转成 null,跟 "user 没改" client `?? null` 信号撞 — 后端
                // 无法分辨。Client 端用 `__CLEAR__` sentinel 显式表达"清空",绕过中间件转换。
                if ($val === '__CLEAR__' || $val === '') {
                    unset($row[$attr]);
                } elseif ($attr === 'size' && is_string($val) && str_contains($val, ',')) {
                    // 2026-05-23 P0 bug 修(round 4):client 发完整 'min,max' 串(GUI 显示双值后)
                    // — 直接保留,不要 coerce 成 int 也不要再拼接 origMin(会变 '6,6,200' bug)
                    $row[$attr] = $val;
                } elseif ($attr === 'size' && $yamlHas
                                           && is_string($row[$attr]) && str_contains($row[$attr], ',')
                                           && in_array((string) $effectiveType, ['varchar', 'char'], true)) {
                    // 2026-05-23 P0 bug 修(round 1):varchar/char size '6,192' 紧凑语法 — yaml 原是
                    // string 含 ',',client 只发 max int(legacy GUI 没暴露 min input),overwrite 会丢
                    // min。保留 yaml 原 min,跟 client.max 合成新 'min,max' 串(向后兼容)。
                    // 2026-05-23 round 5 audit:此分支仅对 varchar/char 适用('min,max' 语义)。
                    // decimal/float/double 的 size 紧凑串 '10,2' 是 '{M},{D}'='{size},{precision}'
                    // 完全不同语义,误套这逻辑会把 D 当 min 保留,user 改 size 时 yaml 写出错位置。
                    // decimal 类型不进此分支,落入下面 else → coerce 成 int,yaml size 紧凑串首次
                    // save 后归一为 size: <int> + 独立 precision: <int>(split 形式,跟仓内主流 yaml 一致)
                    $origMin    = trim(explode(',', $row[$attr], 2)[0]);
                    $row[$attr] = $origMin . ',' . $val;
                } else {
                    // DOM input.value 永远是 string,这里按 effectiveType 反 cast 回 numeric/bool
                    $row[$attr] = $this->coerceFieldValue($attr, $val, $effectiveType);
                }
            }
            // plan-51:client.index 三种 unique 派生 —
            //   - 'unique-app' → row.unique=true(app-level + soft-aware,不进 index 块)
            //   - 'unique-db'  → row.unique 不写(让 index 块单源 type:unique,经 promoteInlineUnique
            //                    在下次 load 时若 yaml 有 attr.db_unique 也会被吸进 index 块)
            //   - 'unique'     → legacy DB-level(等价 'unique-db')
            // 其它 'index' / 'primary' / 'none' → row.unique strip
            //
            // 2026-05-23 P0 bug 修(round 3 历史):"client 切 index 列 unique → index 后刷新又变 unique"
            // 根因是 attr.unique sugar 没同步删 — 这里继续守护
            $clientIdx = $cField['index'] ?? null;
            if ($clientIdx === 'unique-app') {
                $row['unique'] = true;
            } elseif (array_key_exists('unique', $row)) {
                // 不是 app-level → strip sugar(避免历史 attr.unique=true + 新 GUI 选了 db / 别的 → 双源歧义)
                unset($row['unique']);
            }
            // db_unique sugar 暂不写盘 — 让 index 块单源(verbose `type: unique`)表达;
            // 用户若手写 yaml `db_unique: true`,promoteInlineUnique 会在 load 时派生进 index 块

            // display_name → yaml.<key>.name(client 的 name 字段被字段 key 占用,所以用 display_name 传中文)
            if (array_key_exists('display_name', $cField)) {
                $val = $cField['display_name'];
                if (array_key_exists('name', $row)) {
                    if ($val === null || $val === '') {
                        unset($row['name']);
                    } else {
                        $row['name'] = $val;
                    }
                } elseif ($val !== null && $val !== '') {
                    $row['name'] = $val;
                }
            }
            // 2026-05-23 P0 round 5(user 指出 codegen 规则):
            // FreshStorageGenerator:227 `$attr['unsigned'] ?? true` — int/bigint/tinyint/decimal/float
            // 类型 yaml 没写 unsigned **默认 true**。这意味着 yaml 里的 `unsigned: true` 是冗余(跟
            // 默认一致),`unsigned: false` 才是 user 显式 signed 的唯一表达。
            //
            // 但 createTable 系统字段 creator_id/updater_id 历史就带 `unsigned: true`(line 278-279),
            // 已 ship 的项目 yaml 里也都有 — strip 会破坏 idempotent save(round-trip 把 yaml 改了
            // → 触发 updated_* stamp,即使语义没变)。
            //
            // 取舍:保留两种 value 写盘(let coerceFieldValue 处理),坚守:
            //   - client 发 false → yaml 写 `unsigned: false`(显式 signed,user 切换不会丢)
            //   - client 发 true  → yaml 写 `unsigned: true`(idempotent,跟历史 yaml 兼容)
            //   - client 不发(_unsigned_dirty=false → val=null)→ yaml 原值保留
            // R-14 兜底 non-numeric 类型上的 unsigned(无论 true/false 都 strip)。

            // plan-40 §三 R-14:save 端兜底,non-numeric 类型上的 unsigned 拒绝写盘
            // (loadNormalized 也兜底 strip,这里在 save 链路再卡一道,防 DevTools 绕过 GUI disabled)
            // 用 array_key_exists 而非 !empty:false 值也要 strip(varchar 上 unsigned: false 同样
            // 是非法组合 + 噪声)
            if (array_key_exists('unsigned', $row)) {
                $numericTypes = FieldTypes::NUMERIC;
                if (! in_array((string) $effectiveType, $numericTypes, true)) {
                    unset($row['unsigned']);
                }
            }
            // Round 2 真机 Test 3/5 polish:仅对**新字段**(yaml 原本没有)的 row 应用 canonical 顺序,
            // 已存在字段保留 yaml 原 attr 顺序(plan-36 yaml 是 source of truth — user 手写顺序须尊重)。
            // 真机 Test 10 暴露 regression:之前 unconditional sortRowAttrs 把 Order.yaml 全 yaml 重排,
            // user 改一个字段触发全表 diff 噪声。
            $isNewRow           = ! array_key_exists($keyStr, $yamlFields);
            $newFields[$keyStr] = $isNewRow ? $this->sortRowAttrs($row) : $row;
        }

        return $newFields;
    }

    /**
     * Round 2 真机 Test polish:按 scaffold yaml 习惯重排 row attr 顺序,
     * 让 git diff 干净(避免 `{name, type}` vs `{type, name}` 这种顺序噪声)。
     * 顺序参考本仓多张 yaml 的人工习惯,unknown attr 保留在末尾。
     */
    private function sortRowAttrs(array $row): array
    {
        static $canonicalOrder = [
            'required', 'name', 'type', 'size', 'min_size', 'precision',
            'unsigned', 'default', 'format', 'unique', 'comment', 'desc',
        ];
        $out = [];
        foreach ($canonicalOrder as $key) {
            if (array_key_exists($key, $row)) {
                $out[$key] = $row[$key];
                unset($row[$key]);
            }
        }
        // 未识别的 attr 保留原序追加在末尾
        foreach ($row as $k => $v) {
            $out[$k] = $v;
        }

        return $out;
    }

    /**
     * 重建 table.index 块(plan 19 v4 §index-roundtrip,plan 19 v7 B3 修保 yaml 原序)。
     * 策略:single 部分按 yaml 原 idx-name 出现顺序输出(client 仍要的),
     *       client 新增的 single index 按 client.fields 顺序追加在末尾;
     *       multi 部分由 client.multi_indexes 单源驱动(F30),或 fallback yaml 原 multi。
     *
     * v7 B3:之前 single 部分按 clientFields 顺序重建,会把 yaml 里手工排过的 index 顺序覆盖掉
     *        (任何 saveModule 触发都重排);此版改成保 yaml 原序,只增/删/改 type。
     */
    private function rebuildTableIndex(array $yamlIndex, array $clientFields, array $cTable): array
    {
        // plan-51:client 端 dropdown 用 'unique-app' / 'unique-db' 区分语义;
        // index 块只关心 DB 层(unique-db / 旧 'unique' / 'index' / 'primary')。
        // 在 client 字段标签翻译为 yaml-native(canonical)前,先做归一化:
        //   - 'unique-app' → 不进 index 块(由 row.unique sugar 表达,见 rebuildFieldRows 上方修法)
        //   - 'unique-db' → 'unique'(canonical DB 强约束)
        //   - 'unique'(legacy)→ 'unique'(canonical,backward compat)
        $allowedTypes  = ['primary', 'unique', 'index'];
        $singleByField = [];     // fieldKey => ['name' => idxName, 'entry' => yaml entry]
        $singleOrder   = [];      // [fieldKey, ...] yaml 原出现顺序
        $multiKept     = [];      // idxName => entry(多字段索引,GUI 不动)
        foreach ($yamlIndex as $idxName => $entry) {
            $f           = is_array($entry) ? ($entry['fields'] ?? null) : null;
            $singleField = null;
            if (is_string($f)) {
                $singleField = $f;
            } elseif (is_array($f) && count($f) === 1) {
                $singleField = (string) reset($f);
            }
            if ($singleField !== null) {
                $singleByField[$singleField] = ['name' => (string) $idxName, 'entry' => (array) $entry];
                $singleOrder[]               = $singleField;
            } else {
                $multiKept[$idxName] = $entry;
            }
        }

        // client 当前需要 single index 的字段
        $clientNeedIdx = [];     // fieldKey => clientType(canonical)
        foreach ($clientFields as $cField) {
            $key = $cField['name'] ?? null;
            if (! $key) {
                continue;
            }
            $clientType = $cField['index'] ?? null;
            // plan-51 translate:client GUI 字面值 → yaml canonical
            $clientType = match ($clientType) {
                'unique-db'  => 'unique',
                'unique-app' => null,        // app-level 不进 index 块,由 row.unique sugar 单源
                default      => $clientType,
            };
            if (! in_array($clientType, $allowedTypes, true)) {
                continue;
            }
            $clientNeedIdx[$key] = $clientType;
        }

        $newIndex = [];
        $emitted  = [];
        // ① 按 yaml 原序输出仍需要的 entry(仅调 type)
        foreach ($singleOrder as $fieldKey) {
            if (! isset($clientNeedIdx[$fieldKey])) {
                continue;
            }     // client 删了这个 index
            $entry                                       = $singleByField[$fieldKey]['entry'];
            $entry['type']                               = $clientNeedIdx[$fieldKey];
            $entry['fields']                             = $fieldKey;
            $newIndex[$singleByField[$fieldKey]['name']] = $entry;
            $emitted[$fieldKey]                          = true;
        }
        // ② client 新增的 single index(yaml 原没有的)按 client.fields 顺序追加
        foreach ($clientFields as $cField) {
            $key = $cField['name'] ?? null;
            if (! $key || isset($emitted[$key]) || ! isset($clientNeedIdx[$key])) {
                continue;
            }
            $newIndex[$key] = ['type' => $clientNeedIdx[$key], 'fields' => $key];
        }

        // F30 多字段索引:client.multi_indexes 单源驱动;没传则 fallback yaml 原 multi
        if (array_key_exists('multi_indexes', $cTable) && is_array($cTable['multi_indexes'])) {
            foreach ($cTable['multi_indexes'] as $mi) {
                $miName   = (string) ($mi['name'] ?? '');
                $miType   = (string) ($mi['type'] ?? '');
                $miFields = (array) ($mi['fields'] ?? []);
                if ($miName === '' || ! in_array($miType, $allowedTypes, true) || count($miFields) < 2) {
                    continue;
                }
                $newIndex[$miName] = ['type' => $miType, 'fields' => array_values($miFields)];
            }
        } else {
            foreach ($multiKept as $name => $entry) {
                $newIndex[$name] = $entry;
            }
        }

        return $newIndex;
    }

    /** F36 枚举:client.enums 单源(完全替换 yaml.<table>.enums)。 $cEnums=null 时跳过(老 client 兼容)
     *  2026-05-21:enum 翻译辅助 — 允许 key 为空的 pending entry 写盘(yaml map key 不能空,
     *  用 sentinel __pending_<n> 占位)。codegen 端(moo:model buildEnum)看到 sentinel 报错引导
     *  user 去 designer AI 翻译。reader loadTableFull 反解 sentinel → key='' designer UI 显示空。
     */
    private function applyEnums(array $yamlTable, ?array $cEnums): array
    {
        if (! is_array($cEnums)) {
            return $yamlTable;
        }
        $newEnums = [];
        foreach ($cEnums as $g) {
            $field = (string) ($g['field'] ?? '');
            if ($field === '') {
                continue;
            }
            $items = (array) ($g['items'] ?? []);
            if (empty($items)) {
                continue;
            }
            $entries    = [];
            $pendingIdx = 0;
            foreach ($items as $r) {
                $key = (string) ($r['key'] ?? '');
                // Round 2 P2 防下游 XSS:label_zh / label_en 收到 client 时 sanitize,
                // 禁 HTML 尖括号 + quote 字符 + 反斜杠 + control char,cap 64 长度。
                $labelEn = $this->sanitizeEnumLabel((string) ($r['label_en'] ?? ''));
                $labelZh = $this->sanitizeEnumLabel((string) ($r['label_zh'] ?? ''));
                // 空 key item:value/label 至少要有一个非空才保留(防 user 加一行什么都没填浪费占位)
                if ($key === '') {
                    $hasContent = ($r['value'] ?? '') !== '' || $labelEn !== '' || $labelZh !== '';
                    if (! $hasContent) {
                        continue;
                    }
                    $key = '__pending_' . $pendingIdx++;
                }
                // plan-40 §二 P1 防御纵深:enum value sanitize — int value 强 cast,string value
                // strip 控制字符 + quote/backslash 防写到 Enum case PHP 字面量时逃逸
                $rawVal = $r['value'] ?? '';
                $val    = is_string($rawVal) && ! ctype_digit(ltrim($rawVal, '-'))
                    ? $this->sanitizeEnumLabel($rawVal)
                    : $rawVal;
                // yaml 形态:`{ <key>: [value, label_en, label_zh] }`
                $entries[$key] = [
                    $val,
                    $labelEn,
                    $labelZh,
                ];
            }
            if (! empty($entries)) {
                $newEnums[$field] = $entries;
            }
        }
        if (empty($newEnums)) {
            unset($yamlTable['enums']);
        } else {
            $yamlTable['enums'] = $newEnums;
        }

        return $yamlTable;
    }

    /**
     * Load raw yaml file as string (for debug yaml-dump view).
     */
    public function loadRawText(string $schema): string
    {
        $path = $this->yamlPath($schema);
        if (! file_exists($path)) {
            return '';
        }

        return (string) file_get_contents($path);
    }

    /**
     * Dump only the current table's yaml subtree (for per-table debug view).
     * Format may differ from source (inline-flow/comments lost) — that's expected for debug.
     */
    public function loadRawTableText(string $schema, string $tableKey): string
    {
        $path = $this->yamlPath($schema);
        if (! file_exists($path) || $tableKey === '') {
            return '';
        }
        try {
            $raw = Yaml::parse(file_get_contents($path)) ?: [];
        } catch (\Throwable) {
            return '';
        }
        if (! isset($raw['tables'][$tableKey])) {
            return '';
        }

        // plan-49 后续:debug 显示也走 YamlFormatter,user 看到的格式跟写盘一致(canonical 顺序)
        return YamlFormatter::dump(['tables' => [$tableKey => $raw['tables'][$tableKey]]]);
    }

    /**
     * Public: load + normalize full schema (used by SchemaDiffService).
     */
    public function loadNormalized(string $schema): array
    {
        if (isset($this->cache[$schema])) {
            return $this->cache[$schema];
        }
        $raw = $this->loadRaw($schema);

        return $this->cache[$schema] = $this->normalize($raw, $schema);
    }

    /**
     * Parse raw YAML string (used by diff service for baseline). Doesn't cache.
     */
    public function loadFromString(string $yamlContent, string $schema = '__inline__'): array
    {
        try {
            $raw = Yaml::parse($yamlContent) ?: [];
        } catch (\Throwable $e) {
            throw new SchemaLoadException("YAML parse failed: {$e->getMessage()}");
        }

        return $this->normalize($raw, $schema);
    }

    // ---------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------

    private function loadRaw(string $schema): array
    {
        $path = $this->yamlPath($schema);
        if (! is_file($path)) {
            throw new SchemaLoadException("YAML file not found: {$path}");
        }
        try {
            return Yaml::parseFile($path) ?: [];
        } catch (\Throwable $e) {
            throw new SchemaLoadException("YAML parse failed for {$schema}: {$e->getMessage()}");
        }
    }

    /**
     * schema → yaml 绝对路径,按出身解析(plan-53):包 schema 落包目录,未知名(新建)落 host。
     */
    public function yamlPath(string $schema): string
    {
        $origin = $this->originOf($schema);
        $dir    = $origin === null
            ? rtrim($this->utility->getDatabasePath('schema'), '/')
            : rtrim($this->utility->targetContext($origin)->pathFor('database'), '/');

        return $dir . '/' . $schema . '.yaml';
    }

    /**
     * schema 的出身:null = host,否则扩展包 key(PackageRegistry 自动发现)。
     * 未知 schema(如尚未创建)按 host 处理。
     */
    public function originOf(string $schema): ?string
    {
        if ($this->originMap === null) {
            $this->listSchemaFiles();
        }

        return $this->originMap[$schema] ?? null;
    }

    /**
     * schema 的 migration 目录,按出身解析:host = database_path('migrations'),包 = {包根}/database/migrations。
     */
    public function migrationDirFor(string $schema): string
    {
        $origin = $this->originOf($schema);

        return $origin === null
            ? rtrim(database_path('migrations'), '/')
            : rtrim($this->utility->targetContext($origin)->pathFor('migration'), '/');
    }

    /**
     * 写权硬线(plan-53):包 schema 须软链装(写 vendor = 写真仓)才可写;vcs 拷贝拒绝一切变更。
     * public:MigrationWriter / Compacter / controller 写包内产物前同样要过这道闸。
     */
    public function assertOriginWritable(string $schema): void
    {
        $origin = $this->originOf($schema);
        if ($origin !== null && ! $this->utility->targetContext($origin)->writable) {
            throw new SchemaLoadException("扩展包 [{$origin}] 是 vendor 拷贝（非软链安装），schema 只读 —— 请在软链装该包的开发环境编辑。");
        }
    }

    /** in-memory cache:migration filename(无 .php)→ batch 号;migrations 表不存在时为 [] */
    private ?array $migrationBatchCache = null;

    /** in-memory cache:各 migration 目录下 *_table.php 的 [dir => [basename => mtime]](每目录一次 scandir,供 latestMigrationFor 复用;plan-53 起按出身分目录) */
    private array $migrationFilesCache = [];

    /**
     * 列指定表的 migration 历史（按文件名时间戳倒序）。
     * MVP:不解析 SQL 摘要,只读文件名 + mtime;状态走 migrations 表 batch 号;作者走文件头 @Author 行。
     *
     * @return array<int, array{date:string,file:string,summary:string,ran:bool,batch:?int,author:string}>
     */
    public function loadMigrationsFor(string $schema, string $tableKey): array
    {
        $dir = $this->migrationDirFor($schema);
        if (! is_dir($dir) || $tableKey === '') {
            return [];
        }
        $batchMap = $this->loadMigrationBatchMap();     // O(1) DB query/request,无表则 []

        // 2026-07-05:表改名后历史断链修复 —— 按血缘链(当前 key + 各前身 key)匹配,
        // 否则 rename 之前的 create/update 文件从历史面板消失(user 报的严重 bug)。
        $patterns = array_map(fn ($k) => '*_' . $k . '_table.php', $this->tableKeyLineage($schema, $tableKey));

        $out  = [];
        $seen = [];
        foreach ((new Finder)->files()->in($dir)->name($patterns)->depth(0) as $f) {
            $filename = $f->getFilename();
            if (isset($seen[$filename])) {
                continue;
            }
            $seen[$filename] = true;
            $nameNoExt       = pathinfo($filename, PATHINFO_FILENAME);
            $date            = '';
            if (preg_match('/^(\d{4})_(\d{2})_(\d{2})_(\d{2})(\d{2})(\d{2})_/', $filename, $m)) {
                $date = "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
            }
            $batch = $batchMap[$nameNoExt] ?? null;
            $out[] = [
                'date'    => $date,
                'file'    => $filename,
                'summary' => match (true) {
                    str_contains($filename, '_rename_') => 'rename table',
                    str_contains($filename, 'create_')  => 'create table',
                    default                             => 'update table',
                },
                'ran'    => $batch !== null,
                'batch'  => $batch,
                'author' => $this->extractMigrationAuthor((string) $f->getRealPath()),
            ];
        }
        usort($out, fn ($a, $b) => strcmp($b['date'], $a['date']));

        return $out;
    }

    /**
     * 表 key 血缘链:[当前 key, 前身 key, 前前身…]。零额外状态 —— rename migration 的文件名
     * (`..._rename_{old}_to_{new}_table.php`)本身就是血缘记录,逆着 to 端递归回溯。
     * seen 防环(a→b→a 这类来回改名不会死循环)。
     *
     * @return list<string>
     */
    public function tableKeyLineage(string $schema, string $tableKey): array
    {
        $names = array_keys($this->migrationFiles($this->migrationDirFor($schema)));
        $chain = [$tableKey];
        $seen  = [$tableKey => true];
        $cur   = $tableKey;
        while (true) {
            $prev = null;
            foreach ($names as $name) {
                // 贪婪 .+ 保证 old key 自含 `_to_` 时(a_to_b → c)也取到完整前身名
                if (preg_match('/_rename_(.+)_to_' . preg_quote($cur, '/') . '_table\.php$/', $name, $m)) {
                    $prev = $m[1];
                    break;
                }
            }
            if ($prev === null || isset($seen[$prev])) {
                break;
            }
            $chain[]     = $prev;
            $seen[$prev] = true;
            $cur         = $prev;
        }

        return $chain;
    }

    /**
     * 从 migration 文件头注释抽 `@Author:` 行(MigrationWriter 写入侧固定填的格式)。
     * 只读前 512 字节(头注释固定靠前);格式不对 / 读不到 → 空串,blade 侧不拼。
     * 全仓 migration 已经一次性 backfill 成统一格式(tools/backfill-migration-header.php),无需兼容 PHPDoc 旧风。
     */
    private function extractMigrationAuthor(string $absPath): string
    {
        $fp = @fopen($absPath, 'rb');
        if ($fp === false) {
            return '';
        }
        $head = (string) fread($fp, 512);
        fclose($fp);
        if (preg_match('/@Author:\s*(.+?)\s*[\r\n]/', $head, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    /**
     * 查 Laravel `migrations` 表,返回 [migration(无 .php) => batch 号]。
     * 表不存在 / 数据库不通 → 静默回 [],由调用方按"全部未执行"处理。
     *
     * @return array<string,int>
     */
    private function loadMigrationBatchMap(): array
    {
        if ($this->migrationBatchCache !== null) {
            return $this->migrationBatchCache;
        }
        try {
            $rows = DB::table('migrations')->pluck('batch', 'migration')->all();
            // pluck 返回 mixed,统一成 int
            $map = [];
            foreach ($rows as $name => $batch) {
                $map[(string) $name] = (int) $batch;
            }

            return $this->migrationBatchCache = $map;
        } catch (\Throwable $e) {
            return $this->migrationBatchCache = [];
        }
    }

    /**
     * 聚合 host + 各扩展包(PackageRegistry 自动发现)的 schema 文件(plan-53 出身模型)。
     * schema 名跨源全局唯一 —— 重名直接抛错(用户拥有全部仓的主导权,改名即可,不做消歧兜底)。
     *
     * @return array<string, string> [schema_basename => abs_path]
     */
    public function listSchemaFiles(): array
    {
        $out     = [];
        $origins = [];

        $scan = function (string $dir, ?string $origin) use (&$out, &$origins): void {
            if (! is_dir($dir)) {
                return;
            }
            foreach ((new Finder)->files()->in($dir)->name('*.yaml')->depth(0) as $f) {
                $name = pathinfo($f->getFilename(), PATHINFO_FILENAME);
                if (str_starts_with($name, '_')) {
                    continue;
                }     // _fields.yaml etc.
                if (isset($out[$name])) {
                    $prev = $origins[$name] ?? 'host';
                    throw new SchemaLoadException("schema 名跨源重名：[{$name}] 同时在 [{$prev}] 与 [" . ($origin ?? 'host') . '] —— schema 名全局唯一，请改名其一。');
                }
                $out[$name] = $f->getRealPath();
                if ($origin !== null) {
                    $origins[$name] = $origin;
                }
            }
        };

        $scan($this->utility->getDatabasePath('schema'), null);
        foreach (app(PackageRegistry::class)->all() as $key => $pkg) {
            $scan($pkg['base_path'] . 'scaffold/database', $key);
        }

        $this->originMap = $origins;
        ksort($out);

        return $out;
    }

    /**
     * 2026-05-21:全局采样 yaml 里 enum 条目对照,给 AI enum 翻译当 few-shot 样本。
     * 跟 collectNamingSamples 分开 — enum key 风格(status_open / type_image / gender_male)跟 field
     * key 风格(user_id / order_user_name)是不同维度的语料,fields 样本喂 enum 会拉错方向。
     * 跳过 sentinel __pending_*(本身就 pending),按 (field, key) tuple 去重,超过 cap 随机采。
     *
     * @return array<int, array{field:string,key:string,label_en:string,label_zh:string}>
     */
    public function collectEnumSamples(int $cap = 30): array
    {
        $samples = [];
        $seen    = [];
        foreach ($this->listSchemaFiles() as $schema => $_) {
            try {
                $data = $this->loadRaw($schema);
            } catch (\Throwable) {
                continue;
            }
            foreach (($data['tables'] ?? []) as $t) {
                foreach (($t['enums'] ?? []) as $field => $rows) {
                    if (! is_string($field) || $field === '' || ! is_array($rows)) {
                        continue;
                    }
                    foreach ($rows as $key => $row) {
                        $keyStr = (string) $key;
                        if ($keyStr === '' || str_starts_with($keyStr, '__pending_')) {
                            continue;
                        }
                        if (! preg_match('/^[a-z][a-z0-9_]*$/', $keyStr)) {
                            continue;
                        }     // 跳非 snake_case 历史脏数据
                        $tuple = $field . '|' . $keyStr;
                        if (isset($seen[$tuple])) {
                            continue;
                        }
                        $seen[$tuple] = true;
                        $labelEn      = is_array($row) ? (string) ($row[1] ?? '') : '';
                        $labelZh      = is_array($row) ? (string) ($row[2] ?? '') : '';
                        $samples[]    = [
                            'field'    => $field,
                            'key'      => $keyStr,
                            'label_en' => $labelEn,
                            'label_zh' => $labelZh,
                        ];
                    }
                }
            }
        }
        if (count($samples) > $cap) {
            shuffle($samples);
            $samples = array_slice($samples, 0, $cap);
        }

        return $samples;
    }

    /**
     * 全局采样 yaml 里 `{ snake_key: { name: 中文名 } }` 对照,给 AI 翻译当 few-shot 样本。
     * 跳过 id / *_at / parent_id 这些框架字段(没风格信号),按 key 去重,超过 cap 随机采。
     *
     * @return array<int, array{key:string,name:string}>
     */
    public function collectNamingSamples(int $cap = 30): array
    {
        $skip    = ['id', 'parent_id', 'created_at', 'updated_at', 'deleted_at'];
        $samples = [];
        foreach ($this->listSchemaFiles() as $schema => $_) {
            try {
                $data = $this->loadRaw($schema);
            } catch (\Throwable) {
                continue;
            }
            foreach (($data['tables'] ?? []) as $t) {
                foreach (($t['fields'] ?? []) as $fk => $attr) {
                    if (! is_string($fk) || in_array($fk, $skip, true)) {
                        continue;
                    }
                    if (! preg_match('/^[a-z][a-z0-9_]*$/', $fk)) {
                        continue;
                    }     // 跳非 snake_case 的历史脏数据,避免污染 AI
                    $name = is_array($attr) ? ($attr['name'] ?? null) : null;
                    if (! is_string($name) || $name === '') {
                        continue;
                    }
                    $samples[$fk] = $name;     // key 去重,同字段在多表只取一次
                }
            }
        }
        if (count($samples) > $cap) {
            $keys = array_keys($samples);
            shuffle($keys);
            $picked = [];
            foreach (array_slice($keys, 0, $cap) as $k) {
                $picked[$k] = $samples[$k];
            }
            $samples = $picked;
        }
        $out = [];
        foreach ($samples as $key => $name) {
            $out[] = ['key' => $key, 'name' => $name];
        }

        return $out;
    }

    /**
     * @return array{module:array,tables:array,warnings:array,raw:array}
     */
    private function normalize(array $raw, string $schema): array
    {
        $warnings = [];
        $tables   = [];

        foreach ($raw['tables'] ?? [] as $tableName => $tableRaw) {
            $tableRaw = (array) ($tableRaw ?: []);
            $attrs    = (array) ($tableRaw['attrs'] ?? []);
            $locked   = $this->latestMigrationFor((string) $tableName, $schema) !== null;

            $table = [
                'name'       => $attrs['name'] ?? (string) $tableName,
                'desc'       => $attrs['desc'] ?? null,
                'locked'     => $locked,
                'model'      => $tableRaw['model']      ?? null,
                'controller' => $tableRaw['controller'] ?? null,
                'attrs'      => $attrs,
                'fields'     => [],
                'index'      => (array) ($tableRaw['index'] ?? []),
                'enums'      => (array) ($tableRaw['enums'] ?? []),
            ];

            foreach ((array) ($tableRaw['fields'] ?? []) as $fieldName => $attrRaw) {
                $attrRaw      = (array) ($attrRaw ?? []);
                $fieldNameStr = (string) $fieldName;

                if (in_array($fieldNameStr, ['deleted_at', 'created_at', 'updated_at'], true)) {
                    $table['fields'][$fieldNameStr] = ['_system' => true, 'type' => 'timestamp', 'required' => false];

                    continue;
                }
                if ($fieldNameStr === 'id') {
                    $table['fields']['id'] = $this->normalizeIdField($attrRaw);

                    continue;
                }

                // plan-38 P0-SEC-1 / plan-40 §二 注入根因:字段名必须严校验 ^[a-z_][a-z0-9_]*$,
                // 后续 buildStub 把字段名拼进 PHP / SQL 模板,任何特殊字符都会导致 RCE / SQL 注入。
                // 拒绝非法名,落到 warnings,跳过此字段 — 不入 normalized output。
                // 首 `_` 放行:Laravel-NestedSet `_lft / _rgt` 工业惯例字段名
                if (! preg_match('/^[a-z_][a-z0-9_]*$/', $fieldNameStr)) {
                    $warnings[] = [
                        'table' => $tableName,
                        'field' => $fieldNameStr,
                        'msg'   => '字段名非法：必须 ^[a-z_][a-z0-9_]*$，本字段已跳过（防 PHP/SQL 注入）',
                    ];

                    continue;
                }
                if (strlen($fieldNameStr) > 64) {
                    $warnings[] = [
                        'table' => $tableName,
                        'field' => $fieldNameStr,
                        'msg'   => '字段名超 64 字符 MySQL identifier 上限，已跳过',
                    ];

                    continue;
                }

                $san      = $this->sanitizeFieldAttrs((string) $tableName, $fieldNameStr, $attrRaw);
                $warnings = array_merge($warnings, $san['warnings']);
                $cleaned  = $san['cleaned_attr'];

                $type  = $this->canonicalizeType((string) ($cleaned['type'] ?? 'varchar'));
                $sized = $this->parseSize($cleaned['size'] ?? null, $type);
                if (isset($sized['_warn'])) {
                    $warnings[] = ['table' => $tableName, 'field' => $fieldNameStr, 'msg' => $sized['_warn']];
                    unset($sized['_warn']);
                }

                // plan-40 §三 R-14:unsigned 仅 numeric 类型有意义(int 系列 + 浮点系列)。
                // GUI 已经 disabled(unsigned_disabled),但 DevTools 可绕 Alpine state。
                // 后端兜底:non-numeric 类型上的 unsigned 静默 strip + warn,防 yaml 出非法 + migrate 报错。
                $numericTypes = FieldTypes::NUMERIC;
                // 2026-05-23 P0 round 5 视觉 bug 根因:之前默认 false → 数字字段 GUI 显示 unsigned 未勾选,
                // 但 FreshStorageGenerator:225 给 int/bigint/tinyint/decimal/float yaml 没写 unsigned 派生
                // 默认 true(codegen 规则)。loadNormalized 是 GUI 数据源头 — 这里默认必须对齐 codegen,
                // 否则下游 shapeField 看到的就是错值,user 满屏看到"未勾选"但 migration 出来 unsigned。
                // 用 FreshStorageGenerator:225 的窄列表(int/bigint/tinyint/decimal/float)对齐 codegen 实际行为。
                $codegenDefaultUnsigned = FieldTypes::UNSIGNED_DEFAULT;
                $unsignedFlag           = array_key_exists('unsigned', $cleaned)
                    ? (bool) $cleaned['unsigned']
                    : in_array($type, $codegenDefaultUnsigned, true);
                if ($unsignedFlag && ! in_array($type, $numericTypes, true)) {
                    $warnings[] = [
                        'table' => $tableName,
                        'field' => $fieldNameStr,
                        'msg'   => "unsigned 仅对数值类型（int/decimal 等）有意义，{$type} 字段的 unsigned=true 已 strip",
                    ];
                    $unsignedFlag = false;
                    unset($cleaned['unsigned']);
                }

                $table['fields'][$fieldNameStr] = array_merge($cleaned, $sized, [
                    'name'     => $cleaned['name'] ?? null,
                    'type'     => $type,
                    'required' => array_key_exists('required', $cleaned) ? (bool) $cleaned['required'] : true,
                    'unsigned' => $unsignedFlag,
                    'default'  => $cleaned['default'] ?? null,
                ]);

                // plan-40 §六 enum-aware default 校验:int 类型 + default 是字符串 → 必须匹配 table.enums 里该字段的 key,
                // 否则 cast 会归 0,产生静默 bug(plan-40 §一 drift #1 的根因)
                $default  = $cleaned['default'] ?? null;
                $intTypes = FieldTypes::INT;
                if (is_string($default) && $default !== '' && in_array($type, $intTypes, true)) {
                    $tableEnums = (array) ($table['enums'][$fieldNameStr] ?? []);
                    if (! isset($tableEnums[$default])) {
                        $warnings[] = [
                            'table' => $tableName,
                            'field' => $fieldNameStr,
                            'msg'   => "default '{$default}' 是字符串但字段是 {$type}，且 enums 块无此 key — migration cast 后会归 0，请改成 int 或在 enums 中定义 '{$default}'",
                        ];
                    }
                }
            }

            $this->promoteInlineUnique($table);
            $tables[(string) $tableName] = $table;
        }

        return [
            'module'   => (array) ($raw['module'] ?? ['name' => $schema, 'folder' => $schema]),
            'tables'   => $tables,
            'warnings' => $warnings,
            'raw'      => $raw,
        ];
    }

    private function normalizeIdField(array $attrRaw): array
    {
        if (empty($attrRaw)) {
            return ['type' => 'bigint', 'size' => null, 'name' => 'ID', 'unsigned' => true, '_system' => 'id', 'required' => true];
        }
        $type = $this->canonicalizeType((string) ($attrRaw['type'] ?? 'bigint'));

        $normalized = [
            'type'     => $type,
            'size'     => $attrRaw['size'] ?? null,
            'name'     => $attrRaw['name'] ?? 'ID',
            'unsigned' => (bool) ($attrRaw['unsigned'] ?? ! in_array($type, ['varchar', 'char'], true)),
            'required' => true,
            '_system'  => 'id',
        ];

        if (array_key_exists('increment', $attrRaw) || array_key_exists('auto_increment', $attrRaw)) {
            $normalized['increment'] = (bool) ($attrRaw['increment'] ?? $attrRaw['auto_increment']);
        }

        return $normalized;
    }

    private function canonicalizeType(string $type): string
    {
        // Round 2 P2 yaml 类型别名兼容:Laravel migration API 用驼峰(bigInteger / longText / string ...),
        // scaffold 内部用 MySQL 类型词汇(bigint / longtext / varchar)。strtolower 已经处理纯 case 差异
        // (longText → longtext);这里额外把 Laravel-isms 映射到 scaffold canonical 上,让 user 写哪个都认。
        $low = strtolower($type);

        return match ($low) {
            'bool', 'boolean'                        => 'boolean',
            'string'                                 => 'varchar',
            'integer'                                => 'int',
            'biginteger', 'unsignedbiginteger'       => 'bigint',
            'smallinteger', 'unsignedsmallinteger'   => 'smallint',
            'mediuminteger', 'unsignedmediuminteger' => 'mediumint',
            'tinyinteger', 'unsignedtinyinteger'     => 'tinyint',
            'unsignedinteger'                        => 'int',
            default                                  => $low,
        };
    }

    private function parseSize(mixed $raw, string $type): array
    {
        // 只返实际派生出的字段,不预填 null —— 避免 array_merge 时覆盖 yaml 手写的同名字段(如 precision: 6)
        if ($raw === null || $raw === '') {
            return ['size' => null];
        }
        if (is_int($raw) || (is_string($raw) && ctype_digit($raw))) {
            return ['size' => (int) $raw];
        }
        if (is_string($raw) && str_contains($raw, ',')) {
            [$a, $b] = array_map('trim', explode(',', $raw, 2));
            if (! ctype_digit($a) || ! ctype_digit($b)) {
                return ['size' => null, '_warn' => "size 格式非法：{$raw}"];
            }
            $a = (int) $a;
            $b = (int) $b;

            return match (true) {
                in_array($type, ['varchar', 'char'], true) => ['size' => $b, 'min_size' => $a],
                in_array($type, FieldTypes::FLOAT, true)   => ['size' => $a, 'precision' => $b],
                default                                    => ['size' => $b, 'min_size' => $a, '_warn' => "type {$type} 不应有 'm,n' size"],
            };
        }

        return ['size' => null, '_warn' => 'size 类型未知'];
    }

    /**
     * plan-51 重写:区分 app-level unique vs DB-level unique
     *
     * - yaml `db_unique: true`   → 派生进 index 块 `type: unique` → migration emit `$table->unique()` 强约束。
     *                              若 index 块已有同字段 entry(type:index 或别的)→ **升级覆盖**为 type:unique。
     * - yaml `unique: true`(app)→ **不动 index 块** → migration 不 emit unique 约束 → Request 验证由
     *   FormRequest::getUnique() 自动加 whereNull('deleted_at') 软删过滤。
     * - 索引块 `type: unique`(verbose 写法)→ 自然走 DB-level 路径,跟 `db_unique: true` 等价。
     *
     * 兼容性:历史 yaml 同时有 attr.unique=true + index 块 type:unique(legacy 重复落地)→
     * **不**自动 clean,信任 yaml 现状。GUI 反向映射(loadTableFull)显示为 unique-db,优先
     * DB 显式;user 想改 app-level 在 GUI 选 unique-app 显式 save 即可。
     * 这样保证 plan-51 §五 5.3 "历史 yaml 一行不用改"的兼容承诺。
     */
    private function promoteInlineUnique(array &$table): void
    {
        $table['index'] ??= [];
        foreach ($table['fields'] as $fieldName => &$attr) {
            if (! is_array($attr)) {
                continue;
            }

            // DB-level:派生进 index 块,strip sugar
            if (! empty($attr['db_unique'])) {
                $existing = $table['index'][$fieldName] ?? null;
                if ($existing === null || ($existing['type'] ?? null) !== 'unique') {
                    $table['index'][$fieldName] = ['type' => 'unique', 'fields' => $fieldName];
                }
                unset($attr['db_unique'], $attr['unique']);
                // 同字段既有 attr.unique 又显式 db_unique → db_unique 胜出,清 unique sugar 避歧义

                continue;
            }

            // app-level:attr.unique 保留在 attr 中,**不**动 index 块
            // (Request generator 读 attr.unique 决定 getUnique() 是否 emit)
            // 老 yaml 若 index 块同字段已有 type:unique → 信任 yaml 现状不动,后续由
            // `php artisan moo:audit-unique-semantics` 工具引导用户显式选择
        }
        unset($attr);
    }

    private function sanitizeFieldAttrs(string $table, string $field, array $attr): array
    {
        $warnings = [];
        $cleaned  = [];
        foreach ($attr as $key => $value) {
            if (in_array($key, self::FIELD_LEGAL_KEYS, true)) {
                // plan-40 §二 P1 防御纵深:default / format 字段内容 sanitize
                // - default 字符串:strip 控制字符 + quote/backslash(防 cast 后进 PHP/yaml 字面量逃逸)
                // - format:`float:NN` 严格格式,不符 regex → warn + strip,防 `float:100');system('id');//` 注入
                if ($key === 'default' && is_string($value)) {
                    $value = $this->sanitizeEnumLabel($value);
                } elseif ($key === 'format' && is_string($value)) {
                    if ($value !== '' && ! preg_match('/^[a-z]+(?::[0-9,]+)?$/', $value)) {
                        $warnings[] = [
                            'table' => $table,
                            'field' => $field,
                            'msg'   => "format 值 `{$value}` 不符 `<word>` 或 `<word>:<digits>` 格式，已 strip",
                        ];

                        continue;
                    }
                }
                $cleaned[$key] = $value;

                continue;
            }
            $suggest    = $this->suggestKey((string) $key);
            $warnings[] = [
                'table'   => $table,
                'field'   => $field,
                'key'     => (string) $key,
                'msg'     => $suggest ? "未知属性 `{$key}`，是 `{$suggest}` 的笔误？" : "未知属性 `{$key}`（已忽略）",
                'suggest' => $suggest,
            ];
        }

        return ['cleaned_attr' => $cleaned, 'warnings' => $warnings];
    }

    private function suggestKey(string $unknown): ?string
    {
        $best     = null;
        $bestDist = PHP_INT_MAX;
        foreach (self::FIELD_LEGAL_KEYS as $legal) {
            $d = levenshtein($unknown, $legal);
            if ($d < $bestDist && $d <= 2) {
                $best     = $legal;
                $bestDist = $d;
            }
        }

        return $best;
    }

    /**
     * Coerce client-submitted field attr to the type that matches yaml conventions.
     *
     * GUI 上所有 input.value 都是 string('200' / '2' / '0' / 'true'),直接写 yaml 会被引号包起来,
     * 跟手写的 yaml(size: 192 / default: 0)不一致。这里按 effective type 反 cast 回原生类型。
     *
     * 只处理 size/default(其他 attr client 已是正确类型:type/index/comment 是 string,required 是 boolean)。
     */
    /**
     * Round 2 P2 防下游 XSS:enum label(label_en / label_zh)收 client 时 sanitize。
     * 策略:strip 控制字符 + HTML 尖括号 + quote + 反斜杠;cap 64(防写盘膨胀)。
     * 中文 / 标点 / 数字保留。label_en 还有 PascalCase 上游 regex,这里只是兜底。
     */
    private function sanitizeEnumLabel(string $val): string
    {
        if ($val === '') {
            return '';
        }
        // strip < > " ' \  和 ASCII 控制字符
        $clean = preg_replace('/[<>"\'\\\\\x00-\x1F\x7F]/u', '', $val) ?? '';
        $clean = trim($clean);
        if (mb_strlen($clean, 'UTF-8') > 64) {
            $clean = mb_substr($clean, 0, 64, 'UTF-8');
        }

        return $clean;
    }

    private function coerceFieldValue(string $attr, mixed $val, ?string $type): mixed
    {
        // precision 永远 int(decimal/double/float 的小数位数)
        if ($attr === 'precision') {
            if (is_int($val)) {
                return $val;
            }
            if (is_string($val) && ctype_digit($val)) {
                return (int) $val;
            }

            return $val;
        }
        if (! in_array($attr, ['size', 'default'], true)) {
            return $val;
        }
        if (! is_string($val)) {
            return $val;
        }     // 已是 int/bool/float,不动

        $intTypes   = FieldTypes::INT;
        $floatTypes = FieldTypes::FLOAT;
        $boolTypes  = ['bool', 'boolean'];

        if ($attr === 'size') {
            // decimal size 是 'm,n' 形态(precision,scale),保 string 由 normalizeSize 后续解析
            if ($type === 'decimal' && preg_match('/^\d+\s*,\s*\d+$/', $val)) {
                return $val;
            }
            // 其他 size 都是 int(varchar/char/int 系列的数值长度)
            if (ctype_digit($val)) {
                return (int) $val;
            }

            return $val;
        }

        // default:跟 type 联动
        if (in_array($type, $intTypes, true) && preg_match('/^-?\d+$/', $val)) {
            return (int) $val;
        }
        if (in_array($type, $floatTypes, true) && is_numeric($val)) {
            return (float) $val;
        }
        if (in_array($type, $boolTypes, true)) {
            $lower = strtolower($val);
            if (in_array($lower, ['true', '1'], true)) {
                return true;
            }
            if (in_array($lower, ['false', '0'], true)) {
                return false;
            }
        }

        return $val;
    }

    /**
     * Shape a single field row for UI consumption (deliverables: 'Field shape returned').
     */
    private function shapeField(string $name, array $attr, bool $tableLocked): array
    {
        // plan 19 §2.6:tableLocked 只锁表 key / 删表 / 模块 folder,字段编辑(包括加/改/改名)一律允许。
        $isSystem     = isset($attr['_system']);
        $rowReadonly  = $isSystem || ($name === 'id');
        $nameReadonly = $rowReadonly;  // 字段 key 改名走 rename 流程,system 行直接 readonly
        // 2026-05-23:行末删除按钮入口(替代 ⋯ popover 菜单);改名走 key 列行内 input → setFieldKey
        //   id 永远不可删;system timestamps 可删(user 主动砍掉时间戳/软删除字段时用)
        $isIdField = ($name === 'id');
        $canRemove = ! $isIdField;
        // v6 批次 B:系统字段 hint(tooltip),user hover key 列时提示"可删但不可改"
        if ($isIdField) {
            $rowTitle = 'ID 主键，固定不可改不可删';
        } elseif ($isSystem) {
            $rowTitle = match ($name) {
                'deleted_at' => '软删除时间戳。行内只读，但可整行删除（行末 × 按钮）',
                'created_at' => '自动维护的创建时间。行内只读，但可整行删除（行末 × 按钮）',
                'updated_at' => '自动维护的更新时间。行内只读，但可整行删除（行末 × 按钮）',
                default      => '系统字段，行内只读',
            };
        } else {
            $rowTitle = '';
        }
        $shape = [
            // __rowId:session 内 stable 标识(由 yaml field key 派生,初始化后不变);
            // 用户改 key 时 f.key 会变,而 __rowId 保持不变,做 :key 防 DOM 重 mount + closest('tr').data-rk 反查行用
            '__rowId'   => $name,
            'key'       => $name,
            '_orig_key' => $name,           // F39:yaml 原 key 锚点,setFieldKey 时跟 f.key 不同就塞 rename_hint
            'name'      => $attr['name'] ?? null,
            'type'      => $attr['type'] ?? null,
            // 2026-05-23 P0 bug 修(round 4):size 紧凑写法 'min,max' GUI 显示
            // - yaml load 经 parseSize 拆成 size + min_size 两字段(in-memory)
            // - GUI input 直接显示 size 只看到 max(192),user 看不到 min(6)
            // - 修法:有 min_size 时 shape 派生 size 合成 '{min},{max}' string,user 看到完整双值
            // - save 流 rebuildFieldRows 已支持 size 字符串带 ',' 保留(round 1 fix #3)
            'size' => isset($attr['min_size']) && $attr['min_size'] !== null
                            ? ($attr['min_size'] . ',' . ($attr['size'] ?? ''))
                            : ($attr['size'] ?? null),
            'min_size' => $attr['min_size'] ?? null,
            // precision:decimal(M,D) / float / double 的小数位数(D),仅这几种类型可编辑
            'precision'          => $attr['precision'] ?? null,
            'precision_disabled' => ! in_array($attr['type'] ?? '', FieldTypes::FLOAT, true),
            // format:跟 type 平行的自定义,如 'float:100' 让 model 自动整 ↔ 浮点 cast(见 CreateModelGenerator::getFloatAttribute)
            'format'   => $attr['format']  ?? null,
            'default'  => $attr['default'] ?? null,
            'required' => (bool) ($attr['required'] ?? true),
            'unique'   => (bool) ($attr['unique'] ?? false),
            // 2026-05-23 P0 round 5 视觉 bug:之前默认 false → bigint/int 字段 GUI 显示 unsigned 未勾选,
            // 但 FreshStorageGenerator:225 给 int/bigint/tinyint/decimal/float yaml 没写 unsigned 派生
            // 默认 true(codegen 规则)。UI 跟 codegen 反 — user 看到"未勾选"但 migration 出来是 unsigned,
            // 满屏诡异。修法:派生口径对齐 codegen 实际行为。
            'unsigned' => (bool) ($attr['unsigned'] ?? in_array($attr['type'] ?? '', FieldTypes::UNSIGNED_DEFAULT, true)),
            'nullable' => ! (bool) ($attr['required'] ?? true),
            // dirty-tracking 锚点(F26 nullable / F32 unsigned):client 设 dirty=true 后 _buildSavePayload 才发,
            // 避免 shape 派生值污染 yaml
            '_nullable_dirty' => false,
            '_unsigned_dirty' => false,
            // F32:unsigned 是否可编辑(只对 numeric 类型开放)
            'unsigned_disabled' => ! in_array($attr['type'] ?? '', FieldTypes::NUMERIC, true),
            'index'             => 'none',                       // resolved by loadTableFull from table-level index block
            'comment'           => $attr['comment'] ?? null,
            // CSP-friendly 预算 boolean / string(view 模板里只能属性访问,不能 method call)
            'row_readonly'  => $rowReadonly,
            'name_readonly' => $nameReadonly,
            'row_class'     => $rowReadonly ? 'is-readonly' : '',
            // 2026-05-21:字段 key 前缀 strip 按钮可见性 — client 在 init / setTablePrefix / setFieldKey
            // 后通过 _recomputeAllFieldsPrefixStrip 重算(此处给默认值,避免模板 undefined)
            //   不可 strip 行也渲按钮(visibility:hidden 占位)保所有行 key 列宽度齐
            'prefix_strippable'      => false,
            'prefix_strip_btn_class' => 'p-designer-fields__row-btn p-designer-fields__row-btn--strip is-placeholder',
            'prefix_strip_disabled'  => true,
            // 2026-05-21:DeepSeek 拼写检查结果(默认无 warning) — aiSpellCheckFields 后 client 写入
            'spell_warning'     => '',
            'spell_suggestion'  => '',
            'spell_reason'      => '',
            'spell_warn_class'  => 'p-designer-fields__spell-warn is-placeholder',
            'has_spell_warning' => false,
            // 行末删除按钮显隐:id 隐(can_remove=false),system timestamps 可删
            'can_remove' => $canRemove,
            // v6 批次 B:系统字段提示文字(空字符串 = 普通字段,无 tooltip)
            'row_title' => $rowTitle,
            // v6.2 round 4:size 列校验初始 class(varchar/char/decimal 必须有 size)
            'size_class' => $this->computeSizeClass($attr['type'] ?? null, $attr['size'] ?? null),
            'size_title' => $this->computeSizeClass($attr['type'] ?? null, $attr['size'] ?? null) === 'is-invalid'
                ? '此类型需要 size，请填（如 varchar 64）'
                : '',
            // #30:default 值跟 type 兼容性校验
            'default_class' => $this->computeDefaultClass($attr['type'] ?? null, $attr['default'] ?? null),
            'default_title' => $this->computeDefaultTitle($attr['type'] ?? null, $attr['default'] ?? null),
        ];

        return $shape;
    }

    /**
     * v6.2 round 4:size 必填校验(varchar/char/decimal 不能空)。
     * 返回 '' = OK,'is-invalid' = 红框警告。
     */
    private function computeSizeClass(?string $type, $size): string
    {
        $needsSize = in_array($type, ['varchar', 'char', 'decimal'], true);
        if (! $needsSize) {
            return '';
        }
        if ($size === null || $size === '' || $size === 0 || $size === '0') {
            return 'is-invalid';
        }

        return '';
    }

    /**
     * #30:default 值 vs type 兼容性校验。空值永远 OK(由 nullable 控制)。
     * 数字类型(int/bigint/.../decimal/float/double):default 必须是数字
     * bool:default 必须 0/1/true/false
     * 其余(varchar/text/timestamp/etc):宽松,啥都行
     */
    private function computeDefaultClass(?string $type, $default): string
    {
        if ($default === null || $default === '') {
            return '';
        }
        $numTypes = FieldTypes::NUMERIC;
        if (in_array($type, $numTypes, true)) {
            return is_numeric($default) ? '' : 'is-invalid';
        }
        if ($type === 'bool') {
            return in_array((string) $default, ['0', '1', 'true', 'false'], true) ? '' : 'is-invalid';
        }

        return '';
    }

    private function computeDefaultTitle(?string $type, $default): string
    {
        if ($this->computeDefaultClass($type, $default) !== 'is-invalid') {
            return '';
        }
        if ($type === 'bool') {
            return 'bool 默认值须 0/1/true/false';
        }

        return $type . ' 默认值须数字';
    }

    /**
     * 指定 migration 目录下所有 *_table.php 的 [basename => mtime],每目录一次 scandir 缓存。
     * listModules / normalize 会对每张表调 latestMigrationFor,之前每表一次 glob(N globs/请求);
     * 改成共享这份缓存内存过滤,降到每目录 1 次扫描(plan-53:host 与各包目录分开缓存)。
     */
    private function migrationFiles(string $dir): array
    {
        if (isset($this->migrationFilesCache[$dir])) {
            return $this->migrationFilesCache[$dir];
        }
        if (! is_dir($dir)) {
            return $this->migrationFilesCache[$dir] = [];
        }
        $map = [];
        foreach (glob($dir . '/*_table.php') ?: [] as $f) {
            $map[basename($f)] = filemtime($f) ?: 0;
        }

        return $this->migrationFilesCache[$dir] = $map;
    }

    private function latestMigrationFor(string $tableKey, string $schema): ?string
    {
        // str_ends_with('_<tableKey>_table.php') 与原 glob '*_<tableKey>_table.php' 字面等价(保留后缀匹配语义)。
        $suffix      = '_' . $tableKey . '_table.php';
        $latestMtime = 0;
        foreach ($this->migrationFiles($this->migrationDirFor($schema)) as $name => $mtime) {
            if (str_ends_with($name, $suffix) && $mtime > $latestMtime) {
                $latestMtime = $mtime;
            }
        }

        return $latestMtime > 0 ? date('Y-m-d H:i:s', $latestMtime) : null;
    }

    private function countMigrations(): int
    {
        // plan-37 后审 P1:base_path() 已指 engine/,拼 engine/database/migrations 会叠成
        // engine/engine/... 永远 0 → 用 database_path()。plan-53:host + 各包 migration 目录求和。
        $dirs = [rtrim(database_path('migrations'), '/')];
        foreach (app(PackageRegistry::class)->all() as $key => $pkg) {
            $dirs[] = rtrim($pkg['base_path'] . 'database/migrations', '/');
        }

        $count = 0;
        foreach ($dirs as $dir) {
            if (is_dir($dir)) {
                $count += count(glob($dir . '/*.php') ?: []);
            }
        }

        return $count;
    }
}
