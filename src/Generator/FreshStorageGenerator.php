<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2025-07-24 09:29
 * @Description: Fresh Database Storage
 */

namespace Mooeen\Scaffold\Generator;

use Brick\VarExporter\VarExporter;
use Illuminate\Support\Str;
use Mooeen\Scaffold\Support\PackageRegistry;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Yaml\Yaml;

class FreshStorageGenerator extends Generator
{
    protected string $db_schema_path;

    protected string $storage_path;

    protected string $db_relative_schema_path;

    protected string $storage_path_relative;

    protected bool $silence = false;

    /** 每个字段在各表的中文名(全量):[field_name => [table_name => zh-CN]];reportNameConflicts 据此挑叫法不一致的列 */
    protected array $field_table_names = [];

    public function start($clean = false, $silence = false): bool
    {
        $this->silence                 = $silence;
        $this->field_table_names       = [];
        $this->db_schema_path          = $this->utility->getDatabasePath('schema');
        $this->db_relative_schema_path = $this->utility->getDatabasePath('schema', true);

        $this->storage_path          = $this->utility->getStoragePath();
        $this->storage_path_relative = $this->utility->getStoragePath(true);

        if ($clean) {
            $this->cleanAll();
        }

        $menus       = [];
        $tables      = [];
        $enums       = [];
        $models      = [];
        $controllers = [];
        $all_fields  = [];

        $lang_fields = $this->utility->getLangFields();

        // plan-53 出身模型:host + 各扩展包(PackageRegistry 自动发现)的 schema 一起进缓存,
        // 每个条目挂 origin(null = host / 包 key),下游生成器凭它取 TargetContext 决定落点。
        $sources = [['dir' => $this->db_schema_path, 'origin' => null]];
        foreach (app(PackageRegistry::class)->all() as $pkgKey => $pkg) {
            $sources[] = ['dir' => $pkg['base_path'] . 'scaffold/database/', 'origin' => $pkgKey];
        }

        $seenSchemas = [];
        foreach ($sources as $source) {
            if (! is_dir($source['dir'])) {
                continue;
            }
            $origin     = $source['origin'];
            $yaml_files = $this->filesystem->allFiles($source['dir']);
            foreach ($yaml_files as $file) {
                $file = $file->getPathname();

                $file_name = basename($file, '.yaml');
                if (str_starts_with($file_name, '_') || ! str_ends_with($file, '.yaml')) {
                    continue;   // _fields.yaml / .snapshots 内容物等
                }
                // schema 名跨源全局唯一(与 SchemaLoader::listSchemaFiles 同一条规则)
                if (isset($seenSchemas[$file_name])) {
                    throw new \InvalidArgumentException("schema 名跨源重名：[{$file_name}] 同时在 [" . ($seenSchemas[$file_name] ?? 'host') . '] 与 [' . ($origin ?? 'host') . '] —— schema 名全局唯一，请改名其一。');
                }
                $seenSchemas[$file_name] = $origin;

                if (! $this->silence) {
                    $label = $origin === null ? str_replace(base_path(), '.', $file) : "[{$origin}] " . basename($file);
                    $this->console()->parsed($label);
                }

                $data              = Yaml::parseFile($file);
                $menus[$file_name] = [
                    'folder_name'  => $data['module']['folder'],
                    'tables_count' => 0,
                    'tables'       => [],
                    'origin'       => $origin,
                ];

                foreach ($data['tables'] as $table_name => $config) {
                    $controllerApps = $this->normalizeAppConfig($config['controller']['app'] ?? []);
                    $resourceApps   = $this->normalizeAppConfig($config['controller']['resource'] ?? []);

                    // 缓存 控制器 与 模型等的关系
                    if (isset($config['controller'])) {
                        // 2026-05-21 归一化:手编 yaml 漏 Controller 后缀(如 class: Memo)时兜底补,
                        // 收口到 Utility::ensureControllerSuffix 单一真源。
                        // designer GUI 路径在 SchemaLoader::applyTableController 已归一化,这里是手编 yaml 兜底。
                        $controllerClass                           = Utility::ensureControllerSuffix((string) ($config['controller']['class'] ?? ''));
                        $controllers[$file_name][$controllerClass] = [
                            'module'      => $data['module'],
                            'entity_name' => $config['attrs']['name'] ?? $table_name,     // 同 line 101/109,attrs.name 可缺省
                            'table_name'  => $table_name,
                            'model_class' => $config['model']['class'] ?? '',
                            'app'         => $controllerApps,
                            'resource'    => $resourceApps,
                            'origin'      => $origin,
                        ];
                    }

                    // 不是所有数据表都有对应的模型
                    if (isset($config['model'])) {
                        $models[$file_name][$config['model']['class']] = [
                            'module'     => $data['module'],
                            'table_name' => $table_name,
                            'app'        => $controllerApps,
                            'resource'   => $resourceApps,
                            'origin'     => $origin,
                        ];
                    }

                    $menus[$file_name]['tables_count']++;
                    $menus[$file_name]['tables'][$table_name] = [
                        'name' => $config['attrs']['name'] ?? $table_name,
                        'desc' => $config['attrs']['desc'] ?? '',     // attrs.desc 是可选(Order.yaml order_goods 历史无此 attr)
                    ];

                    // 表名全局唯一 fail-fast(2026-07-03 复盘审查 #4):tables.php/enums.php 按裸
                    // table_name 键控,跨 schema(尤其跨源)同名表会静默 last-wins 覆盖 + origin 错挂 —
                    // 下游生成器取错字段/落错目录且无任何报错。与 schema 名重名同一待遇:直接抛。
                    if (isset($tables[$table_name])) {
                        throw new \InvalidArgumentException("表名跨 schema 重复：[{$table_name}] 已在 [" . ($tables[$table_name]['origin'] ?? 'host') . '] 源定义，又出现于 [' . ($origin ?? 'host') . "] 的 {$file_name} —— 表名全局唯一，请改名其一。");
                    }

                    // plan-51:formatFields 接受 index 块,反向派生 db_unique sugar
                    // (verbose `type: unique` 写法的 yaml 在 storage cache 里要让
                    // CreateControllerGenerator 看到 attr.db_unique=true,才能 emit Request 规则)
                    $tables[$table_name] = [
                        'module_folder' => $data['module']['folder'],
                        'table_name'    => $table_name,
                        'model_class'   => $config['model']['class']  ?? null,
                        'name'          => $config['attrs']['name']   ?? $table_name,
                        'desc'          => $config['attrs']['desc']   ?? '',     // 同上,attrs.desc 可选
                        'remark'        => $config['attrs']['remark'] ?? [],
                        'index'         => $this->formatIndex($config['index'] ?? []),
                        'fields'        => $this->formatFields($config['fields'], $config['enums'] ?? [], $config['index'] ?? []),
                        'enums'         => $config['enums'] ?? [],
                        'origin'        => $origin,
                    ];

                    // 格式化字段，为 i18n 作准备
                    $this->formatI18NFields($all_fields, $table_name, $tables[$table_name]['fields'], $lang_fields);

                    $enums[$table_name] = $tables[$table_name]['enums'];
                }
            }
        }

        $this->console()->newLine();

        // 检查目录是否存在，不存在则创建
        $this->checkDirectory($this->storage_path);

        $this->buildModelList($models);
        $this->buildModelIdList($models);
        $this->writePhpCache('controllers.php', $controllers);
        $this->buildTableList($menus);
        $this->writePhpCache('enums.php', $enums);
        $this->buildTables($tables);
        $this->buildFields($all_fields);
        $this->buildFieldsCache($all_fields);

        // 同名列、不同表中文名不一致警示(警告级,即便 silent 也提示)
        $this->reportNameConflicts();

        return true;
    }

