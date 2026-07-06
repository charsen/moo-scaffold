<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2025-07-31 09:53
 * @Description: Create Controller
 */

namespace Mooeen\Scaffold\Generator;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Str;
use Mooeen\Scaffold\Foundation\FormRequest;
use Mooeen\Scaffold\Rules\Mobile;
use Mooeen\Scaffold\Rules\NumericArray;
use Mooeen\Scaffold\Utility;

use function in_array;

class CreateControllerGenerator extends Generator
{
    // controller 的基层目录
    protected string $base_path;

    /**
     * @throws FileNotFoundException
     */
    public function start(string $schema_name, bool $force = false, ?string $only_table = null)
    {
        $this->base_path = app_path('/');
        $all             = $this->utility->getControllers(false);

        if (! isset($all[$schema_name])) {
            $this->console()->error("未找到 schema 文件 \"{$schema_name}\"。");

            return false;
        }

        // plan-53 出身:包 schema 的 Controller/Request/Trait 落包目录(平铺),路由插包 routes/admin.php
        $origin = null;
        foreach ($all[$schema_name] as $attr0) {
            $origin = $attr0['origin'] ?? null;

            break;
        }
        $this->assertOriginWritable($origin);
        $this->originCtx = $this->originContext($origin);

        // 已生成的 controllers
        $created = [];
        $apps    = $this->utility->getApps();

        foreach ($all[$schema_name] as $class => $attr) {
            // moo:free --table 过滤:只生成指定表 key 的代码,其它表跳过
            if ($only_table !== null && $attr['table_name'] !== $only_table) {
                continue;
            }

            foreach ($apps as $app_folder => $app_name) {
                $app_folder    = strtolower($app_folder);
                $uc_app_folder = ucfirst($app_folder);

                // 控制器没配置的 app 不生产
                if (! in_array($app_folder, $attr['app'], true)) {
                    continue;
                }

                // 包 schema 固定 admin(包控制器挂 host admin 组,extra_modules 范式);其它 app 直接拒
                if ($this->originCtx !== null && $app_folder !== 'admin') {
                    $this->console()->error("扩展包 schema 的控制器固定 admin,不支持 app \"{$app_folder}\"(yaml controller.app 请改为 ['admin'])。");

                    continue;
                }

                // 检查目录是否存在，不存在则创建(包平铺,无 module folder 段)
                if ($this->originCtx !== null) {
                    $path = rtrim($this->originCtx->pathFor('controller'), '/');
                } else {
                    $path = $this->utility->getConfig("controller.{$app_folder}.path");
                    $path = base_path($path) . $attr['module']['folder'];
                }
                $this->checkDirectory($path);

                // namespace 前缀处理(包 = psr-4 根 + Http\Controllers\Admin)
                $config_key    = 'controller.' . $app_folder . '.path';
                $namespace_pre = $this->originCtx !== null
                    ? $this->originCtx->namespaceFor('controller') . '\\'
                    : $this->utility->formatNameSpace($this->utility->getControllerPath($config_key, true));

                // model 处理（要求 moo:model 已先执行，否则 getKeyName() 会失败）
                $model_class = $this->originCtx !== null
                    ? $this->originCtx->namespaceFor('model') . '\\' . $attr['model_class']
                    : $this->utility->formatNameSpace($this->utility->getModelPath(relative: true) . $attr['module']['folder'] . '/' . $attr['model_class']);

                // resource(包:落包 Resources 命名空间;未配 resource 时同 host 走基类)
                $resourceBasePath = $this->getModelResourcePath($app_folder, $attr);
                if ($resourceBasePath === null) {
                    $model_resource = $this->utility->getConfig('class.resources.base');
                } elseif ($this->originCtx !== null) {
                    $model_resource = $this->originCtx->namespaceFor('resource') . '\\' . $attr['model_class'] . 'Resource';
                } else {
                    $model_resource = $this->utility->formatNameSpace($resourceBasePath . $attr['module']['folder'] . '/' . $attr['model_class'] . 'Resource');
                }

                // 表格数据
                $table_attrs     = $this->utility->getOneTable($attr['table_name']);
                $fields          = $table_attrs['fields'];
                $enums           = $table_attrs['enums'];
                $controller_name = Utility::stripControllerSuffix($class);

                // 验证规则处理
                $rules = $this->rebuildFieldsRules($fields, $enums);

                // BaseActionTrait 恒指 host(包控制器复用 host 的,与 iResource 宏同款「host 提供约定」— moo-system 实证);
                // 包的 controller trait 平铺在包 Controllers/Admin/Traits 下
                if ($this->originCtx !== null) {
                    $hostAdminNsPre  = $this->utility->formatNameSpace($this->utility->getControllerPath('controller.admin.path', true));
                    $baseActionTrait = "{$hostAdminNsPre}Traits\\BaseActionTrait";
                    $controllerTrait = "{$namespace_pre}Traits\\{$controller_name}Trait";
                } else {
                    $baseActionTrait = "{$namespace_pre}Traits\\BaseActionTrait";
                    $controllerTrait = "{$namespace_pre}{$attr['module']['folder']}\\Traits\\{$controller_name}Trait";
                }

                // 2026-05-21：creator_id / updater_id 由 model 端 HasOperator trait 在
                // creating / updating 事件统一填充（CreateModelGenerator 自动注入）。
                // 控制器不再做内联审计填充。

                $meta = [
                    'author'                        => $this->utility->getConfig('author'),
                    'date'                          => date('Y-m-d H:i'),
                    'package_name'                  => $app_name,
                    'package_en_name'               => $uc_app_folder,
                    'module_name'                   => $attr['module']['name'],
                    'module_en_name'                => $attr['module']['folder'],
                    'table_name'                    => $attr['table_name'],
                    'entity_name'                   => $attr['entity_name'],
                    'entity_en_name'                => $attr['model_class'],
                    'namespace'                     => $this->originCtx !== null ? rtrim($namespace_pre, '\\') : "{$namespace_pre}{$attr['module']['folder']}",
                    'use_base_action'               => $baseActionTrait,
                    'use_controller_trait'          => $controllerTrait,
                    'use_base_controller'           => $this->utility->getConfig('class.controller'),
                    'use_base_resources'            => $this->utility->getConfig('class.resources.base'),
                    'use_base_resources_collection' => $this->utility->getConfig('class.resources.collection'),
                    'use_model_resource'            => $model_resource,
                    'use_model_collection'          => $this->utility->getConfig('class.resources.collection'), // $this->utility->formatNameSpace($model_collection),
                    'use_form_widgets'              => $this->utility->getConfig('class.resources.form'),
                    'use_columns'                   => $this->utility->getConfig('class.resources.columns'),
                    'use_table_columns'             => $this->utility->getConfig('class.resources.table_columns'),
                    'controller_name'               => $controller_name,
                    'list_fields'                   => $this->getListFields($fields),
                    'list_columns'                  => $this->getListFields($fields, true, $enums),
                    'form_layout_columns'           => $this->getFormLayoutColumns($rules),
                    'show_fields'                   => $this->getShowFields($fields),
                    'route_key'                     => strtolower(Str::snake($attr['model_class'], '-')),
                    'model_class'                   => $model_class,
                    'model_key_name'                => (new $model_class)->getKeyName(),
                    'model_name'                    => $attr['model_class'],
                ];

                // 生成 Request 文件
                $controller_file      = "{$path}/{$class}.php";
                $meta['use_requests'] = $this->buildRequest($app_folder, $rules, $enums, $table_attrs['index'], $meta, $controller_file, $force);
                $meta['use_requests'] = implode(PHP_EOL, $meta['use_requests']);

                // build controller trait
                $this->buildTrait($app_folder, $meta, $force);
                $meta['use_traits_code'] = $this->buildTraitUseCode($meta['use_base_action'], $meta['use_controller_trait']);

                // 生成 controller 文件
                $controller_relative_file = $this->relDisplay($controller_file, $this->originCtx);
                $controller_exists        = $this->filesystem->isFile($controller_file);
                if ($controller_exists && ! $force) {
                    $this->console()->exists($controller_relative_file, 'Controller 已存在');
                    $this->console()->newLine();

                    continue;
                }

                // build controller
                $stub    = $this->utility->getConfig('controller.' . $app_folder . '.stub');
                $content = $this->buildStub($meta, $this->getStub($stub));
                // 2026-05-20 audit hook 占位符独立行(L107/L123)+ 周围空行 = hook 空时残留 3+ 连续 whitespace-only 行
                // 折叠 3+ 连续空行(含 whitespace-only)为单个空行,保留 hook 有内容时的视觉间距
                $content = preg_replace('/(?:\n[ \t]*){3,}/', "\n\n", $content);
                $this->filesystem->put($controller_file, $content);
                if ($controller_exists) {
                    $this->console()->overwritten($controller_relative_file);
                } else {
                    $this->console()->created($controller_relative_file);
                }
                $this->console()->newLine();

                $created[] = [
                    'app'         => $app_folder,
                    'namespace'   => "\\{$meta['namespace']}\\",
                    'name'        => $meta['controller_name'] . 'Controller',
                    'entity'      => Str::plural($meta['route_key']),
                    'model_class' => $meta['model_class'],
                    'origin'      => $origin,
                ];
            }
        }

        // 更新路由文件内容
        $this->updateRoutes($created);

        return true;
    }

