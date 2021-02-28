<?php

namespace Charsen\Scaffold\Generator;

use Illuminate\Support\Arr;
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

        if (!isset($all[$schema_name]))
        {
            return $this->command->error("Schema File \"{$schema_name}\" could not be found.");
        }

        // 生成的 controllers
        $created  = [];

        //dump($all[$schema_name]);
        foreach ($all[$schema_name] as $class => $attr) {
           // $folders                 = "{$attr['package']['folder']}/{$attr['module']['folder']}/";
            $folders                 = "{$attr['module']['folder']}/";

            // 检查目录是否存在，不存在则创建
            if (!$this->filesystem->isDirectory($this->controller_path . $folders))
            {
                $this->filesystem->makeDirectory($this->controller_path . $folders, 0777, true, true);
            }

            $controller_file          = $this->controller_path . $folders . "{$class}.php";
            $controller_relative_file = $this->controller_relative_path . "{$class}.php";

            if ($this->filesystem->isFile($controller_file) && ! $force)
            {
                $this->command->error('x Controller is existed (' . $controller_relative_file . ')');
                continue;
            }

            // Request, namespace 处理
            $request_name     = str_replace('Controller', 'Request', $class);
            //$request_class      = "App\\Http\\Requests\\{$attr['package']['folder']}\\{$attr['module']['folder']}\\{$request_name}";
            $request_class    = "App\\Http\\Requests\\{$attr['module']['folder']}\\{$request_name}";

            //$namespace          = "App\\Http\\Controllers\\{$attr['package']['folder']}\\{$attr['module']['folder']}";
            //$namespace        = "App\\Http\\Controllers\\{$attr['module']['folder']}";
            $model_class      = $this->utility->getConfig('model.path') . $attr['module']['folder'] . '/' . $attr['model_class'];
            $trait_class      = $this->utility->getConfig('model.path') . $attr['module']['folder'] . '/Traits/' . $attr['model_class'] . 'Trait';

            // 验证规则 ，字段 处理
            $table_attrs      = $this->utility->getOneTable($attr['table_name']);
            $fields           = $table_attrs['fields'];
            $dictionaries     = $table_attrs['dictionaries'];
            $rules            = $this->rebuildFieldsRules($fields, $dictionaries);
            $model_class      = ucfirst(str_replace('/', '\\', $model_class));

            $meta               = [
                'author'              => $this->utility->getConfig('author'),
                'date'                => date('Y-m-d H:i:s'),
                'package_name'        => $attr['package']['name'],
                'package_en_name'     => $attr['package']['folder'],
                'module_name'         => $attr['module']['name'],
                'module_en_name'      => $attr['module']['folder'],
                'entity_name'         => $attr['entity_name'],
                'entity_en_name'      => str_replace('Controller', '', $class),
                'namespace'           => $attr['namespace'],
                'use_base_action'     => 'App\\Http\\Controllers\\Traits\\BaseActionTrait',     // TODO: 下个大版本优化！
                'use_base_controller' => $this->config('class.controller'),
                'use_base_resources'  => $this->config('class.resources.base'),
                'use_form_widgets'    => $this->config('class.resources.form'),
                'use_columns'         => $this->config('class.resources.columns'),
                'use_table_columns'   => $this->config('class.resources.table_columns'),
                'class'               => $class,
                'index_fields'        => $this->getListFields($fields),
                'show_fields'         => $this->getShowFields($fields),
                'trashed_fields'      => $this->getListFields($fields, true),
                'route_key'           => strtolower($attr['model_class']),
                'model_class'         => $model_class,
                'model_key_name'      => (new $model_class)->getKeyName(),
                'model_name'          => $attr['model_class'],
                'request_class'       => $request_class,
                'request_name'        => $request_name,
                'form_widgets'        => $this->getFormWidgets($rules, $fields, $dictionaries)
            ];

            $this->filesystem->put($controller_file, $this->compileStub($meta));
            $this->command->info('+ ' . $controller_relative_file);

            // Request 处理
            $this->createRequest($rules, $meta['request_class'], $meta['request_name'], $trait_class, $meta['route_key']);

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
     * 获取列表查询字段
     *
     * @param array $fields
     * @param boolean $trashed
     * @return array
     */
    private function getListFields($fields, $trashed = false)
    {
        $fields = array_keys($fields);
        if ( ! $trashed) {
            unset($fields['deleted_at'], $fields['created_at']);
        } else {
            unset($fields['updated_at'], $fields['created_at']);
        }

        // dump($fields);
        foreach ($fields as $k => &$value) {
            if ( ! $trashed) {
                if (in_array($value, ['deleted_at', 'created_at'])) {
                    unset ($fields[$k]);
                    continue;
                }
            } else {
                if (in_array($value, ['created_at', 'updated_at'])) {
                    unset ($fields[$k]);
                    continue;
                }
            }

            $value = "'{$value}'";
        }

        // 加入操作列
        $fields[] = "'options'";

        return implode(', ', $fields);
    }

    /**
     * 获取查看查询字段
     *
     * @param array $fields
     * @return array
     */
    private function getShowFields($fields)
    {
        $fields = array_keys($fields);

        foreach ($fields as $k => &$value)
        {
            $value = "'{$value}'";
        }

        return implode(', ', $fields);
    }

    /**
     * 生成 Request
     *
     * @param array $rules
     * @param string $request_class
     * @param string $request_name
     * @param string $trait_class
     * @param string $route_key
     * @return void
     */
    public function createRequest($rules, $request_class, $request_name, $trait_class, $route_key)
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
        foreach ($rules as $field_name => $rules)
        {
            $tmp_create     = "'{$field_name}' => [" . implode(', ', $this->addQuotation($rules)) ."],";

            if ( ! \in_array('nullable', $rules)) {
                array_unshift($rules, 'required');
            }

            foreach ($rules as $k => $v) {
                if ($v == 'required') {
                    unset($rules[$k]);
                }
            }
            $tmp_update     = "'{$field_name}' => [" . implode(', ', $this->addQuotation($rules, $field_name, $route_key)) ."],";

            $create_code[]  = $this->getTabs(3) . $tmp_create;
            $update_code[]  = $this->getTabs(3) . $tmp_update;
        }
        $create_code[] = $this->getTabs(2) . ']';
        $update_code[] = $this->getTabs(2) . ']';

        $meta = [
            'namespace'        => str_replace('\\' . $request_name, '', $request_class),
            'use_base_request' => $this->config('class.form_request'),
            'request_name'     => $request_name,
            'model_trait'      => ucfirst(str_replace('/', '\\', $trait_class)),
            'model_trait_name' => Arr::last(explode('/', $trait_class)),
            'store_rules'      => implode(PHP_EOL, $create_code),
            'update_rules'     => implode(PHP_EOL, $update_code),
        ];

        $file_txt = $this->buildStub($meta, $this->getStub('request'));

        $this->filesystem->put($request_file, $file_txt);
        return $this->command->info('+ ' . $request_relative_file);
    }

    /**
     * 添加引号
     *
     * @param  $rules
     * @param  $field_name
     * @param  $route_key
     * @return array
     */
    private function addQuotation($rules, $field_name = null, $route_key = null)
    {
        foreach ($rules as &$value) {
            if (strstr($value, 'getDictKeys')) {
                continue;
            }

            if (strstr($value, 'getUnique') || strstr($value, 'getInDict')) {
                if ($route_key == null) {
                    continue;
                }
                else if (strstr($value, 'getUnique')) {
                    // 对编辑运作的 Unique 进行二次处理
                    $value = "\$this->getUnique('{$field_name}', '{$route_key}')";
                }
            } else {
                $value = "'{$value}'";
            }
        }
        return $rules;
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

            if (strstr($field_name, '_ids')) {
                $filed_rules[] = 'array';
            }

            if (in_array($attr['type'], ['char', 'varchar'])) {
                $filed_rules[] = 'string';
            }

            if ($attr['type'] == 'boolean') {
                $filed_rules[] = 'in:0,1';
            }

            if ($attr['type'] == 'date' || $attr['type'] == 'datetime') {
                $filed_rules[] = 'date';
            }

            if (isset($attr['min_size']) && in_array($attr['type'], ['char', 'varchar'])) {
                $filed_rules[] = "min:{$attr['min_size']}";
            }

            if (isset($attr['size']) && in_array($attr['type'], ['char', 'varchar'])) {
                $filed_rules[] = "max:{$attr['size']}";
            }

            if (isset($dictionaries[$field_name])) {
                $filed_rules[] = "\$this->getInDict('{$field_name}')";
            }

            if (isset($attr['unique']) && $attr['unique']) {
                $filed_rules[] = '$this->getUnique()';
            }

            $rules[$field_name] = $filed_rules;
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

        if ($this->filesystem->isFile($file))
        {
            $file_txt      = $this->filesystem->get($file);
        }
        else
        {
            $file_txt      = '<?php' . PHP_EOL
                           . 'use Illuminate\Support\Facades\Route;' . PHP_EOL . PHP_EOL
                           . '//:insert_code_here:do_not_delete';
        }

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
     * @param       $all_rules
     * @param array $fields
     * @param array $dictionaries
     *
     * @return string
     */
    private function getFormWidgets($all_rules, array $fields, array $dictionaries)
    {
        if (empty($all_rules)) return "[];";

        $code = ["["];
        foreach ($all_rules as $field_name => $rules) {
            $code[] = $this->getTabs(3) . "[";
            $code[] = $this->getTabs(4) . "'field_name'    => '{$field_name}',";

            if (isset($rules['sometimes']) OR isset($rules['nullable'])) {
                $code[] = $this->getTabs(4) . "'require'       => FALSE,";
            }

            // 字段以 _id 结尾的，一般是下拉选择
            if (preg_match('/[a-z]_id$/i', $field_name)) {
                $code[] = $this->getTabs(4) . "'type'   => 'select',";
            }

            // 通过字段类型指定 控件类型
            if (isset($fields[$field_name])) {
                if ($fields[$field_name]['type'] == 'date') {
                    $code[] = $this->getTabs(4) . "'type'   => 'date',";
                }

                if ($fields[$field_name]['type'] == 'timestamp') {
                    $code[] = $this->getTabs(4) . "'type'   => 'datetime',";
                }
            }

            if (isset($dictionaries[$field_name])) {
                $code[] = $this->getTabs(4) . "'type'   => 'radio',";
                $code[] = $this->getTabs(4) . "'dictionary'    => true,";
                $code[] = $this->getTabs(4) . "'options'       => \$this->model->init_{$field_name},";
            }

            $code[] = $this->getTabs(4) . "'rules'         => \$rules['{$field_name}'] ?? [],";

            $code[] = $this->getTabs(3) . "],";
        }

        $code[] = $this->getTabs(2) . "]";

        return implode("\n", $code);
    }

    public function buildTrait($controller, $data, $force)
    {
        $model_class      = $this->utility->getConfig('model.path') . $data['module']['folder'] . '/' . $data['model_class'];

        $meta = [
            'namespace'     => $data['namespace'] . '\\Traits',
            'controller'    => $controller . '\'s',
            'trait_class'   => str_replace('Controller', '', $controller) . 'Trait',
            'model_class'   => str_replace('/', '\\', $model_class),
            'author'        => $this->utility->getConfig('author'),
            'date'          => date('Y-m-d H:i:s'),
        ];

        $folders                   = "{$data['module']['folder']}/";
        $this->trait_path          = $this->utility->getControllerPath() . $folders . 'Traits/';
        $this->trait_relative_path = $this->utility->getControllerPath(true) . $folders . 'Traits/';

        // 检查目录是否存在，不存在则创建
        if (!$this->filesystem->isDirectory($this->trait_path))
        {
            $this->filesystem->makeDirectory($this->trait_path, 0777, true, true);
        }

        $trait_file          = $this->trait_path . "{$meta['trait_class']}.php";
        $trait_relative_file = $this->trait_relative_path . "{$meta['trait_class']}.php";

        if ($this->filesystem->isFile($trait_file) && ! $force)
        {
            return $this->command->error('x Controller\'s Trait is existed (' . $trait_relative_file . ')');
        }

        $this->filesystem->put($trait_file, $this->compileTraitStub($meta));
        $this->command->warn('+ ' . $trait_relative_file . ' (' . ($force ? 'Updated' : 'Added') . ')');
    }

    /**
     * 检查 BaseAction 是否存在，不存在则创建
     * @return void
     */
    public function buildBaseAction()
    {
        $path       = $this->utility->getControllerPath() . 'Traits/';
        $base_file  = $path . 'BaseActionTrait.php';

        // 检查目录是否存在，不存在则创建
        if (!$this->filesystem->isDirectory($path))
        {
            $this->filesystem->makeDirectory($path, 0777, true, true);
        }

        // 检查文件是否存在，不存在则创建
        if ( ! $this->filesystem->isFile($base_file))
        {
            $data      = [
                'namespace' => str_replace('/', '\\', 'App/Http/Controllers/Traits')
            ];

            $content = $this->buildStub($data, $this->getStub('base-action-trait'));
            $this->filesystem->put($base_file, $content);

            $this->command->info('+ ' . $this->utility->getControllerPath(true) . '/Traits/BaseActionTrait.php');
        }

        return true;
    }

    /**
     * 编译 Trait 模板
     *
     * @param $meta
     *
     * @return string
     */
    public function compileTraitStub(array $meta)
    {
        return $this->buildStub($meta, $this->getStub('controller-trait'));
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
