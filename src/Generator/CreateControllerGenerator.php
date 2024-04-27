<?php

namespace Mooeen\Scaffold\Generator;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Str;

use function in_array;

/**
 * Create Controller
 *
 * @author Charsen https://github.com/charsen
 */
class CreateControllerGenerator extends Generator
{
    // controller 的基层目录
    protected string $base_path;

    /**
     * @throws FileNotFoundException
     */
    public function start(string $schema_name, bool $force = false)
    {
        $this->base_path = app_path('/');
        $all             = $this->utility->getControllers(false);

        if (! isset($all[$schema_name])) {
            $this->command->error("Schema File \"{$schema_name}\" could not be found.");

            return false;
        }

        // 已生成的 controllers
        $created = [];
        $apps    = $this->utility->getApps();

        //dump($all[$schema_name]);
        foreach ($all[$schema_name] as $class => $attr) {
            foreach ($apps as $app_folder => $app_name) {
                $app_folder    = strtolower($app_folder);
                $uc_app_folder = ucfirst($app_folder);

                // 控制器没配置的 app 不生产
                if (! in_array($app_folder, $attr['app'], true)) {
                    continue;
                }

                // 检查目录是否存在，不存在则创建
                $path = $this->base_path . $uc_app_folder . '/Controllers/' . $attr['module']['folder'];
                if (! $this->filesystem->isDirectory($path)) {
                    $this->filesystem->makeDirectory($path, 0777, true, true);
                }

                // namespace 前缀处理
                $config_key    = 'controller.' . $app_folder . '.path';
                $namespace_pre = ucfirst(str_replace(['./', '/'], ['', '\\'], $this->utility->getControllerPath($config_key, true)));

                // model 处理
                $model_class = $this->utility->getModelPath(relative: true) . $attr['module']['folder'] . '/' . $attr['model_class'];
                // $trait_class = $this->utility->getModelPath(relative: true) . $attr['module']['folder'] . '/Traits/' . $attr['model_class'] . 'Trait';
                $model_class = ucfirst(str_replace(['./', '/'], ['', '\\'], $model_class));

                // 表格数据
                $table_attrs = $this->utility->getOneTable($attr['table_name']);
                $fields      = $table_attrs['fields'];
                $enums       = $table_attrs['enums'];

                $meta = [
                    'author'                        => $this->utility->getConfig('author'),
                    'date'                          => date('Y-m-d H:i:s'),
                    'package_name'                  => $app_name,
                    'package_en_name'               => $uc_app_folder,
                    'module_name'                   => $attr['module']['name'],
                    'module_en_name'                => $attr['module']['folder'],
                    'table_name'                    => $attr['table_name'],
                    'entity_name'                   => $attr['entity_name'],
                    'entity_en_name'                => $attr['model_class'],
                    'namespace'                     => "{$namespace_pre}{$attr['module']['folder']}",
                    'use_base_action'               => "{$namespace_pre}Traits\\BaseActionTrait",
                    'use_base_controller'           => $this->utility->getConfig('class.controller'),
                    'use_base_resources'            => $this->utility->getConfig('class.resources.base'),
                    'use_base_resources_collection' => $this->utility->getConfig('class.resources.collection'),
                    'use_form_widgets'              => $this->utility->getConfig('class.resources.form'),
                    'use_columns'                   => $this->utility->getConfig('class.resources.columns'),
                    'use_table_columns'             => $this->utility->getConfig('class.resources.table_columns'),
                    'controller_name'               => Str::replaceLast('Controller', '', $class),
                    'index_fields'                  => $this->getListFields($fields),
                    'index_columns'                 => $this->getListFields($fields, $app_folder === 'admin'),
                    'show_fields'                   => $this->getShowFields($fields),
                    'trashed_fields'                => $this->getListFields($fields, false, true),
                    'trashed_columns'               => $this->getListFields($fields, $app_folder === 'admin', true),
                    'route_key'                     => strtolower(Str::snake($attr['model_class'], '-')),
                    'model_class'                   => $model_class,
                    'model_key_name'                => (new $model_class())->getKeyName(),
                    'model_name'                    => $attr['model_class'],
                ];

                // 验证规则处理
                $rules = $this->rebuildFieldsRules($meta, $fields, $enums);

                // 生成 Request 文件
                $controller_file      = "{$path}/{$class}.php";
                $meta['use_requests'] = $this->buildRequest($uc_app_folder, $rules, $enums, $table_attrs['index'], $meta, $controller_file, $force);
                $meta['use_requests'] = implode(PHP_EOL, $meta['use_requests']);

                // 生成 controller 文件
                $controller_relative_file = str_replace(base_path(), '.', $controller_file);
                if ($this->filesystem->isFile($controller_file) && ! $force) {
                    $this->command->error('x ' . $controller_relative_file . ' is existed');
                    $this->command->newline();

                    continue;
                }

                $stub    = $this->utility->getConfig('controller.' . $app_folder . '.stub');
                $content = $this->buildStub($meta, $this->getStub($stub));
                $this->filesystem->put($controller_file, $content);
                $this->command->info('+ ' . $controller_relative_file);
                $this->command->newLine();

                $created[] = [
                    'app'         => $app_folder,
                    'namespace'   => "\\{$meta['namespace']}\\",
                    'name'        => $meta['controller_name'] . 'Controller',
                    'entity'      => Str::plural($meta['route_key']),
                    'model_class' => $meta['model_class'],
                ];
            }
        }

        // 更新路由文件内容
        $this->updateRoutes($created);

        return true;
    }

