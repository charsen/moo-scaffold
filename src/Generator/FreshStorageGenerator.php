<?php
namespace Charsen\Scaffold\Generator;

use Symfony\Component\Yaml\Yaml;

/**
 * Fresh Database Storage
 *
 * @author Charsen https://github.com/charsen
 */
class FreshStorageGenerator extends Generator
{

    protected $db_schema_path;
    protected $storage_path;
    protected $db_relative_schema_path;
    protected $storage_path_relative;

    /**
     * @param $clean
     */
    public function start($clean = false)
    {
        $this->db_schema_path           = $this->utility->getDatabasePath('schema');
        $this->db_relative_schema_path  = $this->utility->getDatabasePath('schema', true);

        $this->storage_path          = $this->utility->getStoragePath();
        $this->storage_path_relative = $this->utility->getStoragePath(true);

        if ($clean)
        {
            $this->cleanAll();
        }

        $yaml          = new Yaml;
        $menus         = [];
        $tables        = [];
        $dictionaries  = [];
        $models        = [];
        $controllers   = [];
        $all_fields    = [];

        $lang_fields   = $this->utility->getLangFields();
        $yaml_files    = $this->filesystem->allFiles($this->db_schema_path);
        foreach ($yaml_files as $file)
        {
            $file      = $file->getPathname();
            $file_name = basename($file, '.yaml');
            if ($file_name == '_fields')
            {
                continue;
            }

            $data              = $yaml::parseFile($file);
            $menus[$file_name] = [
                'folder_name'  => $data['module_name'],
                'tables_count' => 0,
                'tables'       => [],
            ];

            foreach ($data['tables'] as $table_name => $config)
            {
                // 缓存 控制器 与 模型等的关系
                if (isset($config['controller']))
                {
                    $controllers[$file_name][$config['controller']['class']] = [
                        'package_name'     => $data['package_name'],
                        'module_name'      => $data['module_name'],
                        'entity_name'      => $config['attrs']['name'],
                        'table_name'       => $table_name,
                        'model_class'      => $config['model']['class'] ?? '',
                    ];
                }

                // 不是所有数据表都有对应的模型
                if (isset($config['model']))
                {
                    $models[$file_name][$config['model']['class']] = [
                        'table_name'       => $table_name,
                    ];
                }

                $menus[$file_name]['tables_count']++;
                $menus[$file_name]['tables'][$table_name] = [
                    'name' => $config['attrs']['name'],
                    'desc' => $config['attrs']['desc'],
                ];

                $tables[$table_name] = [
                    'table_name'       => $table_name,
                    'model_class'      => $config['model']['class'] ?? NULL,
                    'name'             => $config['attrs']['name'],
                    'desc'             => $config['attrs']['desc'],
                    'remark'           => $config['attrs']['remark'] ?? [],
                    'index'            => $this->formatIndex($config['index'] ?? []),
                    'fields'           => $this->formatFields($config['fields']),
                    'dictionaries'     => $config['dictionaries'] ?? [],
                ];

                // 格式化字段，为 i18n 作准备
                $this->formatI18NFields($all_fields, $table_name, $tables[$table_name]['fields'], $lang_fields);

                $dictionaries[$table_name] = $tables[$table_name]['dictionaries'];
            }
        }

        $this->buildModelList($models);
        $this->buildControllerList($controllers);
        $this->buildTableList($menus);
        $this->buildDictionaries($dictionaries);
        $this->buildTables($tables);
        $this->buildFields($all_fields);
        $this->buildFieldsCache($all_fields);

        return true;
    }

    /**
     * 格式化索引
     *
     * @param array $index
     * @return array
     */
    private function formatIndex(array $index)
    {
        if (empty($index))
        {
            return [];
        }

        foreach ($index as $name => &$attr)
        {
            $attr['method'] = $attr['method'] ?? 'btree';
        }

        return $index;
    }

    /**
     * 格式化字段
     *
     * @param array $fields
     * @return array
     */
    private function formatFields(array $fields)
    {
        $fields = $this->formatDefaultFields($fields);
        foreach ($fields as $key => &$attr)
        {
            $attr['require']    = $attr['require'] ?? true;
            $attr['desc']       = $attr['desc'] ?? '';
            $attr['default']    = $attr['default'] ?? null;
            $attr['allow_null'] = $attr['require'] ? false : true;
            $this->getSize($attr);
        }

        return $fields;
    }

    /**
     * 格式化默认字段
     *
     * @param &$fields
     *
     * @return array
     */
    private function formatDefaultFields(&$fields)
    {
        $default_fields = [
            'id'         => ['name' => '编号', 'type' => 'int'],
            'deleted_at' => ['require' => false, 'name' => '删除于', 'type' => 'timestamp', 'default' => null],
            'created_at' => ['name' => '创建于', 'type' => 'timestamp'],
            'updated_at' => ['name' => '更新于', 'type' => 'timestamp'],
        ];

        foreach ($default_fields as $filed => $attr)
        {
            if (isset($fields[$filed]) && empty($fields[$filed]))
            {
                $fields[$filed] = $attr;
            }
        }

        return $fields;
    }

