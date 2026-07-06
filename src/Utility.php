<?php

declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2025-07-29 14:17
 * @Description: Utility
 */

namespace Mooeen\Scaffold;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Mooeen\Scaffold\Support\ConsoleUi;
use Mooeen\Scaffold\Support\PackageRegistry;
use Mooeen\Scaffold\Support\TargetContext;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class Utility
{
    /**
     * @var mixed
     */
    protected Filesystem $filesystem;

    public function __construct()
    {
        $this->filesystem = new Filesystem;
    }

    /**
     * Helper to get the config values.
     */
    public function getConfig(string $key, mixed $default = null): mixed
    {
        return config("scaffold.$key", $default);
    }

    /**
     * 获取当前执行命令的登录用户
     */
    public function resolveCurrentLoginUser(): string
    {
        $candidates = [
            getenv('SUDO_USER') ?: null,
            getenv('LOGNAME') ?: null,
            getenv('USER') ?: null,
            getenv('USERNAME') ?: null,
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $user     = posix_getpwuid(posix_geteuid());
            $userName = trim((string) ($user['name'] ?? ''));
            if ($userName !== '') {
                return $userName;
            }
        }

        $author = trim((string) $this->getConfig('author', ''));

        return $author !== '' ? $author : 'unknown';
    }

    /**
     * 统一解析 API action 中的元信息
     */
    public function normalizeApiActionMeta(array $actionData, bool $formatDates = false): array
    {
        $meta         = $actionData['meta'] ?? [];
        $meta         = is_array($meta) ? $meta : [];
        $createdAt    = trim((string) ($meta['created_at'] ?? ($actionData['created_at'] ?? '')));
        $updatedAt    = trim((string) ($meta['updated_at'] ?? ($actionData['updated_at'] ?? '')));
        $deprecatedAt = trim((string) ($meta['deprecated_at'] ?? ($actionData['deprecated_at'] ?? '')));

        $data = [
            'creator'           => trim((string) ($meta['creator'] ?? ($actionData['creator'] ?? ($actionData['user'] ?? '')))),
            'created_at'        => $createdAt,
            'updated_by'        => trim((string) ($meta['updated_by'] ?? ($actionData['updated_by'] ?? ''))),
            'updated_at'        => $updatedAt,
            'deprecated_by'     => trim((string) ($meta['deprecated_by'] ?? ($actionData['deprecated_by'] ?? ''))),
            'deprecated_at'     => $deprecatedAt,
            'deprecated_reason' => trim((string) ($meta['deprecated_reason'] ?? ($actionData['deprecated_reason'] ?? ''))),
        ];

        if (! $formatDates) {
            return $data;
        }

        $data['created_at']    = $this->formatDisplayDate($data['created_at']);
        $data['updated_at']    = $this->formatDisplayDate($data['updated_at']);
        $data['deprecated_at'] = $this->formatDisplayDate($data['deprecated_at']);

        return $data;
    }

    public function isApiActionDeprecated(array $actionData): bool
    {
        $deprecated = $actionData['deprecated'] ?? false;

        if (is_bool($deprecated)) {
            return $deprecated;
        }

        if (is_numeric($deprecated)) {
            return (int) $deprecated === 1;
        }

        return in_array(strtolower(trim((string) $deprecated)), ['1', 'true', 'yes', 'deprecated'], true);
    }

    /**
     * 兼容旧的扁平菜单格式，并保证 controllers 字段始终可安全追加。
     */
    public function normalizeMenusTransform(array $data): array
    {
        foreach ($data as $key => $val) {
            if (is_string($val)) {
                $data[$key] = ['name' => $val, 'controllers' => []];

                continue;
            }

            if (is_array($val)) {
                $data[$key]['controllers'] = is_array($val['controllers'] ?? null)
                    ? $val['controllers']
                    : [];

                continue;
            }

            unset($data[$key]);
        }

        return $data;
    }

    /**
     * 日期统一展示为 Y-m-d
     */
    public function formatDisplayDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $matches) === 1) {
            return $matches[0];
        }

        $timestamp = strtotime($value);

        return $timestamp !== false ? date('Y-m-d', $timestamp) : $value;
    }

    /**
     * 安全读取 YAML：调试/文档页面遇到损坏文件时跳过，不影响其他模块展示。
     */
    public function parseYamlFile(string $path): array
    {
        if (! $this->filesystem->isFile($path)) {
            return [];
        }

        try {
            $data = Yaml::parseFile($path) ?: [];
        } catch (Throwable) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Get apps list
     */
    public function getApps(): array
    {
        $config = $this->getConfig('controller', []);
        if (! is_array($config)) {
            return [];
        }

        $res = [];
        foreach ($config as $app => $controller) {
            if (! is_array($controller)) {
                continue;
            }

            $res[$app] = $controller['api_name'] ?? $controller['name']['zh-CN'];
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
     * Get Resource Path
     */
    public function getResourcePath($relative = false): string
    {
        $path = base_path($this->getConfig('resource.path'));

        return $relative ? str_replace(base_path(), '.', $path) : $path;
    }

    /**
     * Get App Resource Path
     */
    public function getAppResourcePath(string $app, bool $relative = false): string
    {
        $path = $this->getConfig("controller.{$app}.resource_path", $this->getConfig('resource.path'));
        $path = base_path($path);

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
     * Get API Schema Path
     */
    public function getApiPath(string $folder = 'schema', bool $relative = false): string
    {
        $path = base_path($this->getConfig('api.' . $folder));

        return $relative ? str_replace(base_path(), '.', $path) : $path;
    }

    /**
     * Get ACL Schema Path
     */
    public function getAclPath(bool $relative = false): string
    {
        $path = base_path('scaffold/acl/');

        return $relative ? str_replace(base_path(), '.', $path) : $path;
    }

    /**
     * 检查 API YAML 文件是否存在
     */
    public function isApiFileExist(string $folderPath, string $fileName, string $folder = 'schema'): string
    {
        $folderPath = trim($folderPath, '/');
        $file       = $this->getApiPath($folder) . (empty($folderPath) ? '' : $folderPath . '/') . $fileName . '.yaml';

        if (! $this->filesystem->isFile($file)) {
            throw new InvalidArgumentException("Invalid File Argument (Not Found): {$file}");
        }

        return $file;
    }

    /**
     * 移除 action key 中的 HTTP 方法后缀
     */
    public function removeActionNameMethod(string|array $action): string|array
    {
        if (is_array($action)) {
            return array_map(
                fn (string $val): string => $this->removeActionNameMethod($val),
                $action
            );
        }

        return (string) preg_replace(
            '/_(?:get|post|delete|put|patch|head|options|any)(?:\|(?:get|post|delete|put|patch|head|options|any))*$/i',
            '',
            $action
        );
    }

    /**
     * Get Scaffold Database Path
     *
     * Configured value 可以是 relative path(走 base_path() prefix,常规生产用法)
     * 或 absolute path(scaffold 包测试 / 多项目共享场景,plan-33 加)。
     */
    public function getDatabasePath($folder = 'schema', $relative = false): string
    {
        $configured = (string) $this->getConfig('database.' . $folder);
        $path       = str_starts_with($configured, '/') ? $configured : base_path($configured);

        return $relative ? str_replace(base_path(), '.', $path) : $path;
    }

    /**
     * 获取 schema 文件路径
     */
    public function getSchemaPath($file_name = null, bool $relative = false): string
    {
        $path = $this->getDatabasePath('schema', $relative);

        return $file_name === null ? $path : ($path . $file_name);
    }

    /**
     * 解析多目标上下文(plan-53:出身模型)。
     *
     * $target 为 null / 'host' → host 隐含默认:沿用现有 host 路径与命名空间(字节不变)。
     * 否则从 PackageRegistry(自动发现,无 config 注册表)取包根 + psr-4 命名空间,
     * 各类路径按全 repo 统一目录约定固化(moo-system / moo-radar 实证形态)。
     */
    public function targetContext(?string $target = null): TargetContext
    {
        if ($target === null || $target === 'host') {
            return new TargetContext(
                target: null,
                basePath: rtrim(base_path(), '/') . '/',
                paths: [
                    'model'     => $this->getModelPath(),
                    'resource'  => $this->getResourcePath(),
                    'migration' => $this->getMigrationPath(),
                    'storage'   => $this->getStoragePath(),
                    'database'  => $this->getDatabasePath('schema'),
                    'api'       => $this->getApiPath('schema'),
                    'acl'       => $this->getAclPath(),
                    'docs'      => base_path((string) $this->getConfig('docs.path', 'scaffold/docs')) . '/',
                ],
                namespaces: [
                    'model' => $this->formatNameSpace($this->getModelPath(true)),
                ],
                app: null,
                classes: [],
            );
        }

        $pkg = app(PackageRegistry::class)->get($target);
        if ($pkg === null) {
            throw new InvalidArgumentException("未发现的扩展包:[{$target}](包根须带 scaffold/database/ 目录才会被自动发现)");
        }

        $base = $pkg['base_path'];
        $ns   = $pkg['namespace'];

        return new TargetContext(
            target: $target,
            basePath: $base,
            // 目录约定全 repo 统一(moo-system / moo-radar 实证):包不合约定改包,不改工具
            paths: [
                'model'      => $base . 'src/Models/',
                'resource'   => $base . 'src/Http/Resources/',
                'request'    => $base . 'src/Http/Requests/',
                'controller' => $base . 'src/Http/Controllers/Admin/',
                'migration'  => $base . 'database/migrations/',
                'database'   => $base . 'scaffold/database/',
                'docs'       => $base . 'docs/',
                'lang'       => $base . 'lang/',
                'route'      => $base . 'routes/admin.php',
                // 缓存是 host 侧聚合物(条目挂 origin 键区分出身),不按包分桶
                'storage' => $this->getStoragePath(),
            ],
            namespaces: [
                'model'      => $ns . '\\Models',
                'resource'   => $ns . '\\Http\\Resources',
                'request'    => $ns . '\\Http\\Requests',
                'controller' => $ns . '\\Http\\Controllers\\Admin',
            ],
            // 包的控制器挂 host 的 admin 组(extra_modules 范式),app 固定 admin
            app: 'admin',
            classes: [],
            writable: (bool) $pkg['writable'],
        );
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
            (new ConsoleUi($command))->created('.' . $relative_file);
        }
    }

    /**
     * 根据语言解析
     */
    public function parseByLanguages(string $string): array
    {
        $languages = $this->getConfig('languages');
        $string    = str_replace("'", '&apos;', $string);
        $data      = [];

        foreach ($languages as $lang) {
            preg_match('/' . $lang . ':([^\|\,]*)[\|\,}]/i', $string, $temp);
            $data[$lang] = empty($temp) ? '' : trim($temp[1]);
        }

        return $data;
    }

    /**
     * 解析 包名、模块名、控制器名
     */
    public function parsePMCNames(\ReflectionClass $reflection_class): array
    {
        $data        = [];
        $doc_comment = $this->normalizeDocComment($reflection_class->getDocComment());

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
    public function parseActionInfo(\ReflectionMethod $reflection_method): array
    {
        $data        = [];
        $doc_comment = $this->normalizeDocComment($reflection_method->getDocComment());

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
    public function parseActionName(\ReflectionMethod $reflection_method): string
    {
        $doc_comment = $this->normalizeDocComment($reflection_method->getDocComment());

        preg_match_all('#^\s*\*(.*)#m', $doc_comment, $lines);

        return isset($lines[1][0]) ? trim($lines[1][0]) : '';
    }

    /**
     * 解析 action 描述 —— docblock 第一行是 name(parseActionName 取),第 2 行起的散文行是 desc。
     * 跳过空行 + @param/@return 等 tag 行。返回多行 list(跟 yaml 里 desc: [] 同形)。
     *
     * @return list<string>
     */
    public function parseActionDesc(\ReflectionMethod $reflection_method): array
    {
        $doc_comment = $this->normalizeDocComment($reflection_method->getDocComment());

        // (?!/) 排除收尾的 `*/` 行(否则会捕到一个 '/' 混进 desc);name 行仍是第一行,shift 掉
        preg_match_all('#^\s*\*(?!/)(.*)#m', $doc_comment, $lines);
        $rows = $lines[1] ?? [];
        array_shift($rows);   // 丢掉第一行(name)

        $desc = [];
        foreach ($rows as $row) {
            $row = trim($row);
            if ($row === '' || str_starts_with($row, '@')) {
                continue;
            }
            // 去行首 markdown 列表符(- / * / –),展示端(说明卡)自带项目符,避免「· -」双重符号
            $row = (string) preg_replace('/^[-*–]\s+/u', '', $row);
            if ($row === '') {
                continue;
            }
            $desc[] = $row;
        }

        return $desc;
    }

    private function normalizeDocComment(string|false $docComment): string
    {
        return $docComment === false ? '' : $docComment;
    }

    /**
     * 解析动作参数中的 Request 类
     * ! 变量名，必须是 $request !
     */
    public function getActionRequestClass(\ReflectionMethod $reflection_action)
    {
        $result            = null;
        $reflection_params = $reflection_action->getParameters();

        foreach ($reflection_params as $param) {
            $type = $param->getType();
            if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            if ($param->getName() === 'request') {
                $param_class = $type->getName();
                if (! class_exists($param_class)) {
                    continue;
                }

                $result = (new \ReflectionClass($param_class))->newInstanceWithoutConstructor();
                break;
            }
        }

        return $result;
    }

    /**
     * 获取控制器的命令空间列表
     * !!! 只支持往下两级，更深的层级暂不支持!!!
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

        $result = array_values($dirs);

        // 追加包提供的额外模块（无对应目录，由 controller.{app}.extra_modules 声明），
        // 使 moo:api / moo:auth 能遍历到（如搬到 charsen/moo-system 包的 System 模块）。
        foreach (array_keys($this->getExtraModules($app)) as $extra) {
            if (! in_array($extra, $result, true)) {
                $result[] = $extra;
            }
        }

        return $result;
    }

    /**
     * 包提供的额外 admin 模块：模块名 => 控制器命名空间。
     * 例：['System' => 'Mooeen\System\Http\Controllers\Admin']。
     *
     * 这些控制器不在 host 的 controller.{app}.path 下、生产环境又位于 vendor/，
     * 需显式声明才能进入 ACL 生成（moo:auth）/ API 文档（moo:api）/ 路由调试 / 接口调试。
     * 默认空数组 → 未配置的 host 行为不变（向后兼容）。
     */
    public function getExtraModules(string $app = 'admin'): array
    {
        $map = (array) $this->getConfig("controller.{$app}.extra_modules", []);
        $out = [];
        foreach ($map as $module => $namespace) {
            $out[ucfirst((string) $module)] = trim((string) $namespace, '\\');
        }

        return $out;
    }

    /**
     * 获取所有 Schema 文件的名称(plan-53:走 SchemaLoader::listSchemaFiles 单一真源,
     * host + 各扩展包聚合;出身用 schemaOrigin() 查)。
     */
    public function getSchemaNames(): array
    {
        return array_keys(app(\Mooeen\Scaffold\Designer\SchemaLoader::class)->listSchemaFiles());
    }

    /**
     * schema 的出身:null = host,否则扩展包 key(plan-53)。
     */
    public function schemaOrigin(string $schema): ?string
    {
        return app(\Mooeen\Scaffold\Designer\SchemaLoader::class)->originOf($schema);
    }

    /**
     * 获取一表数据表的数据
     *
     * @throws FileNotFoundException
     */
    public function getOneTable(string $table_name): array
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
    public function getTables(): array
    {
        return $this->filesystem->getRequire($this->getStoragePath() . 'tables.php');
    }

    /**
     * 获取 模型 数据
     *
     * @throws FileNotFoundException
     */
    public function getModels(): array
    {
        return $this->filesystem->getRequire($this->getStoragePath() . 'models.php');
    }

    /**
     * 获取 模型ID 数据
     *
     * @throws FileNotFoundException
     */
    public function getModelIds(): array
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
        $file         = $this->getDatabasePath('schema') . '_fields.yaml';
        $yaml_data    = $this->parseYamlFile($file);
        $tableFields  = is_array($yaml_data['table_fields'] ?? null) ? $yaml_data['table_fields'] : [];
        $appendFields = is_array($yaml_data['append_fields'] ?? null) ? $yaml_data['append_fields'] : [];

        $fields = array_merge($tableFields, $appendFields);

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
                    // 2026-05-21:跳 designer pending sentinel(yaml 占位 __pending_n),
                    // 避免 lang file 出现 memo_status___pending_0 这种 garbage key。
                    // user AI 翻译填好真实 key 后重跑 moo:i18n 才生成 lang 条目。
                    if (str_starts_with((string) $alias, '__pending_')) {
                        continue;
                    }
                    $result[$field_name . '_' . $alias] = ['zh-CN' => $attr[2], 'en' => $attr[1]];
                }
            }
        }

        return $result;
    }

    /**
     * 数据字典统计总数(有字典的模块数 / 枚举字段数 / 字典值数)。
     *
     * 口径跟 ScaffoldController::dictionaries 一致:按模块表分组、只数有枚举的表、
     * value 取原始 case 数 — 供 designer index 字典卡片 + 字典页共用,保证两处数字一致。
     */
    public function dictionaryStats(): array
    {
        try {
            $tables   = $this->getTables();
            $allEnums = $this->getEnums(false);
        } catch (FileNotFoundException) {
            return ['modules' => 0, 'fields' => 0, 'values' => 0];
        }

        $modules = 0;
        $fields  = 0;
        $values  = 0;

        foreach ($tables as $folder) {
            $moduleHasDict = false;
            foreach (array_keys($folder['tables'] ?? []) as $tableName) {
                $dictionaries = $allEnums[$tableName] ?? [];
                if (empty($dictionaries)) {
                    continue;
                }
                $moduleHasDict = true;
                $fields += count($dictionaries);
                foreach ($dictionaries as $rows) {
                    $values += count($rows);
                }
            }
            if ($moduleHasDict) {
                $modules++;
            }
        }

        return ['modules' => $modules, 'fields' => $fields, 'values' => $values];
    }

    /**
     * 格式化命名空间
     */
    public function formatNameSpace(string $path): string
    {
        return ucfirst(str_replace(['./', '/'], ['', '\\'], $path));
    }

    /**
     * controller 类名归一化：去掉末尾的 `Controller` 后缀。
     *
     * **只剥尾缀** —— `UserController` → `User`，但中间含 `Controller` 的名字不动
     * （`ControllerManager` 原样返回）。历史上各端混用 `str_replace`（删全部出现）/
     * `Str::replaceLast`（删最后一次）语义不一，对病态名字结果发散；这里收敛到 `Str::replaceEnd` 同义。
     * 短名 / FQCN 都可传（后缀在末尾，不受前缀影响）。
     */
    public static function stripControllerSuffix(string $class): string
    {
        return str_ends_with($class, 'Controller')
            ? substr($class, 0, -10) // strlen('Controller') === 10
            : $class;
    }

    /**
     * controller 类名归一化：保证以 `Controller` 结尾（缺则补，已有不重复，空串原样返回）。
     * 跟 {@see stripControllerSuffix()} 互为逆操作，同为单一真源。
     */
    public static function ensureControllerSuffix(string $class): string
    {
        return $class === '' || str_ends_with($class, 'Controller')
            ? $class
            : $class . 'Controller';
    }
}
