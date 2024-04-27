<?php

namespace Mooeen\Scaffold\Generator;

/**
 * Create Database Migration
 *
 * @author Charsen https://github.com/charsen
 */
class CreateMigrationGenerator extends Generator
{
    protected string $migration_path;

    protected string $migration_relative_path;

    /**
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function start($schema_name): bool
    {
        $this->migration_path          = $this->utility->getMigrationPath();
        $this->migration_relative_path = $this->utility->getMigrationPath(true);

        // 从 storage 里获取 表名列表，在修改了 schema 后忘了执行 moo:db:fresh 的话会不准确！！
        $all = $this->utility->getTables();

        if (! isset($all[$schema_name])) {
            $this->command->error("x Schema File \"{$schema_name}\" could not be found.");

            return false;
        }

        // 模块下的所有表格
        $tables = $all[$schema_name]['tables'];
        //var_dump($tables);
        foreach ($tables as $table_name => $table) {
            if (($exist_name = $this->checkExist("create_{$table_name}_table")) !== false) {
                $this->command->error('x Migration is existed (' . $exist_name . ')');

                continue;
            }

            $migration_name = date('Y_m_d_H0000') . "_create_{$table_name}_table.php";
            $migration_file = $this->migration_path . $migration_name;

            $meta = [
                'author'       => $this->utility->getConfig('author'),
                'date'         => date('Y-m-d H:i:s'),
                'migrant_name' => $table['name'],
                'migrant_desc' => $table['desc'],
                'schema_up'    => $this->getUpCode($table_name),
                'schema_down'  => $this->getDownCode($table_name),
            ];

            $this->filesystem->put($migration_file, $this->compileStub($meta));
            $this->command->info('+ ' . $this->migration_relative_path . $migration_name);
        }

        return true;
    }

    /**
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    private function getUpCode(string $table_name): string
    {
        $code = ["Schema::create('{$table_name}', static function (Blueprint \$table) {"];

        $code   = $this->buildCreateCode($table_name, $code);
        $code[] = $this->getTabs(2) . '});';

        return implode("\n", $code);
    }

    /**
     * 生成字段相关代码
     *
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    private function buildCreateCode(string $table_name, array $code): array
    {
        $table  = $this->utility->getOneTable($table_name);
        $index  = $table['index'];
        $fields = $table['fields'];

        $fields_code = $this->buildFieldsCode($fields, $index);
        $index_code  = $this->buildIndexCode($index);

        return array_merge($code, $fields_code, $index_code);
    }

    /**
     * 生成字段代码
     */
    private function buildFieldsCode(array $fields, array $index): array
    {
        $code = [];
        if (isset($fields['id'])) {
            if ($fields['id']['type'] === 'bigint') {
                if ($this->utility->getConfig('snow_flake_id')) {
                    $code[] = $this->getTabs(3) . "\$table->unsignedBigInteger('id')->primary();";
                } else {
                    $code[] = $this->getTabs(3) . "\$table->unsignedBigInteger('id', true)->primary();";
                }
            } else {
                $code[] = $this->getTabs(3) . "\$table->unsignedInteger('id', true)->primary();";
            }
            unset($fields['id'], $index['id']);
        }

        $temp_code = [];
        if (isset($fields['deleted_at'])) {
            $temp_code[] = $this->getTabs(3) . '$table->softDeletes();';
            unset($fields['deleted_at']);
        }

        if (isset($fields['updated_at'], $fields['created_at'])) {
            $temp_code[] = $this->getTabs(3) . '$table->timestamps();';
            unset($fields['updated_at'], $fields['created_at']);
        }

        $templates = [
            'char'       => "\$table->char('{{name}}', {{size}})",
            'varchar'    => "\$table->string('{{name}}', {{size}})",
            'text'       => "\$table->text('{{name}}')",
            'mediumText' => "\$table->mediumText('{{name}}')",
            'int'        => "\$table->integer('{{name}}')",
            'tinyint'    => "\$table->tinyInteger('{{name}}')",
            'bigint'     => "\$table->bigInteger('{{name}}')",
            'date'       => "\$table->date('{{name}}')",
            'dateTime'   => "\$table->dateTime('{{name}}')",
            'timestamp'  => "\$table->timestamp('{{name}}')",
            'time'       => "\$table->time('{{name}}')",
            'boolean'    => "\$table->boolean('{{name}}')",
            'binary'     => "\$table->binary('{{name}}')",
            'jsonb'      => "\$table->jsonb('{{name}}')",
            'json'       => "\$table->json('{{name}}')",
            'decimal'    => "\$table->decimal('{{name}}', {{size}}, {{precision}})",
            'double'     => "\$table->double('{{name}}', {{size}}, {{precision}})",
            'float'      => "\$table->float('{{name}}', {{size}}, {{precision}})",
        ];

        foreach ($fields as $name => $attr) {
            $one = $templates[$attr['type']];
            $one = str_replace('{{name}}', $name, $one);
            if (isset($attr['size'])) {
                $one = str_replace('{{size}}', $attr['size'], $one);
            }
            if (isset($attr['precision'])) {
                $one = str_replace('{{precision}}', $attr['precision'], $one);
            }

            $one .= (isset($attr['unsigned']) && $attr['unsigned']) ? '->unsigned()' : '';

            if ($attr['allow_null']) {
                $one .= '->nullable()';
            } else {
                // if (in_array($attr['type'], ['tinyint', 'int', 'bigint', 'decimal']))
                // {
                //     $one .= isset($attr['default']) ? "->default({$attr['default']})" : '';
                // }
                // elseif (in_array($attr['type'], ['char', 'varchar', 'text']))
                // {
                //     $one .= isset($attr['default']) ? "->default('{$attr['default']}')" : '';
                // }
                //$one .= isset($attr['default']) ? "->default({$attr['default']})" : '';

                // 重要，不然 mysql 会自动加入 当前时间，并且设定为on update 时自动修改时间
                if ($attr['type'] === 'timestamp') {
                    $one .= '->useCurrent()';
                }
            }

            if (isset($attr['default'])) {
                $one .= "->default({$attr['default']})";
            }

            $one .= "->comment('{$attr['name']}');";

            $code[] = $this->getTabs(3) . $one;
        }

        return array_merge($code, $temp_code);
    }