    /**
     * 获取 size 值，char|varchar 时会有最小长度作为检验时使用
     *
     * @param  array &$attr
     *
     * @return array
     */
    private function getSize(&$attr)
    {
        $attr['size'] = $attr['size'] ?? '';
        if (in_array($attr['type'], ['int', 'bigint', 'tinyint', 'decimal', 'float']))
        {
            // 添加 unsigned 属性
            $attr['unsigned'] = $attr['unsigned'] ?? true;
            $attr['default']  = empty($attr['default']) ? 0 : $attr['default'];
            if ($attr['type'] == 'bigint')
            {
                $attr['size'] = $attr['size'] == '' ? 20 : $attr['size'];
            }
            else if ($attr['type'] == 'tinyint')
            {
                $attr['size'] = $attr['size'] == '' ? 1 : $attr['size'];
            }
            else
            {
                $attr['size'] = $attr['size'] == '' ? 10 : $attr['size'];
            }
        }
        elseif (in_array($attr['type'], ['char', 'varchar']))
        {
            $attr['default'] = empty($attr['default']) ? '' : $attr['default'];
            $attr['size']    = empty($attr['size']) ? 32 : $attr['size'];
            if (strstr($attr['size'], '|'))
            {
                // 保存最小长度，用于生成检验时使用
                list($attr['min_size'], $attr['size']) = explode('|', $attr['size']);
            }
        }
        else
        {
            unset($attr['size']);
        }

        return $attr;
    }

    /**
     * 格式化多语言字段
     *
     * - 用 _fields.yaml 里润色的名称替换缓存里的，同时附加上 _fields.yaml 的附加字段
     *
     * @param  array $all_fields
     * @param        $table_name
     * @param  array $fields
     * @param array  $lang_fields
     *
     * @return array
     */
    private function formatI18NFields(array &$all_fields, $table_name, array $fields, array $lang_fields)
    {
        //unset($data['id'], $data['created_at'], $data['updated_at'], $data['deleted_at']);

        foreach ($fields as $field_name => $attr)
        {
            if (isset($lang_fields[$field_name]))
            {
                $temp = [
                    'zh-CN'   => $lang_fields[$field_name]['zh-CN'],
                    'en'      => $lang_fields[$field_name]['en'],
                ];
            }
            else
            {
                $temp = [
                    'zh-CN'   => $attr['name'],
                    'en'      => trim(ucwords(str_replace('_', ' ', $field_name))),
                ];
            }
            $temp['type']     = $attr['type'];
            $temp['table']    = $table_name;
            $temp['default']  = !empty($attr['default']) ? $attr['default'] : '';
            $temp['format']   = !empty($attr['format']) ? $attr['format'] : '';

            if (isset($all_fields['table_fields'][$field_name]))
            {
                $all_fields['duplicate_fields'][$field_name] = $temp;
            }
            else
            {
                $all_fields['table_fields'][$field_name] = $temp;
            }
        }

        return $all_fields;
    }