    /**
     * 格式化索引
     */
    private function formatIndex(array $index): array
    {
        if (empty($index)) {
            return [];
        }

        foreach ($index as $name => &$attr) {
            $attr['method'] = $attr['method'] ?? 'btree';
        }

        return $index;
    }

    /**
     * 格式化字段
     *
     * plan-51:接受 index 块 → 反向派生 db_unique sugar 到字段 attr。
     *   verbose 写法的 yaml(只在 index 块写 `type: unique`,fields 里没 sugar)
     *   经过 moo:fresh 后,storage cache 字段 attr 也带 `db_unique: true`,
     *   让 CreateControllerGenerator 能识别 DB-level unique 并 emit Request 规则。
     *
     *   仅处理**单字段** type:unique(`fields: 'col'` 或 `fields: ['col']`),
     *   多字段复合 unique 不影响 Request 校验(那是 join unique,Request 层无法表达)。
     */
    private function formatFields(array $fields, array $enums = [], array $index = []): array
    {
        // 反向派生:扫 index 块,把单字段 type:unique 标到对应 field.db_unique
        $dbUniqueFields = [];
        foreach ($index as $idxEntry) {
            if (! is_array($idxEntry) || ($idxEntry['type'] ?? null) !== 'unique') {
                continue;
            }
            $idxFields   = $idxEntry['fields'] ?? null;
            $singleField = null;
            if (is_string($idxFields)) {
                $singleField = $idxFields;
            } elseif (is_array($idxFields) && count($idxFields) === 1) {
                $singleField = (string) reset($idxFields);
            }
            if ($singleField !== null) {
                $dbUniqueFields[$singleField] = true;
            }
        }

        $fields = $this->formatDefaultFields($fields);
        foreach ($fields as $key => &$attr) {
            $attr['required']   = $attr['required'] ?? true;
            $attr['desc']       = $this->getFieldDesc($key, $attr['desc'] ?? '', $enums);
            $attr['allow_null'] = ! $attr['required'];

            // plan-51 db_unique 反向派生(verbose-only yaml 不丢 Request 验证规则)
            if (isset($dbUniqueFields[$key]) && empty($attr['unique'])) {
                $attr['db_unique'] = true;
            }

            $this->getSize($attr, $key);
        }

        return $fields;
    }

