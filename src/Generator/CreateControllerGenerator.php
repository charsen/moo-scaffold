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

        if (!isset($all[$schema_name]))
        {
            return $this->command->error("Schema File \"{$schema_name}\" could not be found.");
        }

        // 所有字段信息
        $fields             = $this->utility->getFields();
        // 字典数据
        $dictionaries       = $this->utility->getDictionaries();
        // 生成的 controllers
        $build_controllers  = [];

        //dump($all[$schema_name]);
        foreach ($all[$schema_name] as $class => $attr)
        {
            $folders                 = "{$attr['package']['folder']}/{$attr['module']['folder']}/";

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

            // 目录及 namespace 处理
            $request_class      = "App\\Http\\Requests\\{$attr['package']['folder']}\\{$attr['module']['folder']}\\{$attr['model_class']}Request";
            $namespace          = "App\\Http\\Controllers\\{$attr['package']['folder']}\\{$attr['module']['folder']}";
            $model_class        = $this->utility->getConfig('model.path') . $attr['module']['folder'] . '/' . $attr['model_class'];

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
                'model_class'       => ucfirst(str_replace('/', '\\', $model_class)),
                'model_name'        => $attr['model_class'],
                'request_class'     => $request_class,
                'request_name'      => $attr['model_class'] . 'Request',
                'form_widgets'      => '[]', //$this->getFormWidgets($model_class, $fields, $dictionaries)
            ];

            $build_controllers[] = [
                'namespace'         => $meta['package_en_name'] . '\\' . $meta['module_en_name'] . '\\',
                'model'             => strtolower($attr['model_class']) . 's',
                'class'             => $class,
            ];

            $this->filesystem->put($controller_file, $this->compileStub($meta));
            $this->command->info('+ ' . $controller_relative_file);
        }

        $this->updateRoutes($build_controllers);
    }

    /**
     * 更新路由
     *
     * @param $build_controllers
     *
     * @return bool
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    private function updateRoutes($build_controllers)
    {
        $file       = base_path('routes/api.php');
        $file_txt   = $this->filesystem->get($file);

        $code = [];
        foreach ($build_controllers as $controller)
        {
            if (strstr($file_txt, $controller['class']))
            {
                continue;
            }

            $code[] = "Route::resourceHasTrashes('" . $controller['model'] . "', '" . $controller['namespace'] . $controller['class'] . "');";
        }

        if (empty($code))
        {
            return true;
        }

        $code[]     = "\n\n" . '//:end:do_not_delete';
        $code       = implode("\n", $code);

        $file_txt   = str_replace("//:end:do_not_delete", $code, $file_txt);

        $this->filesystem->put($file, $file_txt);
        $this->command->warn('+ ./app/routes/api.php (Updated)');

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
    private function getFormWidgets($repository_class, array $fields, array $dictionaries)
    {
        $rules = $this->getRules($repository_class);
        if ( ! isset($rules['create']))
        {
            return "[];";
        }

        $code = ["["];

        foreach ($rules['create'] as $field_name => $rule_string)
        {
            $code[] = $this->getTabs(3) . "[";
            $code[] = $this->getTabs(4) . "'field_name'    => '{$field_name}',";

            if (strstr($rule_string, 'sometimes') || strstr($rule_string, 'nullable'))
            {
                $code[] = $this->getTabs(4) . "'require'       => FALSE,";
            }

            // 字段以 _id 结尾的，一般是下拉选择
            if (preg_match('/[a-z]_id$/i', $field_name))
            {
                $code[] = $this->getTabs(4) . "'widget_type'   => 'select',";
            }

            // 通过字段类型指定 控件类型
            if (isset($fields[$field_name]))
            {
                if ($fields[$field_name]['type'] == 'date')
                {
                    $code[] = $this->getTabs(4) . "'widget_type'   => 'date',";
                }

                if ($fields[$field_name]['type'] == 'timestamp')
                {
                    $code[] = $this->getTabs(4) . "'widget_type'   => 'datetime',";
                }
            }

            if (isset($dictionaries[$field_name]))
            {
                $code[] = $this->getTabs(4) . "'widget_type'   => 'radio',";
                $code[] = $this->getTabs(4) . "'options'       => \$this->repository->getModel()->init_{$field_name},";
            }

            $code[] = $this->getTabs(3) . "],";
        }

        $code[] = $this->getTabs(2) . "]";

        return implode("\n", $code);
    }

    /**
     * @param $repository_class
     *
     * @return mixed
     */
    private function getRules($repository_class)
    {
        $rules      = (new $repository_class(app()))->getRules();

        return $rules;
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
