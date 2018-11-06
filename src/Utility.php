<?php
namespace Charsen\Scaffold;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

/**
 * Laravel Scaffold Utility
 *
 * @author Charsen https://github.com/charsen
 */
class Utility
{
    /**
     * @var string
     */
    protected $prefix = 'scaffold';

    /**
     * @var mixed
     */
    protected $filesystem;

    public function __construct()
    {
        $this->filesystem = new Filesystem();
    }
    
    /**
     * 获取控制器的命令空间列表
     *
     * !!! 只支持 Http/Controllers/ 再往下两级，更深的层级不支持!!!
     *
     * @return array
     */
    public function getControllerNamespaces()
    {
        $base_path = app_path('Http/Controllers/');
        $dirs = $this->filesystem->directories($base_path);
        if (empty($dirs))
        {
            return ['nothing'];
        }
        
        foreach ($dirs as $path)
        {
            $more = $this->filesystem->directories($path);
            $dirs = array_merge($dirs, $more);
        }
        
        foreach ($dirs as &$dir)
        {
            $dir = str_replace($base_path, '', $dir);
        }
        
        return $dirs;
    }
    
    
    /**
     * 获取所有 Schema 文件的名称
     *
     * @return array
     */
    public function getSchemaNames()
    {
        $path  = $this->getDatabasePath('schema');
        $files = $this->filesystem->files($path);
        
        $data  = [];
        foreach ($files as $file)
        {
            if ($file->getBasename('.yaml') != '_fields')
            {
                $data[] = $file->getBasename('.yaml');
            }
        }

        return $data;
    }
    
    /**
     * 获取多语言文件
     *
     * @param string $file_name
     * @param string $language
     * @param bool   $to_string
     *
     * @return mixed
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function getLanguage($file_name = 'validation', $language = 'en', $to_string = false)
    {
        $file = resource_path("lang/{$language}/{$file_name}.php");

        return $to_string ? $this->filesystem->get($file) : $this->filesystem->getRequire($file);
    }
    
    /**
     * 获取多语言文件路径
     *
     * @param string $file_name
     * @param string $language
     *
     * @return string
     */
    public function getLanguagePath($file_name = 'validation', $language = 'en', $relative = false)
    {
        $path = resource_path("lang/{$language}/{$file_name}.php");
    
        return $relative ? str_replace(base_path(), '.', $path) : $path;
    }
    
    /**
     * 获取 schema 文件路径
     *
     * @param null $file_name
     * @param bool $relative
     *
     * @return string
     */
    public function getSchemaPatch($file_name = null, $relative = false)
    {
        $path = $this->getDatabasePath('schema', $relative);

        return $file_name == null ? $path : ($path . $file_name);
    }
    
    /**
     * 检查 api 下的文件是否存在
     *
     * @param        $folder_path
     * @param        $file_name
     * @param string $folder
     *
     * @return string 文件的完整物理路径
     */
    public function isApiFileExist($folder_path, $file_name, $folder = 'schema')
    {
        $file = $this->getApiPath($folder) . $folder_path . '/' . $file_name . '.yaml';
        if (!is_file($file))
        {
            throw new InvalidArgumentException('Invalid File Argument (Not Found).');
        }

        return $file;
    }
    
    /**
     * @param $table_name
     *
     * @return mixed
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function getOneTable($table_name)
    {
        $file = $this->getDatabasePath('storage') . "{$table_name}.php";
    
        if (!is_file($file))
        {
            throw new InvalidArgumentException('Invalid Argument (Not Found).');
        }
        
        return $this->filesystem->getRequire($file);
    }
    
    /**
     * 获取 数据表 数据
     *
     * @return mixed
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function getTables()
    {
        return $this->filesystem->getRequire($this->getDatabasePath('storage') . 'tables.php');
    }
    
    /**
     * 获取资源仓库数据
     *
     * @return mixed
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function getRepositories()
    {
        return $this->filesystem->getRequire($this->getDatabasePath('storage') . 'repositories.php');
    }
    
    /**
     * 获取模型数据
     *
     * @return mixed
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function getModels()
    {
        return $this->filesystem->getRequire($this->getDatabasePath('storage') . 'models.php');
    }
    
    /**
     * 获取控制器数据
     *
     * @param bool $merge_all
     *
     * @return array
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function getControllers($merge_all = true)
    {
        $data = $this->filesystem->getRequire($this->getDatabasePath('storage') . 'controllers.php');
        if (! $merge_all)
        {
            return $data;
        }
        
        $result = [];
        foreach ($data as $schema_file => $controllers)
        {
            foreach ($controllers as $class => $attr)
            {
                $result[$class] = $attr;
            }
        }
        
        return $result;
    }
    
    /**
     * 获取多语言字段数据
     *
     * @return array
     */
    public function getLangFields()
    {
        $yaml      = new Yaml;
        $yaml_data = $yaml::parseFile($this->getDatabasePath('schema') . '_fields.yaml');
        $fields    = isset($yaml_data['append_fields'])
                        ? array_merge($yaml_data['table_fields'], $yaml_data['append_fields'])
                        : $yaml_data['table_fields'];
        
        return $fields;
    }
    
