<?php
namespace Charsen\Scaffold;

use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;
use Illuminate\Filesystem\Filesystem;

/**
 * Laravel Scaffold Utility
 *
 * @author Charsen https://github.com/charsen
 */
class Utility
{

    /**
     * @var mixed
     */
    protected $filesystem;

    public function __construct()
    {
        $this->filesystem = new Filesystem();
    }

    /**
     * 添加 git ignore 文件
     *
     * @param $command
     *
     * @return mixed
     */
    public function addGitIgnore($command)
    {
        $file    = storage_path('scaffold/') . '.gitignore';
        if ( ! $this->filesystem->isFile($file))
        {
            $this->filesystem->put($file, '*' . PHP_EOL . '!.gitignore');
            $relative_file = str_replace(base_path(), '', $file);
            $command->info('+ .' . $relative_file);
        }
    }

    /**
     * 去除接口文档中动作名带有的请示方法后缀
     *
     * @param $action
     *
     * @return mixed
     */
    public function removeActionNameMethod($action)
    {
        if (is_array($action))
        {
            foreach ($action as &$val)
            {
                $val = str_replace(['_get', '_post', '_delete', '_put', '_patch', '_head'], '', $val);
            }

            return $action;
        }

        return str_replace(['_get', '_post', '_delete', '_put', '_patch', '_head'], '', $action);
    }

    /**
     * 根据语言解析
     *
     * @param $string
     *
     * @return array
     */
    public function parseByLanguages($string)
    {
        $languages  = $this->getConfig('languages');
        $data       = [];
        foreach ($languages as $lang)
        {
            $string = str_replace("'", "&apos;", $string);
            preg_match('/'. $lang .':([^\|]*)[\|}]/i', $string, $temp);
            $data[$lang] = empty($temp) ? '' : trim($temp[1]);
        }

        return $data;
    }

     /**
     * 获取 action key 值
     *
     * @param string $controller
     * @param string  $action
     * @return string
     */
    public function getActionKey($controller, $action)
    {
        $controller  = str_replace(['\\', 'App-Http-Controllers-', 'Controller'], ['-', '', ''], $controller);

        return strtolower($controller . '-' . $action);
    }

    /**
     * 解析 包名、模块名、控制器名
     *
     * @param $reflection_class
     *
     * @return array
     */
    public function parsePMCNames($reflection_class)
    {
        $data               = [];
        $doc_comment        = $reflection_class->getDocComment();

        preg_match('/@package\_name\s(.*)\n/', $doc_comment, $package_name);
        preg_match('/@module\_name\s(.*)\n/', $doc_comment, $module_name);
        preg_match('/@controller\_name\s(.*)\n/', $doc_comment, $controller_name);

        $package_name               = empty($package_name) ? '' : $package_name[1];
        $module_name                = empty($module_name) ? '' : $module_name[1];
        $controller_name            = empty($controller_name) ? '' : $controller_name[1];

        $data['package']['name']    = $this->parseByLanguages($package_name);
        $data['module']['name']     = $this->parseByLanguages($module_name);
        $data['controller']['name'] = $this->parseByLanguages($controller_name);

        return $data;
    }

    /**
     * 解析动作多语言名称
     *
     * @param $action
     * @param $reflection_class
     *
     * @return array
     */
    public function parseActionNames($action, $reflection_class)
    {
        $data               = [];
        $reflection_method  = $reflection_class->getMethod($action);
        $doc_comment        = $reflection_method->getDocComment();

        preg_match('/@acl\s(.*)\n/', $doc_comment, $name);
        if (empty($name))
        {
            $data['whitelist']  = true;
        }
        else
        {
            $data['name']       = $this->parseByLanguages($name[1]);
        }

        return $data;
    }

    /**
     * 解析动作第一行作为名称
     *
     * @param $action
     * @param $reflection_class
     *
     * @return string
     */
    public function parseActionName($action, $reflection_class)
    {
        $reflection_method  = $reflection_class->getMethod($action);
        $doc_comment        = $reflection_method->getDocComment();

        preg_match_all('#^\s*\*(.*)#m', $doc_comment, $lines);

        return isset($lines[1][0]) ? trim($lines[1][0]) : '';
    }

