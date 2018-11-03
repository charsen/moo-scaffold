<?php
namespace Charsen\Scaffold\Generator;

use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

/**
 * Create Model
 *
 * @author Charsen <780537@gmail.com>
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
     * @param $schema_name
     * @param $force
     */
    public function start($schema_name, $force = false)
    {
        $this->model_path          = $this->utility->getModelPath();
        $this->model_relative_path = $this->utility->getModelPath(true);
        $this->model_folder        = $this->utility->getConfig('model.path');

        // 从 storage 里获取 表名列表，在修改了 schema 后忘了执行 scaffold:fresh 的话会不准确！！
        $all = $this->utility->getModels();

        if (!isset($all[$schema_name]))
        {
            throw new InvalidArgumentException(sprintf('Schema File "%s" could not be found.', $schema_name));
        }
        //var_dump($all[$schema_name]);

        foreach ($all[$schema_name] as $class => $attr)
        {
            $table_attr = $this->utility->getOneTable($attr['table_name']);

            $model_file          = $this->model_path . "{$class}.php";
            $model_relative_file = $this->model_relative_path . "{$class}.php";
            if ($this->filesystem->isFile($model_file) && !$force)
            {
                $this->command->error('x Model is existed (' . $model_relative_file . ')');
                continue;
            }

            $fields            = $table_attr['fields'];
            $dictionaries      = $table_attr['dictionaries'];
            $use_trait         = [];
            $user_soft_deletes = '';
            //var_dump($table_attr['fields']);

            $dictionaries_code = $this->buildDictionaries($dictionaries, $fields);

            // 软删除
            if (isset($fields['deleted_at']))
            {
                $use_trait[]       = 'SoftDeletes';
                $user_soft_deletes = 'use Illuminate\Database\Eloquent\SoftDeletes;';
            }

            // 目录及 namespace 处理
            $namespace = $this->dealNameSpaceAndPath($this->model_path, $this->model_folder, $class);

            $meta = [
                'namespace'         => $namespace,
                'user_soft_deletes' => $user_soft_deletes,
                'use_trait'         => !empty($use_trait) ? 'use ' . implode(', ', $use_trait) . ';' : '',
                'class'             => $class,
                'class_name'        => $table_attr['name'] . '模型',
                'table_name'        => $attr['table_name'],
                'dictionaries'      => $dictionaries_code['dictionaries'],
                'hidden'            => '',
                'appends'           => $dictionaries_code['appends'],
                'fillable'          => $this->buildFillable($fields),
                'dates'             => $this->buildDates($fields),
                'get_txt_attribute' => $dictionaries_code['get_txt_attribute'],
            ];

            $this->filesystem->put($model_file, $this->compileStub($meta));
            $this->command->info('+ ' . $model_relative_file);
        }
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
        $data_code     = [];
        $appends_code  = [];
        $function_code = [];

        foreach ($dictionaries as $filed_name => $attr)
        {
            // 字典数据
            $data_code[] = $this->getTabs(1) . '/**';
            $data_code[] = $this->getTabs(1) . " * 设置 {$fields[$filed_name]['name']} 具体值";
            $data_code[] = $this->getTabs(1) . ' * @var array';
            $data_code[] = $this->getTabs(1) . ' */';
            $data_code[] = $this->getTabs(1) . "public \$init_{$filed_name} = [";
            foreach ($attr as $alias => $one)
            {
                $data_code[] = $this->getTabs(2) . "'{$one[0]}' => '{$alias}',";
            }
            $data_code[] = $this->getTabs(1) . '];';
            $data_code[] = ''; //空一行

            // 附加字段
            $appends_code[] = "'{$filed_name}_txt'";

            // 附加字段值获取函数
            $function_name   = str_replace(' ', '', ucwords(str_replace('_', ' ', $filed_name)));
            $function_code[] = $this->getTabs(1) . '/**';
            $function_code[] = $this->getTabs(1) . " * 获取 {$fields[$filed_name]['name']} TXT";
            $function_code[] = $this->getTabs(1) . ' * @var string';
            $function_code[] = $this->getTabs(1) . ' */';
            $function_code[] = $this->getTabs(1) . "public function get{$function_name}TxtAttribute()";
            $function_code[] = $this->getTabs(1) . '{';
            $function_code[] = $this->getTabs(2) . "if (\$this->{$filed_name} !== NULL)";
            $function_code[] = $this->getTabs(2) . '{';
            $function_code[] = $this->getTabs(3) . "return __('model.' . \$this->init_{$filed_name}}[\$this->{$filed_name}]);";
            $function_code[] = $this->getTabs(2) . '}';
            $function_code[] = '';
            $function_code[] = $this->getTabs(2) . "return '';";
            $function_code[] = $this->getTabs(1) . '}';
            $function_code[] = ''; //空一行
        }

        return [
            'dictionaries'      => implode("\n", $data_code),
            'appends'           => implode(', ', $appends_code),
            'get_txt_attribute' => implode("\n", $function_code),
        ];
    }
    
    /**
     * 编译模板
     *
     * @param $meta
     *
     * @return string
     */
    private function compileStub($meta)
    {
        return $this->buildStub($meta, $this->getStub('model'));
    }
}