    /**
     * 生成索引代码
     */
    private function buildIndexCode(array $index): array
    {
        unset($index['id']);
        if (empty($index)) {
            return [];
        }

        $code = ['']; //空一行
        foreach ($index as $name => $attr) {
            $functions = [
                'primary' => '$table->primary(',
                'index'   => '$table->index(',
                'unique'  => '$table->unique(',
            ];

            if (str_contains($attr['fields'], ',')) {
                $fields_string = [];
                foreach (explode(',', $attr['fields']) as $value) {
                    $fields_string[] = "'" . trim($value) . "'";
                }
                $index_name = implode('_', array_map(static function ($item) {
                    return trim($item, '\'');
                }, $fields_string));
                $code[] = $this->getTabs(3) . $functions[$attr['type']] . '[' . implode(', ', $fields_string) . "], '{$index_name}');";
            } else {
                $code[] = $this->getTabs(3) . $functions[$attr['type']] . "'{$attr['fields']}', '{$attr['fields']}');";
            }
        }

        return $code;
    }

    /**
     * 获取回滚代码
     */
    private function getDownCode($table_name): string
    {
        $code = [
            "Schema::dropIfExists('{$table_name}');",
        ];

        return implode("\n", $code);
    }

    /**
     * 编译模板
     */
    private function compileStub(array $meta): string
    {
        return $this->buildStub($meta, $this->getStub('migration'));
    }

    /**
     * 检查 表的迁移 是否存在
     */
    private function checkExist($name): bool|string
    {
        $exist           = false;
        $migration_files = $this->filesystem->allFiles($this->migration_path);

        foreach ($migration_files as $file) {
            $file      = $file->getPathname();
            $file_name = basename($file);
            if (str_contains($file_name, $name)) {
                $exist = $file_name;
            }
        }

        return $exist;
    }
}