    /**
     * 生成 Request
     */
    public function buildRequest(string $app_folder, array $rules, array $enums, array $index, array $controller, string $controller_file, bool $force): array
    {
        $enum_namespace = $this->utility->getModelPath(relative: true) . $controller['module_en_name'] . '/Enums/';
        $enum_namespace = ucfirst(str_replace(['./', '/'], ['', '\\'], $enum_namespace));

        $use_codes = [];

        // 检查目录是否存在，不存在则创建
        $folder    = app_path($app_folder) . "/Requests/{$controller['module_en_name']}/{$controller['controller_name']}/";
        $namespace = ucfirst(str_replace([base_path() . '/', '/'], ['', '\\'], $folder));

        if (! $this->filesystem->isDirectory($folder)) {
            $this->filesystem->makeDirectory($folder, 0777, true, true);
        }

        $use_enums_code = [];
        $options        = $values = ['['];
        foreach ($rules['enum_class'] as $enum) {
            $use_enums_code[] = "use {$enum_namespace}{$enum};";
            $tmp_field        = Str::snake($enum, '_');
            $options[]        = $this->getTabs(3) . "'{$tmp_field}' => {$enum}::valueLabels(),";
            $values[]         = $this->getTabs(3) . "'{$tmp_field}' => {$enum}::values(),";
        }
        $options[] = $values[] = $this->getTabs(2) . ']';
        unset($rules['enum_class']);

        // 创建 BaseRequestTrait
        $trait_name = "{$controller['controller_name']}RequestTrait";
        $meta       = [
            'namespace'  => trim($namespace, '\\'),
            'trait_name' => $trait_name,
            'table_name' => "'{$controller['table_name']}'",
            // 'use_model_class' => "use {$controller['model_class']}",
            // 'model_name'      => $controller['model_name'],
            'use_enums' => implode(PHP_EOL, $use_enums_code),
            'values'    => implode(PHP_EOL, $values),
            'options'   => implode(PHP_EOL, $options),
        ];
        $trait_file         = "{$folder}{$trait_name}.php";
        $dont_build_request = $this->filesystem->isFile($trait_file);
        $this->filesystem->put($trait_file, $this->buildStub($meta, $this->getStub('request-base-trait')));
        $this->command->info('+ ' . str_replace(base_path(), '.', $trait_file));

        // 如果之前已经有 trait 文件了，并且存在 controller 文件，则不再生成 request 文件，避免删除了又重新生成
        if ($dont_build_request && $this->filesystem->isFile($controller_file)) {
            return [];
        }

        // 按配置生成 Request
        $requests = $this->utility->getConfig('controller.' . strtolower($app_folder) . '.requests');
        foreach ($requests as $one) {
            $one                   = ucfirst($one);
            $request_name          = $one . 'Request';
            $request_file          = $folder . $request_name . '.php';
            $request_relative_file = str_replace(base_path(), '.', $request_file);
            $use_codes[]           = "use {$namespace}{$request_name};";

            if ($this->filesystem->isFile($request_file) && ! $force) {
                $this->command->error('x Request is existed (' . $request_relative_file . ')');

                continue;
            }

            // create & update action
            $codes = ['['];
            if (in_array($one, ['Store', 'Update'])) {
                foreach ($rules as $field_name => $rule) {
                    if ($one === 'Store') {
                        $codes[] = $this->getTabs(3) . "'{$field_name}' => [" . implode(', ', $this->addQuotation($rule)) . '],';
                    } else {
                        $codes[] = $this->getTabs(3) . "'{$field_name}' => [" . implode(', ', $this->addQuotation($rule, $field_name, $controller['route_key'])) . '],';
                    }
                }
            } elseif (in_array($one, ['Index', 'Trashed'])) {
                foreach ($rules as $field_name => $rule) {
                    if (isset($enums[$field_name]) or isset($index[$field_name])) {
                        $rule = str_replace('required', 'nullable', $rule);
                        foreach ($rule as $tk => $tmp) {
                            if (str_contains($tmp, '$this->getUnique') or str_contains($tmp, 'min:')) {
                                unset($rule[$tk]);
                            }
                        }
                        $codes[] = $this->getTabs(3) . "'{$field_name}' => [" . implode(', ', $this->addQuotation($rule)) . '],';
                    }
                }

                $codes[] = $this->getTabs(3) . "'page' => ['required', 'integer', 'min:1'],";
                $codes[] = $this->getTabs(3) . "'page_limit' => ['required', 'integer', 'min:1'],";
            } elseif (in_array($one, ['Destroy', 'Restore', 'DestroyBatch'])) {
                $codes[] = $this->getTabs(3) . "'ids' => ['required', 'digital_array'],";
            }

            $codes[] = $this->getTabs(2) . ']';

            $meta = [
                'namespace' => trim($namespace, '\\'),
                //'model_class'      => $controller['model_class'],
                'use_base_request' => $this->utility->getConfig('class.form_request'),
                //'use_enums'        => implode(PHP_EOL, $use_enums_code),
                'request_name' => $request_name,
                'trait_name'   => $trait_name,
                'rules'        => implode(PHP_EOL, $codes),
                'options'      => implode(PHP_EOL, $options),
            ];

            $content = $this->buildStub($meta, $this->getStub('request'));
            $this->filesystem->put($request_file, $content);
            $this->command->info('+ ' . $request_relative_file);
        }

        return $use_codes;
    }

