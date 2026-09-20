<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Designer;

use Illuminate\Support\Facades\DB;
use Mooeen\Scaffold\Support\ColumnTypeGroups;
use Mooeen\Scaffold\Support\Concerns\AtomicFileWrite;
use Mooeen\Scaffold\Support\PackageRegistry;
use Mooeen\Scaffold\Support\Paths;
use Mooeen\Scaffold\Support\StorageRegistry;
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
    use AtomicFileWrite;

    // plan-51:新增 db_unique 显式语义("DB 层强约束" 跟 "app 层 soft-aware" 区分)
    private const FIELD_LEGAL_KEYS = ['name', 'type', 'size', 'min_size', 'required', 'unique', 'db_unique', 'default', 'unsigned', 'desc', 'comment', 'index', 'precision', 'format'];

    /** @var array<string, array> module cache, key = schema basename */
    private array $cache = [];

    /** Round 2 P2 cache:listModules 单 request 多次复用(index 页 + side-tree 都会调) */
    private ?array $listModulesCache = null;

    /** @var array<string,string>|null schema => 出身包 key(host schema 不在内);listSchemaFiles 时重建 */
    private ?array $originMap = null;

    /** in-memory cache:migration filename(无 .php)→ batch 号;migrations 表不存在时为 [] */
    private ?array $migrationBatchCache = null;

    /** in-memory cache:各 migration 目录下 *_table.php 的 [dir => [basename => mtime]](每目录一次 scandir,供 latestMigrationFor 复用;plan-53 起按出身分目录) */
    private array $migrationFilesCache = [];

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
            // 字段形状归一见 Designer\FieldShaper（纯函数）；index / index_disabled 的下文补在这里，不归它
            $fields[] = FieldShaper::shapeField($name, $attr, $t['locked']);
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
            foreach (StorageRegistry::models() as $moduleModels) {
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
        $this->writeSchemaYaml($path, $yaml);
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
        $this->writeSchemaYaml($path, $headerComment . $body);
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
        $this->writeSchemaYaml($newPath, $yaml);
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
        $this->writeSchemaYaml($path, $yaml);
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
        $this->writeSchemaYaml($path, $yaml);
        unset($this->cache[$schema]);
        $this->listModulesCache = null;
    }

    // v6.3 #3:plan §4 C-2 saveModule 从 200 行平铺拆成 5 个 sub-method:
    //   applyModuleBlock / applyTableAttrs / applyRenameHints / rebuildFieldRows /
    //   rebuildTableIndex / applyEnums。saveModule 自身降为 30 行 orchestration。
    //   行为零变化(只搬代码,$writable / $allowNew / allowed 列表均原样保留)。
    // 2026-09-20(第 5 项):上面这 12 个 sub-method 已**整体外迁** Designer\SchemaPayloadMerger;
    //   本方法只留 IO / 缓存失效 / origin 守卫 / updated_* stamp,调用点全改静态调用。
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

        SchemaPayloadMerger::applyModuleBlock($raw, $client);

        foreach (($client['tables'] ?? []) as $tableKey => $cTable) {
            $yamlTable          = (array) ($raw['tables'][$tableKey] ?? []);
            $yamlTable['attrs'] = (array) ($yamlTable['attrs'] ?? []);

            // 审计 metadata:before snapshot 用于 stamp updated_* 前判定真改动(无改动 round-trip save 不刷)
            $beforeSnapshot = SchemaPayloadMerger::changeSnapshot($yamlTable);

            $yamlTable = SchemaPayloadMerger::applyTableAttrs($yamlTable, $cTable);
            // plan 19 v11:Model / Controller / Resource 配置可编辑
            $yamlTable = SchemaPayloadMerger::applyTableModel($yamlTable, $cTable);
            $yamlTable = SchemaPayloadMerger::applyTableController($yamlTable, $cTable, $this->originOf($schema));

            if (array_key_exists('fields', $cTable) && is_array($cTable['fields'])) {
                $yamlFields = (array) ($yamlTable['fields'] ?? []);
                SchemaPayloadMerger::applyRenameHints($yamlFields, $yamlTable, (array) ($cTable['rename_hints'] ?? []));
                $yamlTable['fields'] = SchemaPayloadMerger::rebuildFieldRows($yamlFields, $cTable['fields']);

                $newIndex = SchemaPayloadMerger::rebuildTableIndex(
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

            $yamlTable = SchemaPayloadMerger::applyEnums($yamlTable, $cTable['enums'] ?? null);

            // 真改动才 stamp updated_*。author 为空时(CLI / test 路径)跳过 stamp,不污染 yaml
            if ($author !== null && $author !== '' && $beforeSnapshot !== SchemaPayloadMerger::changeSnapshot($yamlTable)) {
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
        $this->writeSchemaYaml($path, $yaml);
        unset($this->cache[$schema]);
        $this->listModulesCache = null;     // Round 2 P2:invalidate listModules cache(写改了字段数 / last_migration)
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
     * 原子写 schema YAML，并把底层失败统一转成 SchemaLoadException。
     *
     * 2026-09-11：原先 6 处写盘各自 `file_put_contents($path, $yaml, LOCK_EX)`。LOCK_EX 只
     * 串行化写入动作，既不防半写撕裂（进程中途死掉仍留半份文件），也挡不住读-改-写的丢更新。
     * 改为 tmp + rename 后原子性来自 rename 本身，锁反而多余，故一并去掉。
     *
     * @throws SchemaLoadException
     */
    private function writeSchemaYaml(string $path, string $yaml): void
    {
        try {
            $this->writeFileAtomically($path, $yaml);
        } catch (\RuntimeException $e) {
            throw new SchemaLoadException("write failed: {$path}", 0, $e);
        }
    }

    /**
     * schema → yaml 绝对路径,按出身解析(plan-53):包 schema 落包目录,未知名(新建)落 host。
     */
    public function yamlPath(string $schema): string
    {
        $origin = $this->originOf($schema);
        $dir    = $origin === null
            ? rtrim(Paths::database('schema'), '/')
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

        $scan(Paths::database('schema'), null);
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

                $type  = ColumnTypeGroups::canonicalize((string) ($cleaned['type'] ?? 'varchar'));
                $sized = $this->parseSize($cleaned['size'] ?? null, $type);
                if (isset($sized['_warn'])) {
                    $warnings[] = ['table' => $tableName, 'field' => $fieldNameStr, 'msg' => $sized['_warn']];
                    unset($sized['_warn']);
                }

                // plan-40 §三 R-14:unsigned 仅 numeric 类型有意义(int 系列 + 浮点系列)。
                // GUI 已经 disabled(unsigned_disabled),但 DevTools 可绕 Alpine state。
                // 后端兜底:non-numeric 类型上的 unsigned 静默 strip + warn,防 yaml 出非法 + migrate 报错。
                $numericTypes = ColumnTypeGroups::NUMERIC;
                // 2026-05-23 P0 round 5 视觉 bug 根因:之前默认 false → 数字字段 GUI 显示 unsigned 未勾选,
                // 但 FreshStorageGenerator:225 给 int/bigint/tinyint/decimal/float yaml 没写 unsigned 派生
                // 默认 true(codegen 规则)。loadNormalized 是 GUI 数据源头 — 这里默认必须对齐 codegen,
                // 否则下游 shapeField 看到的就是错值,user 满屏看到"未勾选"但 migration 出来 unsigned。
                // 用 FreshStorageGenerator:225 的窄列表(int/bigint/tinyint/decimal/float)对齐 codegen 实际行为。
                $codegenDefaultUnsigned = ColumnTypeGroups::UNSIGNED_DEFAULT;
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
                $intTypes = ColumnTypeGroups::INT;
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
        $type = ColumnTypeGroups::canonicalize((string) ($attrRaw['type'] ?? 'bigint'));

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
                in_array($type, ['varchar', 'char'], true)     => ['size' => $b, 'min_size' => $a],
                in_array($type, ColumnTypeGroups::FLOAT, true) => ['size' => $a, 'precision' => $b],
                default                                        => ['size' => $b, 'min_size' => $a, '_warn' => "type {$type} 不应有 'm,n' size"],
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
                    $value = SchemaPayloadMerger::sanitizeEnumLabel($value);
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
