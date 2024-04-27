<?php

namespace Mooeen\Scaffold;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
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
     * @var mixed
     */
    protected Filesystem $filesystem;

    public function __construct()
    {
        $this->filesystem = new Filesystem();
    }

    /**
     * Helper to get the config values.
     */
    public function getConfig($key, $default = null): mixed
    {
        return config("scaffold.$key", $default);
    }

    /**
     * Get apps list
     */
    public function getApps(): array
    {
        $config = $this->getConfig('controller');
        $res    = [];
        foreach ($config as $app => $controller) {
            $res[$app] = $controller['name']['zh-CN'];
        }

        return $res;
    }

    /**
     * Get Model Path
     */
    public function getModelPath($relative = false): string
    {
        $path = base_path($this->getConfig('model.path'));

        return $relative ? str_replace(base_path(), '.', $path) : $path;
    }

    /**
     * Get Controller Path
     */
    public function getControllerPath($key = 'controller.admin.path', $relative = false): string
    {
        $path = base_path($this->getConfig($key));

        return $relative ? str_replace(base_path(), '.', $path) : $path;
    }

    /**
     * Get Migration Path
     */
    public function getMigrationPath($relative = false): string
    {
        $path = database_path('migrations/');

        return $relative ? str_replace(base_path(), '.', $path) : $path;
    }

    /**
     * Get Storage Path
     */
    public function getStoragePath($relative = false): string
    {
        $path = storage_path('scaffold/');

        return $relative ? str_replace(storage_path(), '.', $path) : $path;
    }

    /**
     * Get Scaffold Database Path
     */
    public function getDatabasePath($folder = 'schema', $relative = false): string
    {
        $path = base_path($this->getConfig('database.' . $folder));

        return $relative ? str_replace(base_path(), '.', $path) : $path;
    }

    /**
     * 获取 schema 文件路径
     */
    public function getSchemaPatch($file_name = null, bool $relative = false): string
    {
        $path = $this->getDatabasePath('schema', $relative);

        return $file_name === null ? $path : ($path . $file_name);
    }

    /**
     * 添加 git ignore 文件
     */
    public function addGitIgnore($command): void
    {
        $file = storage_path('scaffold/') . '.gitignore';
        if (! $this->filesystem->isFile($file)) {
            $this->filesystem->put($file, '*' . PHP_EOL . '!.gitignore');
            $relative_file = str_replace(base_path(), '', $file);
            $command->info('+ .' . $relative_file);
        }
    }

    /**
     * 根据语言解析
     */
    public function parseByLanguages($string): array
    {
        $languages = $this->getConfig('languages');
        $data      = [];
        foreach ($languages as $lang) {
            $string = str_replace("'", '&apos;', $string);
            preg_match('/' . $lang . ':([^\|\,]*)[\|\,}]/i', $string, $temp);
            $data[$lang] = empty($temp) ? '' : trim($temp[1]);
        }

        return $data;
    }

    /**
     * 解析 包名、模块名、控制器名
     */
    public function parsePMCNames($reflection_class): array
    {
        $data        = [];
        $doc_comment = $reflection_class->getDocComment();

        preg_match('/@package\_name\s(.*)\n/', $doc_comment, $package_name);
        preg_match('/@module\_name\s(.*)\n/', $doc_comment, $module_name);
        preg_match('/@controller\_name\s(.*)\n/', $doc_comment, $controller_name);

        $package_name    = empty($package_name) ? '' : $package_name[1];
        $module_name     = empty($module_name) ? '' : $module_name[1];
        $controller_name = empty($controller_name) ? '' : $controller_name[1];

        $data['package']['name']    = $this->parseByLanguages($package_name);
        $data['module']['name']     = $this->parseByLanguages($module_name);
        $data['controller']['name'] = $this->parseByLanguages($controller_name);

        return $data;
    }

    /**
     * 解析动作多语言名称
     */
    public function parseActionInfo($reflection_method): array
    {
        $data        = [];
        $doc_comment = $reflection_method->getDocComment();

        preg_match('/@acl\s(.*)\n/', $doc_comment, $acl);
        $data['whitelist'] = empty($acl);
        $temp_string       = (empty($acl) ? '' : $acl[1]);
        $data['name']      = $this->parseByLanguages($temp_string);

        preg_match('/desc:([^\|]*)[\|}]/i', $temp_string, $temp);
        $data['desc'] = empty($temp) ? '' : trim($temp[1]);

        return $data;
    }

    /**
     * 解析动作第一行作为名称
     */
    public function parseActionName($reflection_method): string
    {
        $doc_comment = $reflection_method->getDocComment();

        preg_match_all('#^\s*\*(.*)#m', $doc_comment, $lines);

        return isset($lines[1][0]) ? trim($lines[1][0]) : '';
    }

    /**
     * 解析动作参数中的 Request 类
     * ! 变量名，必须是 $request !
     */
    public function getActionRequestClass($reflection_action)
    {
        $result            = null;
        $reflection_params = $reflection_action->getParameters();

        foreach ($reflection_params as $param) {
            if ($param->getType() === null) {
                continue;
            }

            if ($param->getName() === 'request') {
                $param_class = $param->getType()->getName(); // ReflectionNamedType
                $result      = new $param_class();
                break;
            }
        }

        return $result;
    }

    /**
     * 获取控制器的命令空间列表
     * !!! 只支持往下两级，更深的层级不支持!!!
     */
    public function getControllerNamespaces(string $app = 'admin'): array
    {
        $base_path = base_path($this->getConfig("controller.{$app}.path"));
        $dirs      = $this->filesystem->directories($base_path);
        array_unshift($dirs, $base_path);
        if (empty($dirs)) {
            return [];
        }

        foreach ($dirs as $path) {
            if ($path === $base_path) {
                continue;
            }
            $more = $this->filesystem->directories($path);
            $dirs = [...$dirs, ...$more];
        }

        foreach ($dirs as $k => &$dir) {
            if (str_contains($dir, 'Traits')) {
                unset($dirs[$k]);

                continue;
            }

            if ($dir === $base_path) {
                $dir = '<ROOT_PATH>'; // 控制器的根目录
            }

            $dir = str_replace($base_path, '', $dir);
        }

        return $dirs;
    }

    /**
     * 获取所有 Schema 文件的名称
     */
    public function getSchemaNames(): array
    {
        $path  = $this->getDatabasePath('schema');
        $files = $this->filesystem->files($path);

        $data = [];
        foreach ($files as $file) {
            if ($file->getBasename('.yaml') !== '_fields') {
                $data[] = $file->getBasename('.yaml');
            }
        }

        return $data;
    }

    /**
     * 获取一表数据表的数据
     *
     * @throws FileNotFoundException
     */
    public function getOneTable($table_name): mixed
    {
        $file = $this->getStoragePath() . "{$table_name}.php";

        if (! $this->filesystem->isFile($file)) {
            throw new InvalidArgumentException('Invalid Argument (Not Found).');
        }

        return $this->filesystem->getRequire($file);
    }

    /**
     * 获取 数据表 数据
     *
     * @throws FileNotFoundException
     */
    public function getTables(): mixed
    {
        return $this->filesystem->getRequire($this->getStoragePath() . 'tables.php');
    }

    /**
     * 获取 模型 数据
     *
     * @throws FileNotFoundException
     */
    public function getModels(): mixed
    {
        return $this->filesystem->getRequire($this->getStoragePath() . 'models.php');
    }

    /**
     * 获取 模型ID 数据
     *
     * @throws FileNotFoundException
     */
    public function getModelIds(): mixed
    {
        return $this->filesystem->getRequire($this->getStoragePath() . 'model_ids.php');
    }

    /**
     * 获取控制器数据
     */
    public function getControllers(bool $merge_all = true): array
    {
        $data = $this->filesystem->getRequire($this->getStoragePath() . 'controllers.php');
        if (! $merge_all) {
            return $data;
        }

        $result = [];
        foreach ($data as $schema_file => $controllers) {
            foreach ($controllers as $class => $attr) {
                $result[$class] = $attr;
            }
        }

        return $result;
    }

    /**
     * 获取多语言字段数据
     */
    public function getLangFields(): array
    {
        $file = $this->getDatabasePath('schema') . '_fields.yaml';
        if (! $this->filesystem->isFile($file)) {
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
     *
     * @throws FileNotFoundException
     */
    public function getFields(): array
    {
        return $this->filesystem->getRequire($this->getStoragePath() . 'fields.php');
    }

    /**
     * 获取字典数据
     *
     *
     * @throws FileNotFoundException
     */
    public function getEnums(bool $merge_all = true): array
    {
        $enums = $this->filesystem->getRequire($this->getStoragePath() . 'enums.php');
        if (! $merge_all) {
            return $enums;
        }

        $result = [];
        foreach ($enums as $table_name => $fields) {
            foreach ($fields as $field_name => $attr) {
                $result[$field_name] = $attr;
            }
        }

        return $result;
    }

    /**
     * 获取字典里的所有词
     */
    public function getEnumWords(): array
    {
        $enums = $this->getEnums(false);

        $result = [];
        foreach ($enums as $table_name => $fields) {
            foreach ($fields as $field_name => $words) {
                foreach ($words as $alias => $attr) {
                    $result[$field_name . '_' . $alias] = ['zh-CN' => $attr[2], 'en' => $attr[1]];
                }
            }
        }

        return $result;
    }
}