    /**
     * 获取字段的描述
     */
    private function getFieldDesc(string $key, string $desc, array $enums): array|string
    {
        $temp = [];

        if (array_key_exists($key, $enums)) {
            foreach ($enums[$key] as $v) {
                $temp[] = "{$v[0]}: {$v[2]}";
            }
            $desc = '{' . implode(', ', $temp) . '}';
        }

        return $desc;
    }

    /**
     * 格式化默认字段
     */
    private function formatDefaultFields(&$fields): array
    {
        $default_fields = [
            'id'         => ['name' => 'ID', 'type' => 'bigint'], // Laravel 5.8+ use bigIncrements() instead of increments()
            'deleted_at' => ['required' => false, 'name' => '删除于', 'type' => 'timestamp', 'default' => null],
            'created_at' => ['name' => '创建于', 'type' => 'timestamp'],
            'updated_at' => ['name' => '更新于', 'type' => 'timestamp'],
        ];

        foreach ($default_fields as $field => $attr) {
            if (! array_key_exists($field, $fields)) {
                continue;
            }

            if (! is_array($fields[$field]) || empty($fields[$field])) {
                $fields[$field] = $attr;

                continue;
            }

            $fields[$field] = array_replace($attr, $fields[$field]);
        }

        return $fields;
    }

    /**
     * 获取 size 值，char|varchar 时会有最小长度作为检验时使用
     */
    private function getSize(array &$attr, string $field_name): void
    {
        $attr['size'] = $attr['size'] ?? '';
        if (in_array($attr['type'], ['int', 'bigint', 'tinyint', 'decimal', 'float'])) {
            // 添加 unsigned 属性
            $attr['unsigned'] = $attr['unsigned'] ?? true;

            if ($attr['type'] === 'bigint') {
                $attr['size'] = empty($attr['size']) ? 20 : $attr['size'];
            } elseif ($attr['type'] === 'tinyint') {
                $attr['size'] = empty($attr['size']) ? 4 : $attr['size'];
            } else {
                $attr['size'] = empty($attr['size']) ? 10 : $attr['size'];
            }
        } elseif (in_array($attr['type'], ['char', 'varchar'])) {
            $attr['size'] = empty($attr['size']) ? 32 : $attr['size'];
            if (is_string($attr['size']) && str_contains($attr['size'], ',')) {
                // 保存最小长度，用于生成检验时使用
                [$attr['min_size'], $attr['size']] = explode(',', $attr['size']);
                $attr['min_size']                  = (int) $attr['min_size'];
            }
            $attr['size'] = (int) $attr['size'];
        } else {
            unset($attr['size']);
        }
    }