    /**
     * 生成 controller 的 trait 代码文件
     */
    public function buildTrait(string $controller, array $data, bool $force): void
    {
        $model_class = $this->utility->getConfig('model.path') . $data['module']['folder'] . '/' . $data['model_class'];

        if (count($data['app']) > 1) {
            $app = $this->command->choice('Which  app?', $data['app']);
        } else {
            $app = $data['app'][0];
        }

        $path                = $this->utility->getConfig("controller.{$app}.path");
        $trait_path          = base_path($path) . $data['module']['folder'] . '/Traits/';
        $trait_relative_path = str_replace(base_path(), '.', $trait_path);

        $meta = [
            'namespace'   => ucfirst(str_replace('/', '\\', $path)) . $data['module']['folder'] . '\\Traits',
            'controller'  => $controller . '\'s',
            'trait_class' => str_replace('Controller', '', $controller) . 'Trait',
            'model_class' => ucfirst(str_replace('/', '\\', $model_class)),
            'author'      => $this->utility->getConfig('author'),
            'date'        => date('Y-m-d H:i:s'),
        ];

        // 检查目录是否存在，不存在则创建
        if (! $this->filesystem->isDirectory($trait_path)) {
            $this->filesystem->makeDirectory($trait_path, 0777, true, true);
        }

        $trait_file          = $trait_path . "{$meta['trait_class']}.php";
        $trait_relative_file = $trait_relative_path . "{$meta['trait_class']}.php";

        if ($this->filesystem->isFile($trait_file) && ! $force) {
            $this->command->error('x ' . $trait_relative_file . ' is existed');

            return;
        }

        $content = $this->buildStub($meta, $this->getStub('controller-trait'));
        $this->filesystem->put($trait_file, $content);
        $this->command->info('+ ' . $trait_relative_file . ' (' . ($force ? 'Updated' : 'Added') . ')');
    }

