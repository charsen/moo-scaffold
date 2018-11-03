<?php
namespace Charsen\Scaffold\Generator;

use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

/**
 * Create Repository
 *
 * @author Charsen <780537@gmail.com>
 */
class CreateRepositoryGenerator extends Generator
{

    /**
     * @var mixed
     */
    protected $repository_path;
    /**
     * @var mixed
     */
    protected $repository_relative_path;
    /**
     * @var mixed
     */
    protected $repository_folder;
    protected $model_path;
    protected $model_folder;

    /**
     * @param $schema_name
     * @param $force
     */
    public function start($schema_name, $force = false)
    {
        $this->repository_path          = $this->utility->getRepositoryPath();
        $this->repository_relative_path = $this->utility->getRepositoryPath(true);
        $this->repository_folder        = $this->utility->getConfig('repository.path');
        $this->model_path               = $this->utility->getModelPath();
        $this->model_folder             = $this->utility->getConfig('model.path');

        // 从 storage 里获取 表名列表，在修改了 schema 后忘了执行 scaffold:fresh 的话会不准确！！
        $all = $this->utility->getRepositories();

        if (!isset($all[$schema_name]))
        {
            throw new InvalidArgumentException(sprintf('Schema File "%s" could not be found.', $schema_name));
        }
        //var_dump($all[$schema_name]);

        foreach ($all[$schema_name] as $repository_class => $attr)
        {
            $table_attrs = $this->utility->getOneTable($attr['table_name']);

            $repository_file          = $this->repository_path . "{$repository_class}Repository.php";
            $repository_relative_file = $this->repository_relative_path . "{$repository_class}Repository.php";
            if ($this->filesystem->isFile($repository_file) && !$force)
            {
                $this->command->error('x Model is existed (' . $repository_relative_file . ')');
                continue;
            }

            $fields           = $table_attrs['fields'];
            $dictionaries     = $table_attrs['dictionaries'];
            $dictionaries_ids = $this->rebuildDictionaries($dictionaries);
            //var_dump($dictionaries_id);

            // 目录及 namespace 处理
            $original_class       = $repository_class;
            $repository_namespace = $this->dealNameSpaceAndPath($this->repository_path, $this->repository_folder, $repository_class);
            $model_namespace      = $this->dealNameSpaceAndPath($this->model_path, $this->model_folder, $attr['model_class']);

            $meta = [
                'namespace' => $repository_namespace,
                'use_model' => 'use App\\' . $model_namespace . '\\'. $attr['model_class'] . ';',
                'class'     => $repository_class,
                'rules'     => $this->buildRules($fields, $dictionaries_ids),
            ];

            $this->filesystem->put($repository_file, $this->compileStub($meta));
            $this->command->info('+ ' . $repository_relative_file);

            // Interface
            $repository_interface_file          = $this->repository_path . "{$original_class}RepositoryInterface.php";
            $repository_relative_interface_file = $this->repository_relative_path . "{$original_class}RepositoryInterface.php";
            $this->filesystem->put($repository_interface_file, $this->compileInterfaceStub($meta));
            $this->command->info('+ ' . $repository_relative_interface_file);
        }
    }

    /**
     * 生成 检验规
     *
     * @param  array  $fields
     * @param  array  $dictionaries [description]
     * @return string
     */
    private function buildRules(array $fields, array $dictionaries_ids)
    {
        $rules = $this->rebuildFieldsRules($fields, $dictionaries_ids);
        //var_dump($rules);

        $create_code = ["'create' => ["];
        $update_code = [$this->getTabs(2) . "'update' => ["];

        foreach ($rules as $field_name => $rule)
        {
            $create_code[] = $this->getTabs(3) . "'{$field_name}' => '{$rule}',";
            $update_code[] = $this->getTabs(3) . "'{$field_name}' => 'sometimes|{$rule}',";
        }

        $create_code[] = $this->getTabs(2) . '],';
        $update_code[] = $this->getTabs(2) . '],';

        return implode("\n", array_merge($create_code, $update_code));
    }

    /**
     * 重建 字段的规则
     *
     * @param  array  $fields
     * @return array
     */
    private function rebuildFieldsRules(array $fields, array $dictionaries_ids)
    {
        $rules = [];
        foreach ($fields as $field_name => $attr)
        {
            if (in_array($field_name, ['id', 'deleted_at', 'created_at', 'updated_at']))
            {
                continue;
            }
            $filed_rules = [];
            if ($attr['require'])
            {
                $filed_rules[] = 'required';
            }
            if ($attr['allow_null'])
            {
                $filed_rules[] = 'nullable';
            }
            if ($attr['type'] == 'date' || $attr['type'] == 'datetime')
            {
                $filed_rules[] = 'date';
            }
            if (isset($attr['min_size']))
            {
                $filed_rules[] = 'min:' . $attr['min_size'];
            }
            if (isset($attr['size']))
            {
                $filed_rules[] = 'max:' . $attr['size'];
            }

            if (isset($dictionaries_ids[$field_name]))
            {
                $filed_rules[] = 'in:' . implode(',', $dictionaries_ids[$field_name]);
            }

            $rules[$field_name] = implode('|', $filed_rules);
        }

        return $rules;
    }

    /**
     * 重建 数据字典
     * @param  array  $dictionaries [description]
     * @return [type]               [description]
     */
    private function rebuildDictionaries(array $dictionaries)
    {
        $data = [];
        foreach ($dictionaries as $field_name => $rows)
        {
            foreach ($rows as $one)
            {
                $data[$field_name][] = $one[0];
            }
        }

        return $data;
    }

    /**
     * 编译模板
     *
     * @return string
     */
    private function compileStub($meta)
    {
        return $this->buildStub($meta, $this->getStub('repository'));
    }

    /**
     * 编译模板
     *
     * @return string
     */
    private function compileInterfaceStub($meta)
    {
        return $this->buildStub($meta, $this->getStub('repository-interface'));
    }
}