    /**
     * 格式化多语言字段
     * - zh-CN 始终以 schema 的字段 name 为准(改中文名后 moo:fresh 自动跟随更新)
     * - en 优先取 _fields.yaml 里的翻译润色保留,仅新字段从列名派生
     */
    private function formatI18NFields(array &$all_fields, $table_name, array $fields, array $lang_fields): void
    {
        // unset($data['id'], $data['created_at'], $data['updated_at'], $data['deleted_at']);

        foreach ($fields as $field_name => $attr) {
            // en:_fields.yaml 的翻译润色优先,缺省/空串则从列名派生(ucwords),保证非空。
            $en = (string) ($lang_fields[$field_name]['en'] ?? '');
            if ($en === '') {
                $en = ucwords(trim(str_replace('_', ' ', $field_name)));
            }

            // zh-CN 始终以 schema 的 attr['name'] 为真源(改中文名后 moo:fresh 自动跟随更新,
            // 不再整体读旧值,否则改名永不生效)。name 缺省/空串时(creator_id 等默认字段 +
            // user 没填中文名)用 en 兜底,避免空内容不友好;en 再空才退回列名。
            $zh = (string) ($attr['name'] ?? '');
            if ($zh === '') {
                $zh = $en !== '' ? $en : $field_name;
            }

            $temp = [
                'zh-CN' => $zh,
                'en'    => $en,
            ];
            $temp['type']    = $attr['type'] ?? 'varchar';     // 同款兜底 — 默认 varchar
            $temp['table']   = $table_name;
            $temp['default'] = $attr['default'] ?? '';
            $temp['format']  = $attr['format']  ?? '';

            // 记录该字段在本表的中文名(全量,含同名一致的),reportNameConflicts 据此挑叫法不一致的列
            $this->field_table_names[$field_name][$table_name] = $temp['zh-CN'];

            if (isset($all_fields['table_fields'][$field_name])) {
                $all_fields['duplicate_fields'][$field_name] = $temp;
            } else {
                $all_fields['table_fields'][$field_name] = $temp;
            }
        }
    }

    /**
     * 同名列、不同表中文名不一致 → console 警示(保留表名定位,按列分块排版,便于逐列去改)。
     *
     * _fields.yaml 按列名全局拍平,生成缓存(fields.php)里 duplicate_fields 覆盖 table_fields,
     * 实际取「字母序最后出现」那张表的中文名。排版:每个冲突列一个块,块内同一中文名归并、
     * 列出用它的所有表(主流叫法在前),这样能直接看到「哪张表把它叫成了什么」去改;
     * 列与列之间按叫法数降序(分歧最大的先看)。故意只警示、不自动仲裁(duplicate_fields 是重名信号,不是合并器)。
     */
    private function reportNameConflicts(): void
    {
        // 挑出「同一列名、在不同表里中文名 > 1 种」的冲突
        $conflicts = [];
        foreach ($this->field_table_names as $field_name => $tableNames) {
            if (count(array_unique($tableNames)) > 1) {
                $conflicts[$field_name] = $tableNames;
            }
        }
        if (empty($conflicts)) {
            return;
        }

        // 列与列:叫法数降序(分歧最大的先看),并列时列名字母序(稳定、好定位)
        uksort($conflicts, static function (string $a, string $b) use ($conflicts): int {
            $na = count(array_unique($conflicts[$a]));
            $nb = count(array_unique($conflicts[$b]));

            return ($nb <=> $na) ?: strcmp($a, $b);
        });

        $lines = [count($conflicts) . ' 个同名字段在不同表里中文名不一致（按叫法数降序；生成缓存取字母序最后那张表的中文名；统一中文名或重命名列）：'];
        foreach ($conflicts as $field_name => $tableNames) {
            // 同一中文名归并它出现的表;叫法按使用的表数量降序,主流叫法在前
            $byName = [];
            foreach ($tableNames as $table => $zh) {
                $byName[$zh][] = $table;
            }
            uasort($byName, static fn (array $x, array $y): int => count($y) <=> count($x));

            $lines[] = '';
            $lines[] = '  ' . $field_name . '（' . count($byName) . ' 种）';
            foreach ($byName as $zh => $tables) {
                // 表多时截断,避免主流叫法那行过长(要改的通常是少数派叫法,会完整列出)
                $shown   = array_slice($tables, 0, 8);
                $more    = count($tables) - count($shown);
                $tail    = $more > 0 ? " …（+{$more}）" : '';
                $lines[] = '      ' . $zh . '：' . implode(', ', $shown) . $tail;
            }
        }

        $this->console()->newLine();
        $this->console()->warn(implode(PHP_EOL, $lines));
    }