    /**
     * 检查 BaseAction 是否存在，不存在则创建
     */
    public function checkAdminBaseAction(): void
    {
        $config = $this->utility->getConfig('controller');
        foreach ($config as $app => $controller) {
            $config_key    = 'controller.' . strtolower($app) . '.path';
            $path          = $this->utility->getControllerPath($config_key) . 'Traits';
            $relative_path = $this->utility->getControllerPath($config_key, true) . 'Traits';
            $base_file     = $path . '/BaseActionTrait.php';

            // 检查目录是否存在，不存在则创建
            if (! $this->filesystem->isDirectory($path)) {
                $this->filesystem->makeDirectory($path, 0777, true, true);
            }

            // 检查文件是否存在，不存在则创建
            if (! $this->filesystem->isFile($base_file)) {
                $data = [
                    'namespace'      => ucfirst(str_replace(['./', '/'], ['', '\\'], $relative_path)),
                    'base_resources' => $this->utility->getConfig('class.resources.base'),
                ];

                $content = $this->buildStub($data, $this->getStub($controller['trait_stub']));
                $this->filesystem->put($base_file, $content);
                $this->command->info("+ {$relative_path}/{$base_file} is created");
            }
        }
    }

    /**
     * 重建 字段的规则
     */
    private function rebuildFieldsRules(array $controller, array $fields, array $enums): array
    {
        $rules = ['enum_class' => []];

        // 获取所有模型，生成外键模型 ID 与 模型类名的对应数组
        $models_keys = $this->utility->getModelIds();
        $id_keys     = array_keys($models_keys);

        foreach ($fields as $field_name => $attr) {
            if (in_array($field_name, ['id', 'deleted_at', 'created_at', 'updated_at'])) {
                continue;
            }

            if (Str::startsWith($field_name, '_')) { // 去掉隐藏字段
                continue;
            }

            $filed_rules = [];
            if ($attr['required']) {
                $filed_rules[] = 'required';
            }

            if ($attr['allow_null']) {
                $filed_rules[] = 'nullable';
            }

            if (in_array($attr['type'], ['int', 'tinyint', 'bigint'])) {
                // 整数转浮点数时，需要调整为 numeric
                if (isset($attr['format']) && str_contains($attr['format'], 'float:')) {
                    $filed_rules[] = 'numeric';
                } elseif ($attr['type'] === 'bigint') {
                    $filed_rules[] = $this->utility->getConfig('snow_flake_id') ? 'numeric' : 'integer';
                } else {
                    $filed_rules[] = 'integer';
                }
            }

            if (str_contains($field_name, '_ids')) {
                $filed_rules[] = 'digital_array'; // 自定义的规则
            }

            if (in_array($attr['type'], ['char', 'varchar'])) {
                $filed_rules[] = 'string';
            }

            if ($attr['type'] === 'boolean' || $attr['type'] === 'bool') {
                $filed_rules[] = 'in:0,1';
            }

            if (in_array($attr['type'], ['date', 'datetime', 'timestamp'])) {
                $filed_rules[] = 'date';
            }

            if (isset($attr['min_size']) && in_array($attr['type'], ['char', 'varchar'])) {
                $filed_rules[] = "min:{$attr['min_size']}";
            }

            if (isset($attr['size']) && in_array($attr['type'], ['char', 'varchar'])) {
                $filed_rules[] = "max:{$attr['size']}";
            }

            if (isset($enums[$field_name])) {
                $enum_class            = str_replace(' ', '', ucwords(str_replace('_', ' ', $field_name)));
                $rules['enum_class'][] = $enum_class;
                $filed_rules[]         = "\$this->getInEnums(\$this->getValues('{$field_name}'))";
            }

            if (isset($attr['unique']) && $attr['unique']) {
                $filed_rules[] = "\$this->getUnique(\$this->getTable(), '{$field_name}')";
            }

            if (in_array($field_name, $id_keys, true)) {
                $filed_rules[] = "\$this->getExistId(\\{$models_keys[$field_name]['model']}::class)";
            }

            $rules[$field_name] = $filed_rules;
        }

        return $rules;
    }

