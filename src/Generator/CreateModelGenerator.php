<?php
namespace Charsen\Scaffold\Generator;


/**
 * Create Model
 *
 * @author Charsen https://github.com/charsen
 */
class CreateModelGenerator extends Generator
{

    /**
     * @var mixed
     */
    protected $model_path;
    /**
     * @var mixed
     */
    protected $model_relative_path;
    /**
     * @var mixed
     */
    protected $model_folder;

    /**
     * @param      $schema_name
     * @param bool $force
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function start($schema_name, $force = false)
    {
        $this->model_path          = $this->utility->getModelPath();
        $this->model_relative_path = $this->utility->getModelPath(true);
        $this->model_folder        = $this->utility->getConfig('model.path');

        // 从 storage 里获取模型数据，在修改了 schema 后忘了执行 scaffold:fresh 的话会不准确！！
        $all = $this->filesystem->getRequire($this->utility->getStoragePath() . 'models.php');

        if (!isset($all[$schema_name]))
        {
            $this->command->error("Schema File \"{$schema_name}\" could not be found.");
            return false;
        }

        foreach ($all[$schema_name] as $class => $attr)
        {
            $model_file          = $this->model_path . "{$class}.php";
            $model_relative_file = $this->model_relative_path . "{$class}.php";
            if ($this->filesystem->isFile($model_file) && ! $force)
            {
                $this->command->error('x Model is existed (' . $model_relative_file . ')');
                continue;
            }

            $table_attr        = $this->utility->getOneTable($attr['table_name']);
            $fields            = $table_attr['fields'];
            $dictionaries      = $table_attr['dictionaries'];
            $hidden            = [];
            $use_trait         = [];
            $use_class         = [];

            // 数据字典代码
            $dictionaries_code      = $this->buildDictionaries($dictionaries, $fields);

            $casts_code             = $this->buildCasts($fields);
            $get_intval_attribute   = $this->buildIntvalAttribute($fields);

            // 软删除
            if (isset($fields['deleted_at']))
            {
                $use_trait[]   = 'SoftDeletes';
                $use_class[]   = 'use Illuminate\Database\Eloquent\SoftDeletes;';
                $hidden[]      = "'deleted_at'";
            }

            // 目录及 namespace 处理
            $namespace = $this->dealNameSpaceAndPath($this->model_path, $this->model_folder, $class);

            $meta = [
                'author'                => $this->utility->getConfig('author'),
                'date'                  => date('Y-m-d H:i:s'),
                'namespace'             => ucfirst($namespace),
                'use_class'             => implode("\n", $use_class),
                'use_trait'             => ! empty($use_trait) ? 'use ' . implode(', ', $use_trait) . ';' : '',
                'class'                 => $class,
                'class_name'            => $table_attr['name'] . '模型',
                'table_name'            => $attr['table_name'],
                'dictionaries'          => $dictionaries_code['dictionaries'],
                'casts'                 => $casts_code,
                'appends'               => $this->buildAppends($dictionaries_code['appends']),
                'hidden'                => $this->buildHidden($hidden),
                'fillable'              => $this->buildFillable($fields),
                'dates'                 => $this->buildDates($fields),
                'get_txt_attribute'     => $dictionaries_code['get_txt_attribute'],
                'get_intval_attribute'  => $get_intval_attribute,
            ];

            $this->filesystem->put($model_file, $this->compileStub($meta));
            $this->command->info('+ ' . $model_relative_file);
        }
    }

    /**
     * 生成隐藏属性
     *
     * @param $hidden
     *
     * @return string
     */
    private function buildHidden($hidden)
    {
        if (empty($hidden))
        {
            return '';
        }

        $code = [
            $this->getTabs(1) . '/**',
            $this->getTabs(1) . ' * 数组中的属性会被隐藏',
            $this->getTabs(1) . ' * @var array',
            $this->getTabs(1) . ' */',
            $this->getTabs(1) . "protected \$hidden = [" . implode(',', $hidden) . "];",
            '', //空一行
        ];

        return implode("\n", $code);
    }


    /**
     * 生成附加属性
     *
     * @param $appends
     *
     * @return string
     */
    private function buildAppends($appends)
    {
        if (empty($appends))
        {
            return '';
        }

        $code = [
            $this->getTabs(1) . '/**',
            $this->getTabs(1) . ' * 追加到模型数组表单的访问器',
            $this->getTabs(1) . ' * @var array',
            $this->getTabs(1) . ' */',
            $this->getTabs(1) . "protected \$appends = [" . implode(', ', $appends) . "];",
            '', //空一行
        ];

        return implode("\n", $code);
    }

    /**
     * 生成 原生类型的属性
     *
     * @param array $fields
     *
     * @return string
     */
    private function buildCasts(array $fields)
    {
        $code = [];

        foreach ($fields as $field_name => $attr)
        {
            if ($attr['type'] == 'boolean')
            {
                $code[] = $this->getTabs(2) . "'{$field_name}' => 'boolean',";
            }
            // todo: 转换更多类型
        }
        if (empty($code))
        {
            return '';
        }
        $code[] = $this->getTabs(1) . '];';
        $code[] = '';

        $temp = [
            $this->getTabs(1) . '/**',
            $this->getTabs(1) . ' * 应该被转换成原生类型的属性',
            $this->getTabs(1) . ' * @var array',
            $this->getTabs(1) . ' */',
            $this->getTabs(1) . 'protected $casts = [',
        ];

        return implode("\n", array_merge($temp, $code));
    }

