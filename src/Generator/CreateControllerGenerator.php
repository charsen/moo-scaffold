<?php

namespace Charsen\Scaffold\Generator;

/**
 * Create Controller
 *
 * @author Charsen https://github.com/charsen
 */
class CreateControllerGenerator extends Generator
{

    protected $controller_path;
    protected $controller_relative_path;

    /**
     * @param      $schema_name
     * @param bool $force
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function start($schema_name, $force = false)
    {
        $this->controller_path          = $this->utility->getControllerPath();
        $this->controller_relative_path = $this->utility->getControllerPath(true);

        // 从 storage 里获取控制器数据，在修改了 schema 后忘了执行 scaffold:fresh 的话会不准确！！
        $all = $this->utility->getControllers(false);

        if (!isset($all[$schema_name])) {
            return $this->command->error("Schema File \"{$schema_name}\" could not be found.");
        }

        // 生成的 controllers
        $created  = [];

        //dump($all[$schema_name]);
        foreach ($all[$schema_name] as $class => $attr) {
           // $folders                 = "{$attr['package']['folder']}/{$attr['module']['folder']}/";
            $folders                 = "{$attr['module']['folder']}/";

            // 检查目录是否存在，不存在则创建
            if (!$this->filesystem->isDirectory($this->controller_path . $folders)) {
                $this->filesystem->makeDirectory($this->controller_path . $folders, 0777, true, true);
            }

            $controller_file          = $this->controller_path . $folders . "{$class}.php";
            $controller_relative_file = $this->controller_relative_path . "{$class}.php";

            if ($this->filesystem->isFile($controller_file) && ! $force) {
                $this->command->error('x Controller is existed (' . $controller_relative_file . ')');
                continue;
            }

            // Request, namespace 处理
            $request_name       = str_replace('Controller', 'Request', $class);
            //$request_class      = "App\\Http\\Requests\\{$attr['package']['folder']}\\{$attr['module']['folder']}\\{$request_name}";
            $request_class      = "App\\Http\\Requests\\{$attr['module']['folder']}\\{$request_name}";

            //$namespace          = "App\\Http\\Controllers\\{$attr['package']['folder']}\\{$attr['module']['folder']}";
            $namespace          = "App\\Http\\Controllers\\{$attr['module']['folder']}";
            $model_class        = $this->utility->getConfig('model.path') . $attr['module']['folder'] . '/' . $attr['model_class'];

            // 验证规则 ，字段 处理
            $table_attrs      = $this->utility->getOneTable($attr['table_name']);
            $fields           = $table_attrs['fields'];
            $dictionaries     = $table_attrs['dictionaries'];
            $rules            = $this->rebuildFieldsRules($fields, $dictionaries);

            $meta               = [
                'author'            => $this->utility->getConfig('author'),
                'date'              => date('Y-m-d H:i:s'),
                'package_name'      => $attr['package']['name'],
                'package_en_name'   => $attr['package']['folder'],
                'module_name'       => $attr['module']['name'],
                'module_en_name'    => $attr['module']['folder'],
                'entity_name'       => $attr['entity_name'],
                'entity_en_name'    => $class,
                'namespace'         => ucfirst($namespace),
                'route_key'         => strtolower($attr['model_class']),
                'model_class'       => ucfirst(str_replace('/', '\\', $model_class)),
                'model_name'        => $attr['model_class'],
                'request_class'     => $request_class,
                'request_name'      => $request_name,
                'form_widgets'      => $this->getFormWidgets($rules, $fields, $dictionaries)
            ];

            $this->filesystem->put($controller_file, $this->compileStub($meta));
            $this->command->info('+ ' . $controller_relative_file);

            // Request 处理
            $this->createRequest($rules, $meta['request_class'], $meta['request_name'], $meta['model_class'], $meta['route_key']);

            $created[] = [
                'namespace'         => $meta['module_en_name'] . '\\',
                'model'             => $meta['route_key'] . 's',
                'model_class'       => $meta['model_class'],
                'class'             => $class,
            ];
        }

        $this->updateRoutes($created);

        return true;
    }

    /**
     * 生成 Request
     *
     * @param array $rules
     * @param string $request_class
     * @param string $request_name
     * @param string $model_class
     * @param string $route_key
     * @return void
     */
    public function createRequest($rules, $request_class, $request_name, $model_class, $route_key)
    {
        // 检查目录是否存在，不存在则创建
        $tmp_folder = app_path() . '/' . str_replace(['App\\', '\\', $request_name], ['', '/', ''], $request_class);
        if (!$this->filesystem->isDirectory($tmp_folder)) {
            $this->filesystem->makeDirectory($tmp_folder, 0777, true, true);
        }

        $request_file          = $tmp_folder . $request_name . '.php';
        $request_relative_file = str_replace(base_path(), '.', $request_file);

        if ($this->filesystem->isFile($request_file)) {
            return $this->command->error('x Request is existed (' . $request_relative_file . ')');
        }

        // create & update action
        $create_code = ['['];
        $update_code = ['['];
        foreach ($rules as $field_name => $rule)
        {
            if (strstr($rule, ":'")) {    // $model->int_field 时拼接代码，结尾的 ' 提前了
                $tmp_create = "'{$field_name}' => '{$rule},";
                if (strstr($rule, "unique:")) {
                    $tmp_update = "'{$field_name}' => 'sometimes|{$rule} . ',{$field_name},' . \$this->route('{$route_key}'),";
                } else {
                    $tmp_update = "'{$field_name}' => 'sometimes|{$rule},";
                }
            } else {
                $tmp_create = "'{$field_name}' => '{$rule}',";
                $tmp_update = "'{$field_name}' => 'sometimes|{$rule}',";
            }

            $create_code[] = $this->getTabs(3) . $tmp_create;
            $update_code[] = $this->getTabs(3) . $tmp_update;
        }
        $create_code[] = $this->getTabs(2) . ']';
        $update_code[] = $this->getTabs(2) . ']';

        $meta = [
            'namespace'     => str_replace('\\' . $request_name, '', $request_class),
            'model_class'   => '\\' . $model_class,
            'request_name'  => $request_name,
            'store_rules'   => implode(PHP_EOL, $create_code),
            'update_rules'  => implode(PHP_EOL, $update_code),
        ];

        $file_txt = $this->buildStub($meta, $this->getStub('request'));

        $this->filesystem->put($request_file, $file_txt);
        return $this->command->info('+ ' . $request_relative_file);
    }