    /**
     * 所有字段缓存
     */
    private function buildFields(array $all_fields): void
    {
        // 组织 yaml 数据
        $yaml_file          = $this->db_schema_path . '_fields.yaml';
        $yaml_relative_file = $this->db_relative_schema_path . '_fields.yaml';
        if ($this->filesystem->isFile($yaml_file)) {
            // 读取现有数据:append_fields(手工字段)保留;table_fields 减量 + 同步;duplicate_fields 覆盖。
            $yaml_data      = Yaml::parseFile($yaml_file);
            $new_field_keys = array_keys($all_fields['table_fields']);
            $old_field_keys = array_keys($yaml_data['table_fields']);

            // 减量:schema 已删的字段从 _fields.yaml 移除
            $reduce_field_keys = array_diff($old_field_keys, $new_field_keys);
            foreach ($reduce_field_keys as $field_name) {
                unset($yaml_data['table_fields'][$field_name]);
            }

            // 新增 + 已存在字段一并同步:zh-CN 取 schema name(formatI18NFields 已派生 → 改名跟随),
            // en 已在 formatI18NFields 取旧 _fields.yaml 值保留,故整体覆盖不丢翻译润色。
            // 逐键赋值(而非整表替换)保留原有字段顺序,新字段追加末尾,最小化 diff。
            foreach ($all_fields['table_fields'] as $field_name => $attr) {
                $yaml_data['table_fields'][$field_name] = $attr;
            }

            if (isset($all_fields['duplicate_fields'])) {
                $yaml_data['duplicate_fields'] = $all_fields['duplicate_fields'];
            }

            $all_fields = $yaml_data;
        }

        $code = [
            '###',
            '# 润色，手动修改翻译（生成时不会被替换）',
            '#',
            '# append_fields: 为手工添加字段，一直保存',
            '# table_fields: 数据库里的字段，会自动做增量、减量',
            '# duplicate_fields: 数据库里重复出现的，有可能是重名了',
            '##',
        ];

        foreach ($all_fields as $type => $fields) {
            $code[] = "{$type}:";
            if (empty($fields)) {
                continue;
            }

            foreach ($fields as $field_name => $attr) {
                // YAML 单引号串里的单引号要双写转义('' )—— 否则 en/zh-CN 含撇号(如 Employee's Name)
                // 会写出非法 YAML,下次 moo:fresh 解析崩、整条流水线挂(2026-06-09 修)。
                $en   = str_replace("'", "''", (string) $attr['en']);
                $zh   = str_replace("'", "''", (string) $attr['zh-CN']);
                $yaml = ["en: '{$en}'", "'zh-CN': '{$zh}'"];

                $code[] = $this->getTabs(1) . "{$field_name}: { " . implode(', ', $yaml) . ' }';
            }
        }
        $code[] = '';

        // 生成可手动修改，用于多语言的，不能被清空覆盖的
        $yaml_exists = $this->filesystem->isFile($yaml_file);
        $put         = $this->filesystem->put($yaml_file, implode("\n", $code));
        $this->reportPutResult($yaml_relative_file, $put, $yaml_exists);
    }