    /**
     * 获取字段数据
     *
     * @return array
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function getFields()
    {
        return $this->filesystem->getRequire($this->getDatabasePath('storage') . 'fields.php');
    }
    
    /**
     * 获取字典数据
     *
     * @param bool $merge_all
     *
     * @return array
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function getDictionaries($merge_all = true)
    {
        $dictionaries = $this->filesystem->getRequire($this->getDatabasePath('storage') . 'dictionaries.php');
        if (!$merge_all)
        {
            return $dictionaries;
        }

        $result = [];
        foreach ($dictionaries as $table_name => $data)
        {
            foreach ($data as $field_name => $attr)
            {
                $result[$field_name] = $attr;
            }
        }

        return $result;
    }
    
    /**
     * 获取字典里的所有词
     *
     * @return array
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function getDictionaryWords()
    {
        $dictionaries = $this->getDictionaries(false);
        $result = [];
        foreach ($dictionaries as $table_name => $data)
        {
            foreach ($data as $field_name => $words)
            {
                foreach ($words as $alias => $attr)
                {
                    $result[$alias] = ['zh-CN' => $attr[2], 'en' => $attr[1]];
                }
            }
        }
    
        return $result;
    }
    
    /**
     * Get Controller Path
     *
     * @param bool $relative
     *
     * @return mixed|string
     */
    public function getControllerPath($relative = false)
    {
        $path = app_path('Http/Controllers/');
        if ($relative)
        {
            $path = str_replace(base_path(), '.', $path);
        }
        
        return $path;
    }
    
    /**
     * 获取 app Repository 的存储目录
     *
     * @return string
     */
    public function getRepositoryFolder()
    {
        return $this->getConfig('repository.path');
    }
    
    /**
     * Get Repository Path
     *
     * @param bool $relative
     *
     * @return string
     */
    public function getRepositoryPath($relative = false)
    {
        $path = base_path($this->getConfig('repository.path'));
    
        return $relative ? str_replace(base_path(), '.', $path) : $path;
    }
    
    /**
     * 获取 app Model 的存储目录
     *
     * @return string
     */
    public function getModelFolder()
    {
        return $this->getConfig('model.path');
    }
    
    /**
     * Get Model Path
     *
     * @param bool $relative
     *
     * @return string
     */
    public function getModelPath($relative = false)
    {
        $path = base_path($this->getConfig('model.path'));
    
        return $relative ? str_replace(base_path(), '.', $path) : $path;
    }
    
    /**
     * Get Migration Path
     *
     * @param bool $relative
     *
     * @return string
     */
    public function getMigrationPath($relative = false)
    {
        $path = database_path('migrations/');
    
        return $relative ? str_replace(base_path(), '.', $path) : $path;
    }
    
    /**
     * Get Scaffold Database Path
     *
     * @param $folder
     * @param $relative
     *
     * @return string
     */
    public function getDatabasePath($folder = 'schema', $relative = false)
    {
        $path = base_path($this->getConfig('database.' . $folder));
        
        return $relative ? str_replace(base_path(), '.', $path) : $path;
    }
    
    /**
     * Get Scaffold Api Path
     *
     * @param $folder
     * @param $relative
     *
     * @return string
     */
    public function getApiPath($folder = 'schema', $relative = false)
    {
        $path = base_path($this->getConfig('api.' . $folder));
    
        return $relative ? str_replace(base_path(), '.', $path) : $path;
    }

    /**
     * Helper to get the config values.
     *
     * @param  string  $key
     * @param  string  $default
     *
     * @return mixed
     */
    public function getConfig($key, $default = null)
    {
        return config("{$this->prefix}.$key", $default);
    }

}