    /**
     * 获取当前 app 对应的 resource 路径
     */
    private function getModelResourcePath(string $app_folder, array $controller): ?string
    {
        $resourceApps = $controller['resource'] ?? [];

        if (in_array($app_folder, $resourceApps, true)) {
            return $this->utility->getAppResourcePath($app_folder, true);
        }

        return null;
    }

    /**
     * 生成 Request
     */
    public function buildRequest(string $app_folder, array $rules, array $enums, array $index, array $controller, string $controller_file, bool $force): array
    {
        // plan-53 出身:包的 Enums 命名空间平铺(无 module folder 段)
        if ($this->originCtx !== null) {
            $enum_namespace = $this->originCtx->namespaceFor('model') . '\\Enums\\';
        } else {
            $enum_namespace = $this->utility->getModelPath(relative: true) . $controller['module_en_name'] . '/Enums/';
            $enum_namespace = $this->utility->formatNameSpace($enum_namespace);
        }

        $use_codes = [];

        // 检查目录是否存在，不存在则创建
        // 包:src/Http/Requests/{Controller}/(无 module 段,moo-system 实证形态);host:{request_path}/{Module}/{Controller}/
        if ($this->originCtx !== null) {
            $folder    = rtrim($this->originCtx->pathFor('request'), '/') . "/{$controller['controller_name']}/";
            $namespace = $this->originCtx->namespaceFor('request') . "\\{$controller['controller_name']}\\";
        } else {
            $path      = $this->utility->getConfig("controller.{$app_folder}.request_path");
            $folder    = base_path($path) . "{$controller['module_en_name']}/{$controller['controller_name']}/";
            $namespace = ucfirst(str_replace([base_path() . '/', '/'], ['', '\\'], $folder));
        }
        $this->checkDirectory($folder);

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
            'use_enums'  => implode(PHP_EOL, $use_enums_code),
            'values'     => implode(PHP_EOL, $values),
            'options'    => implode(PHP_EOL, $options),
        ];
        $trait_file   = "{$folder}{$trait_name}.php";
        $trait_exists = $this->filesystem->isFile($trait_file);
        $this->filesystem->put($trait_file, $this->buildStub($meta, $this->getStub('request-base-trait')));
        if ($trait_exists) {
            $this->console()->updated($this->relDisplay($trait_file, $this->originCtx), 'Updated request trait');
        } else {
            $this->console()->created($this->relDisplay($trait_file, $this->originCtx), 'Created request trait');
        }

