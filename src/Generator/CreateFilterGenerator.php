<?php
namespace Charsen\Scaffold\Generator;

use Symfony\Component\Yaml\Yaml;

/**
 * Create Model Filter
 *
 * @author Charsen https://github.com/charsen
 */
class CreateFilterGenerator extends Generator
{
    protected $files_path;

    /**
     * @param      $model_name
     * @param bool $force
     *
     * @throws \ReflectionException
     */
    public function start($folder, $model_name, $force = false)
    {
        $model_path = $this->utility->getModelPath();
        $model_file = $model_path . $folder . '/' . $model_name . '.php';
        // var_dump($model_file);

        if (empty($folder) OR empty($model_name) OR ! $this->filesystem->isFile($model_file))
        {
            return $this->command->error("Model File \"{$model_file}\" could not be found.");
        }

        $namespace       = $this->utility->getConfig('model.path') . $folder . '/Filters';
        $namespace       = ucfirst(str_replace('/', '\\', $namespace));
        $filter_class    = $model_name . 'Filter';

        $this->buildFilter($model_path, $folder, $model_name, $namespace, $filter_class);
        $this->updateModelFile($folder, $model_file, $namespace, $model_name, $filter_class);

        return true;
    }

    /**
     * 更新 model 文件代码
     *
     * @param string $model_file
     * @return void
     */
    public function updateModelFile($folder, $model_file, $namespace, $model_name, $filter_class)
    {
        $file_code         = $this->filesystem->get($model_file);

        $is_updated        = false;
        $use_filterable    = 'use EloquentFilter\Filterable;';
        $use_filter_classs = 'use ' . $namespace . '\\' . $filter_class . ';';

        if ( ! strstr($file_code, $use_filterable))
        {
            $is_updated  = true;
            $target_code = 'use Illuminate\Database\Eloquent\Model;';
            $append_code =  $target_code . "\n" . $use_filterable . "\n" . $use_filter_classs;

            $file_code   = str_replace($target_code, $append_code, $file_code);
        }

        $use_filter_alias  = 'use Filterable;';
        if ( ! strstr($file_code, $use_filter_alias))
        {
            // 要考虑 没有 use trait 的情况
            if ( ! preg_match('/use\s[A-Za-z\,\s]*Filterable[A-Za-z\,\s]*\;/', $file_code, $match))
            {
                $is_updated  = true;
                preg_match('/extends Model\s*{/', $file_code, $match);
                $target_code = $match[0];
                $append_code = $target_code . "\n" . $this->getTabs(1) . $use_filter_alias;

                $file_code   = str_replace($target_code, $append_code, $file_code);
            }
        }

        // 先写入文件，再用 file() 方法添加 modelFilter 到倒数第二行
        $this->filesystem->put($model_file, $file_code);

        // 添加 modelFilter()
        if ( ! strstr($file_code, 'public function modelFilter()'))
        {
            $is_updated  = true;
            $file_code                  = \file($model_file);
            $count_line                 = count($file_code);
            $file_code[$count_line - 1] = $this->getModelFilterFunction($filter_class) . "\n\n}\n";

            $this->filesystem->put($model_file, $file_code);
        }

        $relative_file = str_replace(base_path(), '', $model_file);
        if ($is_updated)
        {
            $this->command->info('+ .' . $relative_file . ' is updated!');
        }
        else
        {
            $this->command->warn('.' . $relative_file . ' is nothing changed!');
        }
    }

    /**
     * 获取 指定 filter 函数代码
     * @param string $filter_class
     * @return void
     */
    private function getModelFilterFunction($filter_class)
    {
        $data_code[] = "\n";
        $data_code[] = $this->getTabs(1) . '/**';
        $data_code[] = $this->getTabs(1) . " * 指定 Filter";
        $data_code[] = $this->getTabs(1) . ' */';

        $data_code[] = $this->getTabs(1) . ' public function modelFilter()';
        $data_code[] = $this->getTabs(1) . ' {';
        $data_code[] = $this->getTabs(2) . ' return $this->provideFilter(' . $filter_class . '::class);';
        $data_code[] = $this->getTabs(1) . ' }';

        return implode("\n", $data_code);
    }

    /**
     * 生成 model 的 filter 文件
     *
     * @param string $model_path
     * @param string $folder
     * @param string $model_name
     * @param string $filter_class
     * @return boolean
     */
    public function buildFilter($model_path, $folder, $model_name, $namespace, $filter_class)
    {
        // 检查目录是否存在，不存在则创建
        $filter_path = $model_path . $folder . '/Filters/';
        if (!$this->filesystem->isDirectory($filter_path))
        {
            $this->filesystem->makeDirectory($filter_path, 0777, true, true);
        }

        $filter_file  = $filter_path . $model_name . 'Filter.php';
        $relative_file = str_replace(base_path(), '', $filter_file);
        // 检查文件是否存在，不存在则创建
        if ( ! $this->filesystem->isFile($filter_file))
        {
            $base_filter     = $this->utility->getConfig('model.path') . 'Filters\BaseFilter';
            $use_base_filter = ucfirst(str_replace('/', '\\', $base_filter));

            // TODO: 解析 request index 的规则，生成对应函数

            $data            = [
                'namespace'       => $namespace,
                'use_base_filter' => $use_base_filter,
                'class_name'      => $filter_class
            ];

            $content = $this->buildStub($data, $this->getStub('model-filter'));
            $this->filesystem->put($filter_file, $content);

            $this->command->info('+ .' . $relative_file . ' is created!');
        }
        else
        {
            $this->command->warn('. ' . $relative_file . ' is existed!');
        }

        return true;
    }

    /**
     * 检查 BaseFilter 是否存在，不存在则创建
     * @return void
     */
    public function buildBaseFilter()
    {
        $path       = $this->utility->getFilterPath();
        $base_file  = $path . 'BaseFilter.php';

        // 检查目录是否存在，不存在则创建
        if (!$this->filesystem->isDirectory($path))
        {
            $this->filesystem->makeDirectory($path, 0777, true, true);
        }

        // 检查文件是否存在，不存在则创建
        if ( ! $this->filesystem->isFile($base_file))
        {
            $namespace = $this->utility->getConfig('model.path') . 'Filters';
            $data      = [
                'namespace' => ucfirst(str_replace('/', '\\', $namespace))
            ];

            $content = $this->buildStub($data, $this->getStub('model-base-filter'));
            $this->filesystem->put($base_file, $content);

            $this->command->info('+ ' . $this->utility->getFilterPath(true) . 'BaseFilter.php');
        }

        return true;
    }
}