    /**
     * 重建 字段的规则
     *
     * @param  array $fields
     * @param array  $dictionaries_ids
     *
     * @return array
     */
    private function rebuildFieldsRules(array $fields, array $dictionaries)
    {
        $rules = [];
        foreach ($fields as $field_name => $attr) {
            if (in_array($field_name, ['id', 'deleted_at', 'created_at', 'updated_at'])) {
                continue;
            }

            $filed_rules        = [];
            if ($attr['require']) {
                $filed_rules[]  = 'required';
            }

            if ($attr['allow_null']) {
                $filed_rules[]  = 'nullable';
            }

            if (in_array($attr['type'], ['int', 'tinyint', 'bigint'])) {
                // 整数转浮点数时，需要调整为 numeric
                if (isset($attr['format']) && strstr($attr['format'], 'intval:')) {
                    $filed_rules[] = 'numeric';
                } else {
                    $filed_rules[] = 'integer';
                }

                if (isset($attr['unsigned']) && ! isset($dictionaries[$field_name])) {
                    $filed_rules[] = 'min:0';
                }
            }

            if ($attr['type'] == 'boolean') {
                $filed_rules[] = 'in:0,1';
            }

            if ($attr['type'] == 'date' || $attr['type'] == 'datetime') {
                $filed_rules[] = 'date';
            }

            if (isset($attr['min_size']) && in_array($attr['type'], ['char', 'varchar'])) {
                $filed_rules[] = 'min:' . $attr['min_size'];
            }

            if (isset($attr['size']) && in_array($attr['type'], ['char', 'varchar'])) {
                $filed_rules[] = 'max:' . $attr['size'];
            }

            if (isset($dictionaries[$field_name])) {
                $filed_rules[] = "in:' . implode(',', array_keys(\$model->init_{$field_name}))";
            }

            if (isset($attr['unique']) && $attr['unique']) {
                $filed_rules[] = "unique:' . \$model->getTable()";
            }

            $rules[$field_name] = implode('|', $filed_rules);
        }

        return $rules;
    }

    /**
     * 更新路由
     *
     * @param $builded_controllers
     *
     * @return bool
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    private function updateRoutes($created)
    {
        if (empty($created)) return true;

        $file          = $this->utility->getRouteFile('admin');
        $file_relative = $this->utility->getRouteFile('admin', true);
        $file_txt      = $this->filesystem->get($file);

        $code = [];
        foreach ($created as $controller) {
            if (strstr($file_txt, $controller['class'])) {
                continue;
            }

            $code[] = "Route::resourceHasTrashes('" . $controller['model'] . "', '" . $controller['namespace'] . $controller['class'] . "');";
        }

        if (empty($code)) {
            return true;
        }

        $code[]     = "\n\n" . $this->getTabs(1) . '//:insert_code_here:do_not_delete';
        $code       = implode("\n", $code);

        $file_txt   = str_replace("//:insert_code_here:do_not_delete", $code, $file_txt);

        $this->filesystem->put($file, $file_txt);
        $this->command->warn('+ ' . $file_relative . ' (Updated)');

        return true;
    }

    /**
     * 获取 创建/编辑 表单的控件代码
     *
     * @param       $repository_class
     * @param array $fields
     * @param array $dictionaries
     *
     * @return string
     */
    private function getFormWidgets($rules, array $fields, array $dictionaries)
    {
        if (empty($rules)) return "[];";

        $code = ["["];

        foreach ($rules as $field_name => $rule_string) {
            $code[] = $this->getTabs(3) . "[";
            $code[] = $this->getTabs(4) . "'field_name'    => '{$field_name}',";

            if (strstr($rule_string, 'sometimes') || strstr($rule_string, 'nullable')) {
                $code[] = $this->getTabs(4) . "'require'       => FALSE,";
            }

            // 字段以 _id 结尾的，一般是下拉选择
            if (preg_match('/[a-z]_id$/i', $field_name)) {
                $code[] = $this->getTabs(4) . "'widget_type'   => 'select',";
            }

            // 通过字段类型指定 控件类型
            if (isset($fields[$field_name])) {
                if ($fields[$field_name]['type'] == 'date') {
                    $code[] = $this->getTabs(4) . "'widget_type'   => 'date',";
                }

                if ($fields[$field_name]['type'] == 'timestamp') {
                    $code[] = $this->getTabs(4) . "'widget_type'   => 'datetime',";
                }
            }

            if (isset($dictionaries[$field_name])) {
                $code[] = $this->getTabs(4) . "'widget_type'   => 'radio',";
                $code[] = $this->getTabs(4) . "'options'       => \$this->model->init_{$field_name},";
            }

            $code[] = $this->getTabs(3) . "],";
        }

        $code[] = $this->getTabs(2) . "]";

        return implode("\n", $code);
    }

    /**
     * 编译模板
     *
     * @param $meta
     *
     * @return string
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    private function compileStub(array $meta)
    {
        return $this->buildStub($meta, $this->getStub('controller'));
    }
}
