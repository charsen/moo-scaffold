<?php

namespace Mooeen\Scaffold\Generator;

use Brick\VarExporter\VarExporter;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Fresh Database Storage
 *
 * @author Charsen https://github.com/charsen
 */
class FreshStorageGenerator extends Generator
{
    protected string $db_schema_path;

    protected string $storage_path;

    protected string $db_relative_schema_path;

    protected string $storage_path_relative;

    public function start($clean = false): bool
    {
        $this->db_schema_path          = $this->utility->getDatabasePath('schema');
        $this->db_relative_schema_path = $this->utility->getDatabasePath('schema', true);

        $this->storage_path          = $this->utility->getStoragePath();
        $this->storage_path_relative = $this->utility->getStoragePath(true);

        if ($clean) {
            $this->cleanAll();
        }

        $yaml        = new Yaml;
        $menus       = [];
        $tables      = [];
        $enums       = [];
        $models      = [];
        $controllers = [];
        $all_fields  = [];

        $lang_fields = $this->utility->getLangFields();
        $yaml_files  = $this->filesystem->allFiles($this->db_schema_path);
        foreach ($yaml_files as $file) {
            $file = $file->getPathname();

            $file_name = basename($file, '.yaml');
            if ($file_name === '_fields') {
                continue;
            }

            $this->command->warn('+ Parse: ' . str_replace(base_path(), '.', $file));

            $data              = $yaml::parseFile($file);
            $menus[$file_name] = [
                'folder_name'  => $data['module']['folder'],
                'tables_count' => 0,
                'tables'       => [],
            ];

            foreach ($data['tables'] as $table_name => $config) {
                // 缓存 控制器 与 模型等的关系
                if (isset($config['controller'])) {
                    //                    $app_namespace                                           = ucfirst($data['package']['folder']);
                    //                    $app_namespace                                           = str_replace('/', '\\', $app_namespace);
                    $controllers[$file_name][$config['controller']['class']] = [
                        'module' => $data['module'],
                        //                        'namespace'   => "{$app_namespace}\\Controllers\\{$data['module']['folder']}",
                        'entity_name' => $config['attrs']['name'],
                        'table_name'  => $table_name,
                        'model_class' => $config['model']['class']    ?? '',
                        'app'         => $config['controller']['app'] ?? [],
                    ];
                }

                // 不是所有数据表都有对应的模型
                if (isset($config['model'])) {
                    $models[$file_name][$config['model']['class']] = [
                        'module'     => $data['module'],
                        'table_name' => $table_name,
                    ];
                }

                $menus[$file_name]['tables_count']++;
                $menus[$file_name]['tables'][$table_name] = [
                    'name' => $config['attrs']['name'],
                    'desc' => $config['attrs']['desc'],
                ];

                $tables[$table_name] = [
                    'module_folder' => $data['module']['folder'],
                    'table_name'    => $table_name,
                    'model_class'   => $config['model']['class'] ?? null,
                    'name'          => $config['attrs']['name'],
                    'desc'          => $config['attrs']['desc'],
                    'remark'        => $config['attrs']['remark'] ?? [],
                    'index'         => $this->formatIndex($config['index'] ?? []),
                    'fields'        => $this->formatFields($config['fields'], $config['enums'] ?? []),
                    'enums'         => $config['enums'] ?? [],
                ];

                // 格式化字段，为 i18n 作准备
                $this->formatI18NFields($all_fields, $table_name, $tables[$table_name]['fields'], $lang_fields);

                $enums[$table_name] = $tables[$table_name]['enums'];
            }
        }

        // 检查目录是否存在，不存在则创建
        $this->checkDirectory($this->storage_path);

        $this->buildModelList($models);
        $this->buildModelIdList($models);
        $this->buildControllerList($controllers);
        $this->buildTableList($menus);
        $this->buildEnums($enums);
        $this->buildTables($tables);
        $this->buildFields($all_fields);
        $this->buildFieldsCache($all_fields);

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
     */
    private function formatFields(array $fields, array $enums = []): array
    {
        $fields = $this->formatDefaultFields($fields);
        foreach ($fields as $key => &$attr) {
            $attr['required']   = $attr['required'] ?? true;
            $attr['desc']       = $this->getFieldDesc($key, $attr['desc'] ?? '', $enums);
            $attr['allow_null'] = ! $attr['required'];

            $this->getDefault($attr, $key);
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
            'id'         => ['name' => '编号', 'type' => 'bigint'], // Laravel 5.8+ use bigIncrements() instead of increments()
            'deleted_at' => ['required' => false, 'name' => '删除于', 'type' => 'timestamp', 'default' => null],
            'created_at' => ['name' => '创建于', 'type' => 'timestamp'],
            'updated_at' => ['name' => '更新于', 'type' => 'timestamp'],
        ];

        foreach ($default_fields as $filed => $attr) {
            if (isset($fields[$filed]) && empty($fields[$filed])) {
                $fields[$filed] = $attr;
            }
        }

        return $fields;
    }

    /**
     * 获取默认值
     */
    private function getDefault(array &$attr, string $field_name): void
    {
        //        if (array_key_exists('default', $attr)) {
        //            if (is_null($attr['default'])) {
        //                $attr['default'] = null;
        //            }
        //        }
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
            if (str_contains($attr['size'], ',')) {
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
     * - 用 _fields.yaml 里润色的名称替换缓存里的，同时附加上 _fields.yaml 的附加字段
     */
    private function formatI18NFields(array &$all_fields, $table_name, array $fields, array $lang_fields): void
    {
        //unset($data['id'], $data['created_at'], $data['updated_at'], $data['deleted_at']);

        foreach ($fields as $field_name => $attr) {
            if (isset($lang_fields[$field_name])) {
                $temp = [
                    'zh-CN' => $lang_fields[$field_name]['zh-CN'],
                    'en'    => $lang_fields[$field_name]['en'],
                ];
            } else {
                $temp = [
                    'zh-CN' => $attr['name'],
                    'en'    => ucwords(trim(str_replace('_', ' ', $field_name))),
                ];
            }
            $temp['type']    = $attr['type'];
            $temp['table']   = $table_name;
            $temp['default'] = $attr['default'] ?? '';
            $temp['format']  = $attr['format']  ?? '';

            if (isset($all_fields['table_fields'][$field_name])) {
                $all_fields['duplicate_fields'][$field_name] = $temp;
            } else {
                $all_fields['table_fields'][$field_name] = $temp;
            }
        }
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
            // 读取现有数据，保留 append_fields，table_fields 做增量，duplicate_fields 做覆盖
            $yaml           = new Yaml;
            $yaml_data      = $yaml::parseFile($yaml_file);
            $new_field_keys = array_keys($all_fields['table_fields']);
            $old_field_keys = array_keys($yaml_data['table_fields']);

            $reduce_field_keys   = array_diff($old_field_keys, $new_field_keys);
            $increase_field_keys = array_diff($new_field_keys, $old_field_keys);

            foreach ($reduce_field_keys as $field_name) {
                unset($yaml_data['table_fields'][$field_name]);
            }

            foreach ($increase_field_keys as $field_name) {
                $yaml_data['table_fields'][$field_name] = $all_fields['table_fields'][$field_name];
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
                $yaml   = ["en: '{$attr['en']}'"];
                $yaml[] = "'zh-CN': '{$attr['zh-CN']}'";

                $code[] = $this->getTabs(1) . "{$field_name}: { " . implode(', ', $yaml) . ' }';
            }
        }
        $code[] = '';

        // 生成可手动修改，用于多语言的，不能被清空覆盖的
        $put = $this->filesystem->put($yaml_file, implode("\n", $code));
        if ($put) {
            $this->command->info('+ ' . $yaml_relative_file);
        } else {
            $this->command->error('x ' . $yaml_relative_file);
        }
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
        $php_code = '<?php' . PHP_EOL
                    . 'return ' . VarExporter::export($php_data) . ';'
                    . PHP_EOL;

        $put = $this->filesystem->put($this->storage_path . 'fields.php', $php_code);
        if ($put) {
            $this->command->info('+ ' . $this->storage_path_relative . 'fields.php');
        } else {
            $this->command->error('x ' . $this->storage_path_relative . 'fields.php');
        }
    }

    /**
     * 生成 数据库表 数据
     */
    private function buildTables(array $tables): void
    {
        /** 数据表的详情处理，生成单表单个文件 */
        foreach ($tables as $name => $table) {
            $php_code = '<?php' . PHP_EOL
                        . 'return ' . VarExporter::export($table) . ';'
                        . PHP_EOL;

            $put = $this->filesystem->put($this->storage_path . "{$name}.php", $php_code);
            if ($put) {
                $this->command->info('+ ' . $this->storage_path_relative . "{$name}.php");
            } else {
                $this->command->error('x ' . $this->storage_path_relative . "{$name}.php");
            }
        }
    }

    /**
     * 生成 模型字典 数据
     */
    private function buildEnums(array $enums): void
    {
        /** 数据字典生成，生成单表单个文件 */
        $php_code = '<?php' . PHP_EOL
                    . 'return ' . VarExporter::export($enums) . ';'
                    . PHP_EOL;

        $put = $this->filesystem->put($this->storage_path . 'enums.php', $php_code);

        if ($put) {
            $this->command->info('+ ' . $this->storage_path_relative . 'enums.php');
        } else {
            $this->command->error('x ' . $this->storage_path_relative . 'enums.php');
        }
    }

    /**
     * 生成控制器列表数据
     */
    private function buildControllerList(array $controllers): void
    {
        /** 数据首页生成，列表 */
        $php_code = '<?php' . PHP_EOL
                    . 'return ' . VarExporter::export($controllers) . ';'
                    . PHP_EOL;

        $put = $this->filesystem->put($this->storage_path . 'controllers.php', $php_code);

        if ($put) {
            $this->command->info('+ ' . $this->storage_path_relative . 'controllers.php');
        } else {
            $this->command->error('x ' . $this->storage_path . 'controllers.php');
        }
    }

    /**
     * 生成模型列表数据
     */
    private function buildModelList(array $models): void
    {
        /** 数据首页生成，列表 */
        $php_code = '<?php' . PHP_EOL
                    . 'return ' . VarExporter::export($models) . ';'
                    . PHP_EOL;

        $put = $this->filesystem->put($this->storage_path . 'models.php', $php_code);

        if ($put) {
            $this->command->info('+ ' . $this->storage_path_relative . 'models.php');
        } else {
            $this->command->error('x ' . $this->storage_path . 'models.php');
        }
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
                $namespace            = ucfirst(str_replace(['./', '/'], ['', '\\'], $model_path)) . $config['module']['folder'];
                $model_ids[$model_id] = [
                    'namespace'  => $namespace,
                    'model'      => $namespace . '\\' . $model,
                    'model_name' => $model,
                    'table_name' => $config['table_name'],
                ];
            }
        }

        /** 数据首页生成，列表 */
        $php_code = '<?php' . PHP_EOL
            . 'return ' . VarExporter::export($model_ids) . ';'
            . PHP_EOL;

        $put = $this->filesystem->put($this->storage_path . 'model_ids.php', $php_code);

        if ($put) {
            $this->command->info('+ ' . $this->storage_path_relative . 'model_ids.php');
        } else {
            $this->command->error('x ' . $this->storage_path . 'model_ids.php');
        }
    }

    /**
     * 生成数据表列表数据
     */
    private function buildTableList(array $menus): void
    {
        /** 数据首页生成，列表 */
        $php_code = '<?php' . PHP_EOL
                    . 'return ' . VarExporter::export($menus) . ';'
                    . PHP_EOL;

        $put = $this->filesystem->put($this->storage_path . 'tables.php', $php_code);

        if ($put) {
            $this->command->info('+ ' . $this->storage_path_relative . 'tables.php');
        } else {
            $this->command->error('x ' . $this->storage_path . 'tables.php');
        }
    }

    /**
     * 清除所有缓存
     */
    private function cleanAll(): void
    {
        $this->command->warn("\n******************     clean caches     ******************");
        $clean = $this->filesystem->cleanDirectory($this->storage_path);
        if ($clean) {
            $this->command->info('√ clean ' . $this->storage_path_relative . ' successes!');
        } else {
            $this->command->error('x clean ' . $this->storage_path_relative . ' failed!');
        }

        $this->utility->addGitIgnore($this->command);

        $this->command->warn("\n******************     moo:fresh     ******************");
    }
}