        // Request 文件可手动修改规则；trait 已存在且 controller 也存在说明是二次执行，
        // 跳过重新生成避免覆盖手动改动（force 例外）
        if ($trait_exists && $this->filesystem->isFile($controller_file) && ! $force) {
            return [];
        }

        // codegen 固定用 scaffold 自带的校验规则类（短名 new NumericArray / new Mobile 在生成代码里硬编码，
        // 此处仅生成对应 use 导入）；不再走 config 中转，业务系统无需也无法在此替换。
        $numericArrayClass = NumericArray::class;
        $mobileClass       = Mobile::class;

        // 按配置生成 Request
        $requests = $this->utility->getConfig('controller.' . $app_folder . '.requests');
        foreach ($requests as $one) {
            $one                   = ucfirst($one);
            $request_name          = $one . 'Request';
            $request_file          = $folder . $request_name . '.php';
            $request_relative_file = $this->relDisplay($request_file, $this->originCtx);
            $use_codes[]           = "use {$namespace}{$request_name};";
            $use_numeric_array     = '';
            $use_mobile            = '';
            $request_exists        = $this->filesystem->isFile($request_file);

            // 字段中带有 _ids 字符的，为多个 ID，要使用 NumericArray 规则
            $setNumericArray = static function (string $field_name) use (&$use_numeric_array, $numericArrayClass): void {
                if (str_contains($field_name, '_ids') && $use_numeric_array === '') {
                    $use_numeric_array = "use {$numericArrayClass};";
                }
            };

            // mobile / *_mobile 字段使用 Mobile 规则
            $setMobile = static function (string $field_name) use (&$use_mobile, $mobileClass): void {
                if (($field_name === 'mobile' || str_contains($field_name, '_mobile')) && $use_mobile === '') {
                    $use_mobile = "use {$mobileClass};";
                }
            };

            if ($request_exists && ! $force) {
                $this->console()->exists($request_relative_file, 'Request 已存在');

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
                    $setNumericArray($field_name);
                    $setMobile($field_name);
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

                    $setNumericArray($field_name);
                    $setMobile($field_name);
                }

                $codes[] = $this->getTabs(3) . "'page' => ['required', 'integer', 'min:1'],";
                $codes[] = $this->getTabs(3) . "'page_limit' => ['required', 'integer', 'min:1'],";
            } elseif (in_array($one, ['Destroy', 'Restore', 'DestroyBatch'])) {
                $setNumericArray('_ids');
                $codes[] = $this->getTabs(3) . "'ids' => ['required', new NumericArray],";
            }