    /**
     *  生成缓存的所有字段数据
     */
    private function buildFieldsCache(array $data): void
    {
        // 附加分页码，在生成接口时用
        $data['append_fields']['page'] = [
            'en'      => 'Page',
            'zh-CN'   => '分页码',
            'int'     => 'int',
            'default' => 1,
        ];
        $data['append_fields']['page_limit'] = [
            'en'      => 'Page Limit',
            'zh-CN'   => '分页数量',
            'int'     => 'int',
            'default' => 10,
        ];
        $data['append_fields']['ids'] = [
            'en'      => 'Ids',
            'zh-CN'   => '多个编号',
            'int'     => 'varchar',
            'default' => '2,3',
        ];

        $php_data = [];
        foreach ($data as $type => $fields) {
            if (empty($fields)) {
                continue;
            }

            foreach ($fields as $field_name => $attr) {
                $php_data[$field_name] = [
                    'en'      => $attr['en'],
                    'zh-CN'   => $attr['zh-CN'],
                    'type'    => $attr['type']   ?? null,
                    'format'  => $attr['format'] ?? null,
                    'default' => $attr['default'],
                    'table'   => $attr['table'] ?? '',
                ];

            }
        }

        // 生成缓存的，可被覆盖的，因为有时修改字段 type 和 default
        $this->writePhpCache('fields.php', $php_data);
    }

    /**
     * 生成 数据库表 数据
     */
    private function buildTables(array $tables): void
    {
        foreach ($tables as $name => $table) {
            $this->writePhpCache("{$name}.php", $table);
        }
    }

    /**
     * 生成模型列表数据
     */
    private function buildModelList(array $models): void
    {
        $this->writePhpCache('models.php', $models);
    }

    /**
     * 生成模型列表数据
     */
    private function buildModelIdList(array $data): void
    {
        $model_ids  = [];
        $model_path = $this->utility->getModelPath(true);

        foreach ($data as $folder => $models) {
            foreach ($models as $model => $config) {
                $model_id             = Str::snake($model, '_') . '_id';
                $namespace            = $this->utility->formatNameSpace($model_path) . $config['module']['folder'];
                $model_ids[$model_id] = [
                    'namespace'  => $namespace,
                    'model'      => $namespace . '\\' . $model,
                    'model_name' => $model,
                    'table_name' => $config['table_name'],
                ];
            }
        }

        $this->writePhpCache('model_ids.php', $model_ids);
    }

    /**
     * 生成数据表列表数据
     */
    private function buildTableList(array $menus): void
    {
        $this->writePhpCache('tables.php', $menus);
    }

    /**
     * 清除所有缓存
     */
    private function cleanAll(): void
    {
        $this->console()->section('Cleaning scaffold caches');
        $clean = $this->filesystem->cleanDirectory($this->storage_path);
        if ($clean) {
            $this->console()->cleaned($this->storage_path_relative);
        } else {
            $this->console()->failed($this->storage_path_relative, 'Clean failed');
        }

        $this->utility->addGitIgnore($this->command);

        $this->console()->newLine();
    }

    private function writePhpCache(string $filename, array $data): void
    {
        $php_code = '<?php declare(strict_types=1);' . PHP_EOL
                    . 'return ' . VarExporter::export($data) . ';'
                    . PHP_EOL;

        $file        = $this->storage_path . $filename;
        $file_exists = $this->filesystem->isFile($file);
        $put         = $this->filesystem->put($file, $php_code);
        $this->reportPutResult($this->storage_path_relative . $filename, $put, $file_exists);
    }

    private function reportPutResult(string $relativeFile, int|bool $put, bool $fileExists): void
    {
        if ($this->silence) {
            return;
        }

        if (! $put) {
            $this->console()->failed($relativeFile);

            return;
        }

        if ($fileExists) {
            $this->console()->updated($relativeFile);
        } else {
            $this->console()->created($relativeFile);
        }
    }

    /**
     * 统一格式化 app / resource 配置
     */
    private function normalizeAppConfig(array|string|null $apps): array
    {
        if ($apps === null || $apps === '') {
            return [];
        }

        $apps = is_array($apps) ? $apps : [$apps];
        $apps = array_map(
            static fn ($item): string => strtolower(trim((string) $item)),
            $apps
        );

        $apps = array_values(array_filter($apps, static fn (string $item): bool => $item !== ''));

        return array_values(array_unique($apps));
    }
}