    /**
     * 所有字段缓存
     * @param  array  $all_fields
     * @return mixed
     */
    private function buildFields(array $all_fields)
    {
        // 组织 yaml 数据
        $yaml_file          = $this->db_schema_path . '_fields.yaml';
        $yaml_relative_file = $this->db_relative_schema_path . '_fields.yaml';
        if ($this->filesystem->isFile($yaml_file))
        {
            // 读取现有数据，保留 append_fields，table_fields 做增量，duplicate_fields 做覆盖
            $yaml           = new Yaml;
            $yaml_data      = $yaml::parseFile($yaml_file);
            $new_field_keys = array_keys($all_fields['table_fields']);
            $old_field_keys = array_keys($yaml_data['table_fields']);

            $reduce_field_keys   = array_diff($old_field_keys, $new_field_keys);
            $increase_field_keys = array_diff($new_field_keys, $old_field_keys);

            foreach ($reduce_field_keys as $field_name)
            {
                unset($yaml_data['table_fields'][$field_name]);
            }

            foreach ($increase_field_keys as $field_name)
            {
                $yaml_data['table_fields'][$field_name] = $all_fields['table_fields'][$field_name];
            }

            if (isset($all_fields['duplicate_fields']))
            {
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

        foreach ($all_fields as $type => $fields)
        {
            $code[] = "{$type}:";
            if (empty($fields))
            {
                continue;
            }

            foreach ($fields as $field_name => $attr)
            {
                $yaml   = ["en: '{$attr['en']}'"];
                $yaml[] = "'zh-CN': '{$attr['zh-CN']}'";

                $code[] = $this->getTabs(1) . "{$field_name}: { " . implode(', ', $yaml) . ' }';
            }
        }
        $code[] = '';

        // 生成可手动修改，用于多语言的，不能被清空覆盖的
        $put    = $this->filesystem->put($yaml_file, implode("\n", $code));
        if ($put)
        {
            return $this->command->info('+ ' . $yaml_relative_file . ' (Updated)');
        }

        return $this->command->error('x ' . $yaml_relative_file . '(Failed)');
    }

    /**
     *  生成缓存的所有字段数据
     *
     * @param $data
     */
    private function buildFieldsCache($data)
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
        foreach ($data as $type => $fields)
        {
            if (empty($fields))
            {
                continue;
            }

            foreach ($fields as $field_name => $attr)
            {
                $php_data[$field_name] = [
                    'en'      => $attr['en'],
                    'zh-CN'   => $attr['zh-CN'],
                    'type'    => $attr['type'] ?? null,
                    'format'  => $attr['format'] ?? null,
                    'default' => ! empty($attr['default']) ? $attr['default'] : '',
                    'table'   => ! empty($attr['table']) ? $attr['table'] : '',
                ];

            }
        }
        // 生成缓存的，可被覆盖的，因为有时修改字段 type 和 default
        $php_code = '<?php' . PHP_EOL
                    . 'return ' . var_export($php_data, true) . ';'
                    . PHP_EOL;

        $put = $this->filesystem->put($this->storage_path . 'fields.php', $php_code);
        if ($put)
        {
            return $this->command->info('+ ' . $this->storage_path_relative . 'fields.php (Updated)');
        }

        return $this->command->error('x ' . $this->storage_path_relative . 'fields.php (Failed)');
    }

    /**
     * @param $tables
     *
     * @return bool
     */
    private function buildTables($tables)
    {
        /** 数据表的详情处理，生成单表单个文件 */
        foreach ($tables as $name => $table)
        {
            $php_code = '<?php' . PHP_EOL
                . 'return ' . var_export($table, true) . ';'
                . PHP_EOL;

            $put = $this->filesystem->put($this->storage_path . "{$name}.php", $php_code);
            if ($put)
            {
                $this->command->info('+ ' . $this->storage_path_relative . "{$name}.php (Updated)");
            }
            else
            {
                $this->command->error('x ' . $this->storage_path_relative . "{$name}.php (Failed)");
            }
        }

        return true;
    }

    /**
     * @param $dictionaries
     * @return mixed
     */
    private function buildDictionaries($dictionaries)
    {
        /** 数据字典生成，生成单表单个文件 */
        $php_code = '<?php' . PHP_EOL
            . 'return ' . var_export($dictionaries, true) . ';'
            . PHP_EOL;

        $put = $this->filesystem->put($this->storage_path . 'dictionaries.php', $php_code);

        if ($put)
        {
            return $this->command->info('+ ' . $this->storage_path_relative . 'dictionaries.php (Updated)');
        }

        return $this->command->error('x ' . $this->storage_path_relative . 'dictionaries.php (Failed)');
    }

    /**
     * 生成控制器列表数据
     *
     * @param array $controllers
     * @return mixed
     */
    private function buildControllerList(array $controllers)
    {
        /** 数据首页生成，列表 */
        $php_code = '<?php' . PHP_EOL
            . 'return ' . var_export($controllers, true) . ';'
            . PHP_EOL;

        $put = $this->filesystem->put($this->storage_path . 'controllers.php', $php_code);

        if ($put)
        {
            return $this->command->info('+ ' . $this->storage_path_relative . 'controllers.php (Updated)');
        }

        return $this->command->error('x ' . $this->storage_path . 'controllers.php (Failed)');
    }

    /**
     * 生成模型列表数据
     *
     * @param array $models
     * @return mixed
     */
    private function buildModelList(array $models)
    {
        /** 数据首页生成，列表 */
        $php_code = '<?php' . PHP_EOL
            . 'return ' . var_export($models, true) . ';'
            . PHP_EOL;

        $put = $this->filesystem->put($this->storage_path . 'models.php', $php_code);

        if ($put)
        {
            return $this->command->info('+ ' . $this->storage_path_relative . 'models.php (Updated)');
        }

        return $this->command->error('x ' . $this->storage_path . 'models.php (Failed)');
    }

    /**
     * 生成数据表列表数据
     *
     * @param array $menus
     * @return mixed
     */
    private function buildTableList(array $menus)
    {
        /** 数据首页生成，列表 */
        $php_code = '<?php' . PHP_EOL
            . 'return ' . var_export($menus, true) . ';'
            . PHP_EOL;

        $put = $this->filesystem->put($this->storage_path . 'tables.php', $php_code);

        if ($put)
        {
            return $this->command->info('+ ' . $this->storage_path_relative . 'tables.php (Updated)');
        }

        return $this->command->error('x ' . $this->storage_path . 'tables.php (Failed)');
    }

    /**
     * 清除所有缓存
     *
     * @return boolean
     */
    private function cleanAll()
    {
        $this->command->warn('+ cleaning caches...');
        $clean = $this->filesystem->cleanDirectory($this->storage_path);
        if ($clean)
        {
            $this->command->info('+ clean ' . $this->storage_path_relative . ' successed!');
            $this->command->warn('+ cleaned');
        }
        else
        {
            $this->command->error('x clean ' . $this->storage_path_relative . ' failed!');
        }

        return true;
    }
}