    /**
     * 获取列表查询字段
     */
    private function getListFields(array $fields, bool $option = false, bool $trashed = false): string
    {
        $fields = array_keys($fields);

        // dump($fields);
        foreach ($fields as $k => &$value) {
            if (Str::startsWith($value, '_') or str_contains($value, 'password')) { // 去掉隐藏字段
                unset($fields[$k]);

                continue;
            }

            if (! $trashed) {
                if (in_array($value, ['deleted_at'])) {
                    unset($fields[$k]);

                    continue;
                }
            } else {
                if (in_array($value, ['updated_at'])) {
                    unset($fields[$k]);

                    continue;
                }
            }

            if ($option && in_array($value, ['id', 'created_at'])) { // 列表头，不要 id, created_at 列
                unset($fields[$k]);

                continue;
            }

            $value = "'{$value}'";
        }
        unset($value);

        if ($option) {  // 列表加入操作列
            $fields[] = "'options'";
        }

        return implode(', ', $fields);
    }

    /**
     * 获取查看查询字段
     */
    private function getShowFields(array $fields): string
    {
        $fields = array_keys($fields);
        $res    = [];
        foreach ($fields as $value) {
            if (Str::startsWith($value, '_') or str_contains($value, 'password')) { // 去掉隐藏字段
                continue;
            }
            $res[] = "'{$value}'";
        }

        return implode(', ', $res);
    }

    /**
     * 添加引号
     */
    private function addQuotation(array $rules, $field_name = null, $route_key = null): array
    {
        foreach ($rules as &$value) {
            if (str_contains($value, 'getDictKeys')) {
                continue;
            }

            if (str_contains($value, 'getUnique') || str_contains($value, 'getInEnums') !== false || str_contains($value, 'getExistId')) {
                if ($route_key === null) {
                    continue;
                }

                if (str_contains($value, 'getUnique')) { // 对编辑运行的 Unique 进行二次处理
                    $value = str_replace('\')', "', 'id')", $value);
                }
            } else {
                $value = "'{$value}'";
            }
        }
        unset($value);

        return $rules;
    }

    /**
     * 更新路由
     *
     * @throws FileNotFoundException
     */
    private function updateRoutes(array $created): void
    {
        if (empty($created)) {
            return;
        }

        $config = $this->utility->getConfig('controller');
        foreach ($config as $app => $controller) {
            $file          = base_path('/') . $controller['route'];
            $file_relative = "./{$controller['route']}";

            $file_txt = $this->filesystem->get($file);
            $codes    = [];
            foreach ($created as $item) {
                if ($item['app'] !== $app) {
                    continue;
                }

                $check_str = $item['entity'] . "', " . $item['namespace'] . $item['name'];
                if (str_contains($file_txt, $check_str)) {
                    continue;
                }

                $codes[] = "// Route::iResource('" . $item['entity'] . "', " . $item['namespace'] . $item['name'] . '::class);';
            }

            if (empty($codes)) {
                return;
            }

            $codes[]  = "\n\n" . $this->getTabs(1) . '//:insert_code_here:do_not_delete';
            $codes    = implode(PHP_EOL, $codes);
            $file_txt = str_replace('//:insert_code_here:do_not_delete', $codes, $file_txt);

            $this->filesystem->put($file, $file_txt);
            $this->command->warn('+ ' . $file_relative . ' (Updated)');
        }
    }
}