            $codes[] = $this->getTabs(2) . ']';

            $meta = [
                'author'    => $this->utility->getConfig('author'),
                'date'      => date('Y-m-d H:i'),
                'namespace' => trim($namespace, '\\'),
                // 'model_class'      => $controller['model_class'],
                'use_base_request' => FormRequest::class,
                'use_custom_rules' => implode(PHP_EOL, array_filter([$use_numeric_array, $use_mobile])),
                // 'use_enums'        => implode(PHP_EOL, $use_enums_code),
                'request_name' => $request_name,
                'trait_name'   => $trait_name,
                'rules'        => implode(PHP_EOL, $codes),
                'options'      => implode(PHP_EOL, $options),
                'form_layout'  => in_array($one, ['Store', 'Update']) ? $this->getFormLayoutMethod($rules) : '',
            ];

            $content = $this->buildStub($meta, $this->getStub('request'));
            $this->filesystem->put($request_file, $content);
            if ($request_exists) {
                $this->console()->overwritten($request_relative_file);
            } else {
                $this->console()->created($request_relative_file);
            }
        }

        return $use_codes;
    }

    /**
     * 生成 controller 的 trait 代码文件
     */
    public function buildTrait(string $app, array $data, bool $force = false): void
    {
        // plan-53:包的 controller trait 平铺在包 Controllers/Admin/Traits/(无 module 段)
        if ($this->originCtx !== null) {
            $trait_path = rtrim($this->originCtx->pathFor('controller'), '/') . '/Traits/';
        } else {
            $path       = $this->utility->getConfig("controller.{$app}.path");
            $trait_path = base_path($path) . $data['module_en_name'] . '/Traits/';
        }
        $trait_relative_path = $this->relDisplay($trait_path, $this->originCtx);

        $meta = [
            'namespace'           => $data['namespace'] . '\\Traits',
            'controller_name'     => $data['controller_name'],
            'trait_class'         => $data['controller_name'] . 'Trait',
            'model_class'         => $data['model_class'],
            'model_name'          => $data['model_name'],
            'list_fields'         => $data['list_fields'],
            'list_columns'        => $data['list_columns'],
            'form_layout_columns' => $data['form_layout_columns'],
            'author'              => $data['author'],
            'date'                => $data['date'],
        ];

        // 检查目录是否存在，不存在则创建
        $this->checkDirectory($trait_path);

        $trait_file          = $trait_path . "{$meta['trait_class']}.php";
        $trait_relative_file = $trait_relative_path . "{$meta['trait_class']}.php";
        $trait_exists        = $this->filesystem->isFile($trait_file);

        if ($trait_exists && ! $force) {
            $this->console()->exists($trait_relative_file, 'Controller trait 已存在');

            return;
        }

        $content = $this->buildStub($meta, $this->getStub("controller-{$app}-trait"));
        $this->filesystem->put($trait_file, $content);
        if ($trait_exists) {
            $this->console()->overwritten($trait_relative_file);
        } else {
            $this->console()->created($trait_relative_file);
        }
    }

    /**
     * 生成 controller 中的 trait use 代码
     */
    private function buildTraitUseCode(string $baseActionTrait, string $controllerTrait): string
    {
        $indent              = $this->getTabs(1);
        $controllerTraitName = Str::afterLast($controllerTrait, '\\');
        $conflicts           = array_values(array_intersect(
            $this->getTraitMethodNames($baseActionTrait),
            $this->getTraitMethodNames($controllerTrait),
        ));

        if ($conflicts === []) {
            return implode(PHP_EOL, [
                "{$indent}use BaseActionTrait;",
                "{$indent}use {$controllerTraitName};",
            ]);
        }

        sort($conflicts);

        $lines = ["{$indent}use BaseActionTrait, {$controllerTraitName} {"];
        foreach ($conflicts as $method) {
            $lines[] = "{$indent}{$indent}BaseActionTrait::{$method} insteadof {$controllerTraitName};";
        }
        $lines[] = "{$indent}}";

        return implode(PHP_EOL, $lines);
    }

    /**
     * 获取 trait 方法名
     */
    private function getTraitMethodNames(string $traitClass): array
    {
        if (! trait_exists($traitClass)) {
            return [];
        }

        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass($traitClass))->getMethods(),
        );
        $methods = array_values(array_unique($methods));
        sort($methods);

        return $methods;
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
            $this->checkDirectory($path);

            // 检查文件是否存在，不存在则创建
            if (! $this->filesystem->isFile($base_file)) {
                $data = [
                    'namespace'      => $this->utility->formatNameSpace($relative_path),
                    'base_resources' => $this->utility->getConfig('class.resources.base'),
                ];

                $content = $this->buildStub($data, $this->getStub($controller['trait_stub']));
                $this->filesystem->put($base_file, $content);
                $this->console()->created("{$relative_path}/BaseActionTrait.php");
            }
        }
    }

    /**
     * 重建 字段的规则
     */
    private function rebuildFieldsRules(array $fields, array $enums): array
    {
        $rules = ['enum_class' => []];

        // 获取所有模型，生成外键模型 ID 与 模型类名的对应数组
        $models_keys = $this->utility->getModelIds();
        $id_keys     = array_keys($models_keys);

        foreach ($fields as $field_name => $attr) {
            // id / 时间戳 / 软删 + 操作人(creator_id / updater_id)都非用户输入:操作人由 model 端
            // HasOperator trait 在 creating/updating 事件自动填充(见上方 buildRequest 处 2026-05-21 注释),
            // Request 不该为它们生成校验规则;formLayout() 派生自 $rules,排除后表单也随之不含。
            if (in_array($field_name, ['id', 'deleted_at', 'created_at', 'updated_at', 'creator_id', 'updater_id'])) {
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

            // 2026-06-11 修:原来只认 int/tinyint/bigint,漏 smallint/mediumint/decimal/float/double
            // → 生成的 Request 对这些数值列零类型校验。inline 列表跟本文件其它类型判断保持一致风格。
            $isIntType   = in_array($attr['type'], ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'], true);
            $isFloatType = in_array($attr['type'], ['decimal', 'float', 'double'], true);
            if ($isIntType || $isFloatType) {
                // decimal/float/double → numeric;整数列 format float: → numeric;雪花 bigint → numeric;其余整型 → integer
                if ($isFloatType || (isset($attr['format']) && str_contains($attr['format'], 'float:'))) {
                    $filed_rules[] = 'numeric';
                } elseif ($attr['type'] === 'bigint') {
                    $filed_rules[] = $this->utility->getConfig('snow_flake_id') ? 'numeric' : 'integer';
                } else {
                    $filed_rules[] = 'integer';
                }

                // 无符号数字 >= 0(枚举字段不需要;整型 + 浮点同样适用)
                if ($attr['unsigned'] && ! isset($enums[$field_name])) {
                    $filed_rules[] = 'min:0';
                }
            }

            if (str_contains($field_name, '_ids')) {
                $filed_rules[] = 'new NumericArray'; // 自定义的规则
            }

            if ($field_name === 'mobile' || str_contains($field_name, '_mobile')) {
                $filed_rules[] = 'new Mobile'; // 自定义的规则（手机号）
            }

            // 字符串类型都加 string 规则;text 系列(text/tinytext/mediumtext/longtext)同样是字符串,
            // 原先漏掉 → 生成的 Request 里 text 字段缺 'string' 验证。max/min 仍只给 char/varchar(text 无 size)。
            if (in_array($attr['type'], ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'])) {
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
                // plan-40 §二 F8 P1 防御纵深:field_name 已经 SchemaLoader 严校 `^[a-z][a-z0-9_]*$`,
                // 双层保护下 caller 也 escape,跟 CreateModelGenerator C-6 修法对齐
                $fnEsc         = $this->escapePhpString($field_name);
                $filed_rules[] = "\$this->getInEnums(\$this->getValues('{$fnEsc}'))";
            }

            // plan-51:unique(app · 软删过滤)与 db_unique(DB 强约束)双语义都触发 Request 规则,
            // db-level 形态额外带 $soft=false 让 Request 验证跟 DB 行为对齐(跨软删唯一);
            // Update action 转换由 addQuotation 拦截 emit 形态 → 加 $route_key(详见该函数)
            $isAppUnique = ! empty($attr['unique']);
            $isDbUnique  = ! empty($attr['db_unique']);
            if ($isAppUnique || $isDbUnique) {
                $fnEsc         = $this->escapePhpString($field_name);
                $filed_rules[] = $isDbUnique
                    ? "\$this->getUnique(\$this->getTable(), '{$fnEsc}', null, false)"
                    : "\$this->getUnique(\$this->getTable(), '{$fnEsc}')";
            }

            if (in_array($field_name, $id_keys, true)) {
                $filed_rules[] = "\$this->getExistId(\\{$models_keys[$field_name]['model']}::class)";
            }

            $rules[$field_name] = $filed_rules;
        }

        return $rules;
    }

    /**
     * 获取表单布局的列
     */
    private function getFormLayoutColumns(array $rules): string
    {
        unset($rules['enum_class']);

        $code = [];
        foreach ($rules as $field_name => $rule) {
            if (str_contains($field_name, '_ids')) {
                continue; // 多个 ID 的字段不需要列
            }

            if (Str::startsWith($field_name, '_')) { // 去掉隐藏字段
                continue;
            }

            if (Str::endsWith($field_name, '_count')) { // 去掉隐藏字段
                continue;
            }

            if (in_array($field_name, ['id', 'created_at', 'updated_at', 'deleted_at'])) {
                continue; // 不需要列的字段
            }

            $code[] = $this->getTabs(3) . "['{$field_name}']";
        }

        if (! empty($code)) {
            $code[0] = trim($code[0]); // 去掉第一行的缩进空格
        }

        return implode(', ' . PHP_EOL, $code);
    }

    /**
     * 生成 formLayout() 方法代码
     */
    private function getFormLayoutMethod(array $rules): string
    {
        $columns = $this->getFormLayoutColumns($rules);

        if (empty($columns)) {
            return '';
        }

        return PHP_EOL . $this->getTabs(1) . 'public function formLayout(): array'
            . PHP_EOL . $this->getTabs(1) . '{'
            . PHP_EOL . $this->getTabs(2) . 'return ['
            . PHP_EOL . $this->getTabs(3) . $columns . ','
            . PHP_EOL . $this->getTabs(2) . '];'
            . PHP_EOL . $this->getTabs(1) . '}';
    }

    /**
     * 获取列表查询字段
     */
    private function getListFields(array $fields, bool $is_columns = false, array $enums = []): string
    {
        $excluded = ['updated_at', 'deleted_at'];
        if ($is_columns) {
            $excluded = [...$excluded, 'id', 'created_at'];
        }

        // 大文本类型不进列表查询字段（避免 SELECT 拖累、列表回包过大）
        $excluded_types = ['text', 'mediumtext', 'longtext'];

        $fields = array_filter(
            $fields,
            static function ($meta, string $name) use ($excluded, $excluded_types): bool {
                if (Str::startsWith($name, '_') || str_contains($name, 'password') || in_array($name, $excluded, true)) {
                    return false;
                }
                $type = is_array($meta) ? ($meta['type'] ?? null) : null;

                return ! in_array($type, $excluded_types, true);
            },
            ARRAY_FILTER_USE_BOTH,
        );
        $fields = array_values(array_keys($fields));

        $fields = array_map(static function (string $v) use ($is_columns, $enums): string {
            if ($is_columns && isset($enums[$v])) {
                $v .= '_txt';
            }

            return "'{$v}'";
        }, $fields);

        if ($is_columns) {
            return trim(implode(',' . PHP_EOL . $this->getTabs(3), $fields));
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

        // 一字段一行(对齐 getListColumns 的 list_columns 形态):首行由 stub 里 {{show_fields}}
        // 占位本身的缩进顶上,后续行各补 3 tab(getTabs(3)=12 空格)。
        // 配套:controller-*.stub 的 show() 已改成多行 `$columns = [\n  {{show_fields}}\n];`。
        return trim(implode(',' . PHP_EOL . $this->getTabs(3), $res));
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

            if (str_contains($value, 'new ')) {
                continue;
            }

            if (str_contains($value, 'getUnique') || str_contains($value, 'getInEnums') || str_contains($value, 'getExistId')) {
                if ($route_key === null) {
                    continue;
                }

                if (str_contains($value, 'getUnique')) {
                    // plan-51:Update action 转换 — 兼容 app(2 arg)与 db-unique(4 arg)两种 emit 形态
                    //   - app: getUnique('tbl','col')          → getUnique('tbl','col','id')
                    //   - db:  getUnique('tbl','col',null,false)→ getUnique('tbl','col','id',false)
                    if (str_contains($value, ', null, false)')) {
                        $value = str_replace(', null, false)', ", 'id', false)", $value);
                    } else {
                        $value = str_replace('\')', "', 'id')", $value);
                    }
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

        $this->console()->newLine();
        $this->console()->section('Updating routes');

        // plan-53 出身分流:host 条目插宿主 routes/{app}.php,包条目插包自己的 routes/admin.php
        // (包路由文件由包 Provider 挂 host admin 组,radar.php 这类手工特例工具不碰)
        $hostItems = array_filter($created, static fn (array $i): bool => ($i['origin'] ?? null) === null);
        $pkgItems  = array_filter($created, static fn (array $i): bool => ($i['origin'] ?? null) !== null);

        $config = $this->utility->getConfig('controller');
        foreach ($config as $app => $controller) {
            $items = array_values(array_filter($hostItems, static fn (array $i): bool => $i['app'] === $app));
            $this->insertRoutes(base_path('/') . $controller['route'], "./{$controller['route']}", $items);
        }

        if ($pkgItems !== []) {
            // 同一次 start() 只处理一个 schema = 单一出身;originCtx 即该包
            $file = $this->originCtx->pathFor('route');
            $this->insertRoutes($file, $this->relDisplay($file, $this->originCtx), array_values($pkgItems));
        }
    }

    /**
     * 把 iResource 路由行插到目标文件的 `:insert_code_here` 标记处(无新增行 / 文件缺失则跳过)。
     *
     * @param array<int,array<string,mixed>> $items
     */
    private function insertRoutes(string $file, string $file_relative, array $items): void
    {
        if ($items === []) {
            return;
        }
        if (! $this->filesystem->isFile($file)) {
            $this->console()->error("路由文件不存在:{$file_relative}(跳过插入,请补 `// :insert_code_here:do_not_delete` 标记)");

            return;
        }

        $file_txt = $this->filesystem->get($file);
        $codes    = [];
        foreach ($items as $item) {
            // $check_str = $item['entity'] . "', " . $item['namespace'] . $item['name'];
            $check_str = $item['namespace'] . $item['name'];
            if (str_contains($file_txt, $check_str)) {
                continue;
            }

            // plan-40 §二 F8 P1 防御纵深:entity / namespace / name 来自 yaml controller 配置,
            // 写到 routes/admin.php 的 PHP 字面量字符串里,escape 兜底防 quote 逃逸
            $entityEsc = $this->escapePhpString($item['entity']);
            $codes[]   = $this->getTabs(1) . "// {$item['name']}";
            $codes[]   = $this->getTabs(1) . "Route::iResource('" . $entityEsc . "', " . $item['namespace'] . $item['name'] . '::class);';
        }

        if (empty($codes)) {
            return;
        }

        $insert_holder = '// :insert_code_here:do_not_delete';
        if (! str_contains($file_txt, $insert_holder)) {
            $this->console()->error("路由文件缺插入标记:{$file_relative}(请加一行 `{$insert_holder}` 后重跑)");

            return;
        }
        $codes[0] = trim($codes[0]); // 去掉第一行的缩进空格
        $codes[]  = PHP_EOL;
        $codes[]  = $this->getTabs(1) . $insert_holder;

        $file_txt = str_replace($insert_holder, implode(PHP_EOL, $codes), $file_txt);
        $this->filesystem->put($file, $file_txt);
        $this->console()->updated($file_relative);
    }
}