    /**
     * 生成 整形转浮点数处理函数
     *
     * @param array $fields
     *
     * @return string
     */
    private function buildIntvalAttribute(array $fields)
    {
        $code = [];

        foreach ($fields as $field_name => $attr)
        {
            if (isset($attr['format']) && strstr($attr['format'], 'intval:'))
            {
                list($intval, $divisor) = explode(':', trim($attr['format']));
                $function_name = str_replace(' ', '', ucwords(str_replace('_', ' ', $field_name)));

                $code[] = $this->getTabs(1) . '/**';
                $code[] = $this->getTabs(1) . " * {$fields[$field_name]['name']} 浮点数转整数 互转";
                $code[] = $this->getTabs(1) . ' */';
                $code[] = $this->getTabs(1) . "public function set{$function_name}Attribute(\$value)";
                $code[] = $this->getTabs(1) . "{";
                $code[] = $this->getTabs(2) . "\$this->attributes['{$field_name}'] = intval(\$value * {$divisor});";
                $code[] = $this->getTabs(1) . "}";
                $code[] = $this->getTabs(1) . "public function get{$function_name}Attribute()";
                $code[] = $this->getTabs(1) . "{";
                $code[] = $this->getTabs(2) . "return \$this->attributes['{$field_name}'] / {$divisor};";
                $code[] = $this->getTabs(1) . "}";
                $code[] = '';
            }
        }

        return implode("\n", $code);
    }

    /**
     * 生成 dates 代码
     * @param  array  $fields
     * @return string
     */
    private function buildDates(array $fields)
    {
        $code = [];

        foreach ($fields as $field_name => $attr)
        {
            if ($field_name == 'deleted_at')
            {
                continue;
            }

            if (in_array($attr['type'], ['date', 'datetime', 'timestamp', 'time']))
            {
                $code[] = "'{$field_name}'";
            }
        }

        return implode(', ', $code);
    }

    /**
     * 生成 fillable 代码
     *
     * @param  array  $fields
     * @return string
     */
    private function buildFillable(array $fields)
    {
        $code = [];

        foreach ($fields as $field_name => $attr)
        {
            if (in_array($field_name, ['id', 'deleted_at', 'created_at', 'updated_at']))
            {
                continue;
            }
            $code[] = "'{$field_name}'";
        }

        return implode(', ', $code);
    }

    /**
     * 生成字典相关的代码，字典数据，附加字段，附加字段值获取函数
     *
     * @param  array  $dictionaries
     * @param  array  $fields
     * @return array
     */
    private function buildDictionaries(array $dictionaries, array $fields)
    {
        $data_code     = ['']; // 先空一行
        $appends_code  = [];
        $function_code = [];

        foreach ($dictionaries as $field_name => $attr)
        {
            // 字典数据
            $data_code[] = $this->getTabs(1) . '/**';
            $data_code[] = $this->getTabs(1) . " * 设置 {$fields[$field_name]['name']} 具体值";
            $data_code[] = $this->getTabs(1) . ' * @var array';
            $data_code[] = $this->getTabs(1) . ' */';
            $data_code[] = $this->getTabs(1) . "public \$init_{$field_name} = [";
            foreach ($attr as $alias => $one)
            {
                $data_code[] = $this->getTabs(2) . "'{$one[0]}' => '{$alias}',";
            }
            $data_code[] = $this->getTabs(1) . '];';
            $data_code[] = ''; //空一行

            // 附加字段
            $appends_code[] = "'{$field_name}_txt'";

            // 附加字段值获取函数
            $function_name   = str_replace(' ', '', ucwords(str_replace('_', ' ', $field_name)));
            $function_code[] = $this->getTabs(1) . '/**';
            $function_code[] = $this->getTabs(1) . " * 获取 {$fields[$field_name]['name']} TXT";
            $function_code[] = $this->getTabs(1) . ' * @return array|null|string';
            $function_code[] = $this->getTabs(1) . ' */';
            $function_code[] = $this->getTabs(1) . "public function get{$function_name}TxtAttribute()";
            $function_code[] = $this->getTabs(1) . '{';
            $function_code[] = $this->getTabs(2) . "if (\$this->{$field_name} !== NULL)";
            $function_code[] = $this->getTabs(2) . '{';
            $function_code[] = $this->getTabs(3) . "return __('model.' . \$this->init_{$field_name}[\$this->{$field_name}]);";
            $function_code[] = $this->getTabs(2) . '}';
            $function_code[] = '';
            $function_code[] = $this->getTabs(2) . "return '';";
            $function_code[] = $this->getTabs(1) . '}';
            $function_code[] = ''; //空一行
        }

        return [
            'dictionaries'      => implode("\n", $data_code),
            'appends'           => $appends_code,
            'get_txt_attribute' => implode("\n", $function_code),
        ];
    }

    /**
     * 编译模板
     *
     * @param $meta
     *
     * @return string
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    private function compileStub($meta)
    {
        return $this->buildStub($meta, $this->getStub('model'));
    }
}