    /**
     * 解析动作参数中的 Request 类
     * ! 变量名，必须是 $request !
     *
     * @param $action
     * @param $reflection_class
     *
     * @return null || string
     */
    public function getActionRequestClass($action, $reflection_class)
    {
        $result            = null;
        $reflection_action = $reflection_class->getMethod($action);    // ReflectionMethod
        $reflection_params = $reflection_action->getParameters();   // ReflectionParameter

        foreach ($reflection_params as $param)
        {
            if ($param->getType() === null) continue;

            // if (strstr($param_class, 'App\Http\Requests\\'))
            if ($param->getName() == 'request')
            {
                $param_class = $param->getType()->getName(); // ReflectionNamedType
                $result      = new $param_class();
                break;
            }
        }

        return $result;
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
        $base_path  = app_path('Http/Controllers/');
        $dirs       = $this->filesystem->directories($base_path);
        if (empty($dirs))
        {
            return ['nothing'];
        }

        foreach ($dirs as $path)
        {
            $more = $this->filesystem->directories($path);
            $dirs = array_merge($dirs, $more);
        }

        foreach ($dirs as $k => &$dir)
        {
            if (stristr($dir, 'Traits'))
            {
                unset ($dirs[$k]);
                continue;
            }

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
     * Get the Stub Path.
     *
     * @return string
     */
    protected function getStubPath()
    {
        return __DIR__ . '/Stub/';
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

        if ( ! $this->filesystem->isFile($file))
        {
            // 获取语言文件的默认模板数据
            $file = $this->getStubPath() . "lang/{$language}.{$file_name}.stub";
        }

        return $to_string ? $this->filesystem->get($file) : $this->filesystem->getRequire($file);
    }

    /**
     * 获取多语言文件路径
     *
     * @param string $file_name
     * @param string $language
     * @param bool   $relative
     *
     * @return string
     */
    public function getLanguagePath($file_name = 'validation', $language = 'en', $relative = false)
    {
        $path = resource_path("lang/{$language}/");

        if ( ! $this->filesystem->isDirectory($path))
        {
            $this->filesystem->makeDirectory($path);
        }

        $path .= "{$file_name}.php";
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
     * 获取一表数据表的数据
     *
     * @param $table_name
     *
     * @return mixed
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function getOneTable($table_name)
    {
        $file = $this->getStoragePath() . "{$table_name}.php";

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
        return $this->filesystem->getRequire($this->getStoragePath() . 'tables.php');
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
        $data = $this->filesystem->getRequire($this->getStoragePath() . 'controllers.php');
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
        $file = $this->getDatabasePath('schema') . '_fields.yaml';
        if ( ! $this->filesystem->isFile($file))
        {
            return [];
        }

        $yaml      = new Yaml;
        $yaml_data = $yaml::parseFile($file);
        $fields    = isset($yaml_data['append_fields'])
                        ? array_merge($yaml_data['table_fields'], $yaml_data['append_fields'])
                        : $yaml_data['table_fields'];

        return empty($fields) ? [] : $fields;
    }

    /**
     * 获取字段数据
     *
     * @return array
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function getFields()
    {
        return $this->filesystem->getRequire($this->getStoragePath() . 'fields.php');
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
        $dictionaries = $this->filesystem->getRequire($this->getStoragePath() . 'dictionaries.php');
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

        return $relative ? str_replace(base_path(), '.', $path) : $path;
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
     * Get Storage Path
     *
     * @param $relative
     *
     * @return string
     */
    public function getStoragePath($relative = false)
    {
        $path = storage_path('scaffold/');

        return $relative ? str_replace(storage_path(), '.', $path) : $path;
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
     * Get App Route Path
     *
     * @param $relative
     *
     * @return string
     */
    public function getRoutePath($relative = false)
    {
        $path = base_path('routes/');

        return $relative ? str_replace(base_path(), '.', $path) : $path;
    }

    /**
     * Get App Route Path
     *
     * @param $relative
     *
     * @return string
     */
    public function getFilterPath($relative = false)
    {
        $path = base_path($this->getConfig('model.path')) . 'Filters/';

        return $relative ? str_replace(base_path(), '.', $path) : $path;
    }

    /**
     * 获取路由文件
     *
     * @param string $name
     * @param boolean $relative
     * @return string
     */
    public function getRouteFile($name = 'api', $relative = false)
    {
        $file = base_path('/') . $this->getConfig('routes.' . $name);

        return $relative ? str_replace(base_path('/'), '.', $file) : $file;
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
        return config("scaffold.$key", $default);
    }

}
