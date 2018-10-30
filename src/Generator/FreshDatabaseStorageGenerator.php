<?php
namespace Charsen\Scaffold\Generator;

use Symfony\Component\Yaml\Yaml;

/**
 * Fresh Database Storage
 *
 * @author   Charsen <780537@gmail.com>
 */
class FreshDatabaseStorageGenerator extends Generator
{
    protected $schema_path;
    protected $storage_path;

    public function start($format = false)
    {
        $this->schema_path           = $this->utility->getDatabasePath('schema');
        $this->storage_path          = $this->utility->getDatabasePath('storage');
        $this->relative_storage_path = $this->utility->getDatabasePath('storage', true);

        $file_names = $this->filesystem->allFiles($this->schema_path);
        if ($format)
        {
            $this->command->warn('+ cleaning...');
            $clean = $this->filesystem->cleanDirectory($this->storage_path);
            if ($clean)
            {
                $this->command->info('+ clean ' . $this->relative_storage_path . ' successed!');
                $this->command->warn('+ cleaned');
            }
            else
            {
                $this->command->error('x clean ' . $this->relative_storage_path . ' failed!');
            }
        }

        $yaml         = new Yaml;
        $menus        = [];
        $tables       = [];
        $dictionaries = [];

        foreach ($file_names as $file)
        {
            $file      = $file->getPathname();
            $data      = $yaml::parseFile($file);
            $file_name = basename($file, '.yaml');

            $menus[$file_name] = [
                'folder_name'  => $data['name'],
                'tables_count' => 0,
                'tables'       => [],
            ];

            foreach ($data['tables'] as $table_name => $config)
            {
                $menus[$file_name]['tables'][$table_name] = ['name' => $config['attrs']['name'], 'desc' => $config['attrs']['desc']];
                $menus[$file_name]['tables_count']++;

                $tables[$table_name] = [
                    'table_name'   => $table_name,
                    'name'         => $config['attrs']['name'],
                    'desc'         => $config['attrs']['desc'],
                    'indexs'       => $config['indexs'],
                    'fields'       => $this->formatFields($config['fields']),
                    'dictionaries' => $config['dictionaries'] ?? [],
                ];

                $dictionaries[$table_name] = $tables[$table_name]['dictionaries'];
            }
        }

        $build = $this->buildTableList($menus);
        $build = $this->buildDictionaries($dictionaries);
        $build = $this->buildTables($tables);
    }

    private function formatFields(&$fields)
    {
        foreach ($fields as $key => &$config)
        {
            $config['require']    = $config['require'] ?? true;
            $config['size']       = $config['size'] ?? 0;
            $config['unsigned']   = $config['unsigned'] ?? true;
            $config['desc']       = $config['desc'] ?? '';
            $config['default']    = $config['default'] ?? '';
            $config['allow_null'] = (!$config['require'] && $config['default'] == '') ? true : false;

            if (in_array($config['type'], ['int', 'bigint', 'tinyint']))
            {
                $config['unsigned'] = $config['unsigned'] == '' ? true : $config['unsigned'];
                if ($config['type'] == 'bigint' && $config['size'] == '')
                {
                    $config['size'] = 20;
                }
                else if ($config['type'] == 'bigint' && $config['size'] == '')
                {
                    $config['size'] = 10;
                }
                else
                {
                    $config['size'] = 2;
                }
            }

            if (in_array($config['type'], ['char', 'varchar']))
            {
                $config['size'] = $config['size'] ?? 32;
            }
        }

        return $fields;
    }

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
                $this->command->info('+ ' . $this->relative_storage_path . "{$name}.php (Updated)");
            }
            else
            {
                $this->command->error('x ' . $this->relative_storage_path . "{$name}.php (Failed)");
            }
        }

        return true;
    }

    private function buildDictionaries($dictionaries)
    {
        /** 数据字典生成，生成单表单个文件 */
        $php_code = '<?php' . PHP_EOL
        . 'return ' . var_export($dictionaries, true) . ';'
            . PHP_EOL;

        $put = $this->filesystem->put($this->storage_path . 'dictionaries.php', $php_code);

        if ($put)
        {
            return $this->command->info('+ ' . $this->relative_storage_path . 'dictionaries.php (Updated)');
        }

        return $this->command->error('x ' . $this->relative_storage_path . 'dictionaries.php (Failed)');
    }

    private function buildTableList($menus)
    {
        /** 数据首页生成，列表 */
        $php_code = '<?php' . PHP_EOL
        . 'return ' . var_export($menus, true) . ';'
            . PHP_EOL;

        $put = $this->filesystem->put($this->storage_path . 'tables.php', $php_code);

        if ($put)
        {
            return $this->command->info('+ ' . $this->relative_storage_path . 'tables.php (Updated)');
        }

        return $this->command->error('x ' . $this->storage_path . 'tables.php (Failed)');
    }
}
