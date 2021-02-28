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
        $this->updateModelFile($folder, $model_file, $namespace, $filter_class);

        return true;
    }

    /**
     * 更新 model 文件代码
     *
     * @param string $model_file
     * @return void
     */
    public function updateModelFile($folder, $model_file, $namespace, $filter_class)
    {
        $file_code         = $this->filesystem->get($model_file);

        $use_filterable    = 'use EloquentFilter\Filterable;';
        $use_filter_classs = 'use ' . $namespace . '\\' . $filter_class . ';';

        if ( ! strstr($file_code, $use_filterable))
        {
            $target_code = 'use Illuminate\Database\Eloquent\Model;';
            $append_code =  $target_code . "\n" . $use_filterable . "\n" . $use_filter_classs;

            $file_code   = str_replace($target_code, $append_code, $file_code);
        }

        $use_filter_alias  = 'use Filterable;';
        if ( ! strstr($file_code, $use_filter_alias))
        {
            // 要考虑 没有 use trait 的情况
            // preg_match('/use\s[A-Za-z\,\s]*Filterable[A-Za-z\,\s]*\;/', $file_code, $match);
            // var_dump($match);
            preg_match('/extends Model\s*{/', $file_code, $match);
            $target_code = $match[0];
            $append_code = $target_code . "\n" . $this->getTabs(1) . $use_filter_alias;

            $file_code   = str_replace($target_code, $append_code, $file_code);
        }

        if ( ! strstr($file_code, 'public function modelFilter()'))
        {
            // $file_code = trim($file_code, " \t\n\r\0\x0B\}");
            // 在第一个函数前增加 (TODO: 只能通过反向解析类文件来做！！！)
            // preg_match('/(public|private|protected)+\s*function\s+[A-Za-z\s]+\(.*?\}/s', $file_code, $match);
            // var_dump($match);
            // if (empty($match))
            // {
            //     $this->command->warn('Can not append modelFilter function!! ');
            // }
            // else
            // {
            //     $target_code = $match[0];
            //     $append_code = $target_code . "\n" . $this->getTabs(1) . $this->getModelFilterFunction($filter_class);
            //     //$file_code   = str_replace($target_code, $append_code, $file_code);
            // }
        }

        $this->filesystem->put($model_file, $file_code);

        $relative_file = str_replace(base_path(), '', $model_file);
        $this->command->info('+ .' . $relative_file . ' is updated!');
    }

    /**
     * 获取 指定 filter 函数代码
     * @param string $filter_class
     * @return void
     */
    private function getModelFilterFunction($filter_class)
    {
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
