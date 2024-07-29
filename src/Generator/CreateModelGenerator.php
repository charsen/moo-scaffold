<?php

namespace Mooeen\Scaffold\Generator;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

use function in_array;

/**
 * Create Model
 *
 * @author Charsen https://github.com/charsen
 */
class CreateModelGenerator extends Generator
{
    protected string $model_path;

    protected string $model_relative_path;

    protected string $factory_path;

    protected string $base_namespace;

    /**
     * @throws FileNotFoundException
     */
    public function start(string $schema_name, bool $force = false, bool $factory = false): bool
    {
        $this->model_path          = $this->utility->getModelPath();
        $this->model_relative_path = $this->utility->getModelPath(true);
        $this->base_namespace      = ucfirst(str_replace(['./', '/'], ['', '\\'], $this->model_relative_path));
        $this->factory_path        = database_path('factories/');

        $all = $this->filesystem->getRequire($this->utility->getStoragePath() . 'models.php');

        if (! isset($all[$schema_name])) {
            $this->command->error("Schema File \"{$schema_name}\" could not be found.");

            return false;
        }

        $this->checkBaseFilter();
        $this->checkBaseTraitFiles();

        foreach ($all[$schema_name] as $class => $attr) {
            $model_path    = $this->model_path . $attr['module']['folder'];
            $model_file    = $model_path . "/{$class}.php";
            $relative_file = $this->model_relative_path . $attr['module']['folder'] . "/{$class}.php";

            // Model 目录检查，不存在则创建
            $this->checkDirectory($model_path);

            $table_attr = $this->utility->getOneTable($attr['table_name']);

            // Model 目录及 namespace 处理
            $trait_class     = "{$class}Trait";
            $filter_class    = "{$class}Filter";
            $namespace       = $this->base_namespace . $attr['module']['folder'];
            $trait_namespace = $namespace . '\Traits';

            // model trait 部分代码处理
            $field_codes  = $this->prepareFieldCode($namespace, $table_attr['enums'], $table_attr['fields']);
            $get_float_fn = $this->getFloatAttribute($table_attr['fields']);

            // 检查是否存在，存在则不更新
            if ($this->filesystem->isFile($model_file) && ! $force) {
                $this->command->error('x Model is existed (' . $relative_file . ')');

                // 生成对应的 Filter
                $this->buildFilter($model_path, $namespace, $filter_class, $table_attr['index'], $table_attr['enums'], $force);

                // 生成对应的 Trait，即使无 'get_txt_fn' 也生成，因验证时要用到
                $this->buildTrait($model_path, $trait_namespace, $trait_class, $attr['table_name'], $field_codes, $get_float_fn);

                // 生成对应的 Enum
                $this->buildEnum($table_attr['name'], $model_path, $namespace, $table_attr['enums'], $table_attr['fields']);

                // 生成对应的 factory 文件并更新 Seeder
                if ($factory) {
                    $this->buildFactory($attr['module']['folder'], $class, $namespace, $table_attr['fields'], $table_attr['enums'], $force);
                }

                $this->command->newLine();

                continue;
            }

            // 生成 model
            $this->buildModel($model_path, $class, $namespace, $attr, $table_attr, $field_codes, $factory);

            // 生成对应的 Filter
            $this->buildFilter($model_path, $namespace, $filter_class, $table_attr['index'], $table_attr['enums'], $force);

            // 生成对应的 Trait，即使无 'get_txt_fn' 也生成，因验证时要用到
            $this->buildTrait($model_path, $trait_namespace, $trait_class, $attr['table_name'], $field_codes, $get_float_fn);

            // 生成对应的 Enum
            $this->buildEnum($table_attr['name'], $model_path, $namespace, $table_attr['enums'], $table_attr['fields']);

            // 生成对应的 factory 文件并更新 Seeder
            if ($factory) {
                $this->buildFactory($attr['module']['folder'], $class, $namespace, $table_attr['fields'], $table_attr['enums'], $force);
            }

            $this->command->newLine();
        }

        return true;
    }

    /**
     * 创建 model 文件
     */
    private function buildModel(string $model_path, string $class, string $namespace, array $schema, array $table_attr, array $field_codes, bool $factory): void
    {
        // 文件处理
        $model_file    = $model_path . "/{$class}.php";
        $relative_file = $this->model_relative_path . $schema['module']['folder'] . "/{$class}.php";

        // model 文件代码处理
        $use_trait = ['Filterable'];
        $use_class = ['use EloquentFilter\Filterable;'];

        // Model Trait
        $use_trait[] = "{$class}Trait";
        $use_class[] = "use {$namespace}\\Traits\\{$class}Trait;";

        // Model Filter
        $use_class[] = "use {$namespace}\Filters\\{$class}Filter;";

        if ($factory) {
            $use_trait[] = 'HasFactory';
            $use_class[] = 'use Illuminate\Database\Eloquent\Factories\HasFactory;';
        }

        // 时间序列化
        $use_trait[] = 'GetSerializeDate';
        $use_class[] = "use {$this->base_namespace}Traits\GetSerializeDate;";

        // 人性化 更新于 时间
        if (isset($table_attr['fields']['updated_at'])) {
            $use_trait[] = 'GetUpdatedAtHumanTime';
            $use_class[] = "use {$this->base_namespace}Traits\GetUpdatedAtHumanTime;";
        }

        // Optional Trait
        $use_trait[] = 'Optional';
        $use_class[] = "use {$this->base_namespace}Traits\Optional;";

        // 雪花算法 ID
        if ($this->utility->getConfig('snow_flake_id')) {
            $use_trait[] = 'UsingSnowFlakePrimaryKey';
            $use_class[] = "use {$this->base_namespace}Traits\UsingSnowFlakePrimaryKey;";
        }

        // 软删除
        if (isset($table_attr['fields']['deleted_at'])) {
            $use_trait[] = 'SoftDeletes';
            $use_class[] = 'use Illuminate\Database\Eloquent\SoftDeletes;';
        }

        $meta = [
            'author'        => $this->utility->getConfig('author'),
            'date'          => date('Y-m-d H:i:s'),
            'property_code' => $this->getPropertyCode($table_attr['fields']),
            'namespace'     => $namespace,
            'use_class'     => implode(PHP_EOL, $use_class),
            'use_trait'     => $this->getModelUseTrait($use_trait),
            'class'         => $class,
            'filter'        => "{$class}Filter",
            'class_name'    => $table_attr['name'] . '模型',
            'table_name'    => $schema['table_name'],
            'casts'         => $this->getCasts($table_attr['fields']),
            'appends'       => $this->getAppends($field_codes['appends']),
            'hidden'        => $this->getHidden($table_attr['fields']),
            'fillable'      => $this->getFillable($table_attr['fields']),
            'attributes'    => $this->getModelAttributes($table_attr['fields']),
        ];

        // 生成 model 文件
        $content = $this->buildStub($meta, $this->getStub('model'));
        $this->filesystem->put($model_file, $content);
        $this->command->info('+ ' . $relative_file);
    }

    /**
     * 生成 Trait 文件
     */
    private function buildTrait(string $path, string $namespace, string $class, string $table_name, array $field_codes, string $get_float_fn): void
    {
        $path .= '/Traits/';
        $this->checkDirectory($path);

        $trait_file          = $path . $class . '.php';
        $trait_relative_file = str_replace(base_path(), '.', $trait_file);

        $meta = [
            'trait_namespace' => $namespace,
            'trait_class'     => $class,
            'use_class'       => implode(PHP_EOL, $field_codes['trait_use_class']),
            'table_name'      => $table_name,
            'get_txt_fn'      => $field_codes['get_txt_fn'],
            'get_float_fn'    => $get_float_fn,
        ];

        $content = $this->buildStub($meta, $this->getStub('model-trait'));
        $this->filesystem->put($trait_file, $content);
        $this->command->info('+ ' . $trait_relative_file);
    }

    /**
     * 生成 model 的 Enum 文件
     */
    public function buildEnum(string $model_name, string $model_path, string $namespace, array $enums, $fields): void
    {
        // 检查目录是否存在，不存在则创建
        $enum_path = $model_path . '/Enums/';
        $this->checkDirectory($enum_path);

        foreach ($enums as $field_name => $values) {
            $case_codes    = [];
            $case_labels   = [];
            $enum_class    = str_replace(' ', '', ucwords(str_replace('_', ' ', $field_name)));
            $enum_file     = $enum_path . $enum_class . '.php';
            $relative_file = str_replace(base_path(), '', $enum_file);

            foreach ($values as $alias => $item) {
                $new_alias     = strtoupper($alias);
                $case_codes[]  = $this->getTabs(1) . "case {$new_alias} = {$item[0]};";
                $case_labels[] = $this->getTabs(3) . "self::{$new_alias} => __('model.{$field_name}_{$alias}'),";
            }

            $data = [
                'namespace'   => $namespace . '\Enums',
                'model_name'  => $model_name,
                'field_name'  => $fields[$field_name]['name'],
                'trait_class' => $enum_class,
                'case_codes'  => implode(PHP_EOL, $case_codes),
                'case_labels' => implode(PHP_EOL, $case_labels),
            ];

            $content = $this->buildStub($data, $this->getStub('model-enum-trait'));
            $this->filesystem->put($enum_file, $content);

            $this->command->info('+ .' . $relative_file);
        }
    }

    /**
     * 生成 model 的 filter 文件
     */
    public function buildFilter(string $model_path, string $namespace, string $filter_class, array $index, array $enums, bool $force = false): void
    {
        // 检查目录是否存在，不存在则创建
        $filter_path = $model_path . '/Filters/';
        $this->checkDirectory($filter_path);

        $filter_file   = $filter_path . $filter_class . '.php';
        $relative_file = str_replace(base_path(), '', $filter_file);

        // 检查文件是否存在，不存在则创建
        if ($this->filesystem->isFile($filter_file) && ! $force) {
            $this->command->error('x ' . $relative_file . ' is existed!');

            return;
        }

        // table index 和 enums
        $codes         = [];
        $enum_fields   = array_keys($enums);
        $enum_fields[] = 'id';
        foreach ($index as $field_name => $config) {
            if (in_array($field_name, $enum_fields, true)) {
                continue;
            }
            $codes[] = ''; // 空一行

            if (Str::endsWith($field_name, '_id')) {
                $codes[] = $this->getTabs() . "public function {$field_name}(\$ids)";
                $codes[] = $this->getTabs() . '{';
                $codes[] = $this->getTabs(2) . '$ids = is_array($ids) ? $ids : [$ids];';
                $codes[] = $this->getTabs(2) . "return \$this->whereIn('{$field_name}', \$ids);";
                $codes[] = $this->getTabs() . '}';

                continue;
            }

            $codes[] = $this->getTabs() . "public function {$field_name}(\$str)";
            $codes[] = $this->getTabs() . '{';
            $codes[] = $this->getTabs(2) . "return \$this->whereLike('{$field_name}', \$str);";
            $codes[] = $this->getTabs() . '}';
        }

        foreach ($enums as $field_name => $config) {
            $codes[] = ''; // 空一行
            $codes[] = $this->getTabs() . "public function {$field_name}(\$int)";
            $codes[] = $this->getTabs() . '{';
            $codes[] = $this->getTabs(2) . '$int = is_array($int) ? $int : [$int];';
            $codes[] = $this->getTabs(2) . "return \$this->whereIn('{$field_name}', \$int);";
            $codes[] = $this->getTabs() . '}';
        }

        $meta = [
            'namespace'       => $namespace . '\Filters',
            'use_base_filter' => $this->base_namespace . 'BaseFilter',
            'class_name'      => $filter_class,
            'codes'           => implode(PHP_EOL, $codes),
        ];

        $content = $this->buildStub($meta, $this->getStub('model-filter'));
        $this->filesystem->put($filter_file, $content);

        $this->command->info('+ .' . $relative_file);
    }

    /**
     * 生成 factory 文件
     *
     *
     * @throws FileNotFoundException
     */
    private function buildFactory(string $folder, string $class, string $namespace, array $fields, array $enums, $force): void
    {
        // Factory 目录检查，不存在则创建
        $this->checkDirectory($this->factory_path . $folder);

        $factory_file  = $this->factory_path . $folder . '/' . $class . 'Factory.php';
        $relative_file = str_replace(base_path(), '.', $factory_file);

        if ($this->filesystem->isFile($factory_file) && ! $force) {
            $this->command->error('x ' . $relative_file . ' is existed!');

            return;
        }

        $meta = [
            'author'      => $this->utility->getConfig('author'),
            'date'        => date('Y-m-d H:i:s'),
            'namespace'   => "Database\Factories\\{$folder}",
            'model_class' => $namespace . '\\' . $class,
            'class'       => $class,
        ];

        $codes = [];
        foreach ($fields as $field_name => $attr) {
            if (in_array($field_name, ['id', 'deleted_at'])) {
                continue;
            }

            // https://github.com/fzaninotto/Faker
            if (str_contains($field_name, '_ids')) {
                $rule = "fake()->numberBetween(1, 3) . ',' . fake()->numberBetween(4, 7)";
            } elseif ($field_name === 'password' || str_contains($field_name, '_password')) {
                $rule = 'fake()->password';
            } elseif ($field_name === 'address' || str_contains($field_name, '_address')) {
                $rule = 'fake()->address';
            } elseif ($field_name === 'mobile' || str_contains($field_name, '_mobile')) {
                $rule = 'fake()->phoneNumber';
            } elseif ($field_name === 'email' || str_contains($field_name, '_email')) {
                $rule = 'fake()->unique()->safeEmail';
            } elseif ($field_name === 'user_name' || $field_name === 'nick_name') {
                $rule = 'fake()->userName';
            } elseif ($field_name === 'real_name') {
                $rule = "fake()->name(Arr::random(['male', 'female']))";
            } elseif (str_contains($field_name, '_code')) {
                $rule = "fake()->numerify('C####')";
            } elseif (in_array($attr['type'], ['int', 'tinyint', 'bigint'])) {
                $rule = 'random_int(0, 1)';
            } elseif ($attr['type'] === 'varchar' || $attr['type'] === 'char') {
                $rule = "implode(' ', fake()->words(2))";
            } elseif ($attr['type'] === 'text') {
                $rule = 'fake()->text(100)';
            } elseif ($attr['type'] === 'date') {
                $rule = 'fake()->date()';
            } elseif ($attr['type'] === 'datetime' || $attr['type'] === 'timestamp') {
                $rule = "fake()->date() . ' ' . fake()->time()";
            } elseif ($attr['type'] === 'boolean') {
                $rule = 'random_int(0, 1)';
            }

            if (isset($enums[$field_name])) {
                $temp = Arr::pluck($enums[$field_name], 1, 0);
                $rule = 'fake()->randomElement([' . implode(', ', array_keys($temp)) . '])';
            }

            $codes[] = $this->getTabs(3) . "'{$field_name}' => {$rule},";
        }
        $meta['fields'] = implode(PHP_EOL, $codes);

        $this->updateSeeder($meta['model_class']);

        $content = $this->buildStub($meta, $this->getStub('model-factory'));
        $this->filesystem->put($factory_file, $content);
        $this->command->info('+ ' . $relative_file);
    }

    /**
     * 更新 Database Seeder
     */
    private function updateSeeder(string $model_class): void
    {
        $file     = database_path('seeders/DatabaseSeeder.php');
        $file_txt = $this->filesystem->get($file);

        // 判断是否已存在于 seeder 中
        if (str_contains($file_txt, $model_class)) {
            return;
        }

        $code     = [];
        $code[]   = "\\{$model_class}::factory(15)->create();";
        $code[]   = PHP_EOL . $this->getTabs(2) . '//:auto_insert_code_here::do_not_delete';
        $code     = implode(PHP_EOL, $code);
        $file_txt = str_replace('//:auto_insert_code_here::do_not_delete', $code, $file_txt);

        $this->filesystem->put($file, $file_txt);
        $this->command->warn('+ ./database/seeders/DatabaseSeeder.php (Updated)');
    }

    /**
     * 生成 class property 代码
     */
    public function getPropertyCode(array $fields): string
    {
        $code = [];

        foreach ($fields as $field_name => $attr) {
            if (in_array($attr['type'], ['bigint', 'int', 'tinyint'])) {
                $type = 'int';
            } elseif (in_array($attr['type'], ['bool', 'boolean'])) {
                $type = 'bool';
            } elseif ($attr['type'] === 'array') {
                $type = 'array';
            } elseif ($attr['type'] === 'json') {
                $type = 'json';
            } else {
                $type = 'string';
            }
            $code[] = " * @property {$type} \${$field_name} {$attr['name']}";
        }

        return implode(PHP_EOL, $code);
    }

    /**
     * use trait 代码
     */
    public function getModelUseTrait(array $use_trait): string
    {
        if (empty($use_trait)) {
            return '';
        }

        $code = [];
        foreach ($use_trait as $one) {
            $code[] = $this->getTabs(1) . 'use ' . $one . ';';
        }

        return implode(PHP_EOL, $code);
    }

    /**
     * 生成隐藏属性
     */
    private function getHidden(array $fields): string
    {
        $hidden = [];
        foreach ($fields as $field_name => $attr) {
            if (Str::startsWith($field_name, '_') or str_contains($field_name, 'password')) {
                $hidden[] = "'{$field_name}'";
            }
        }

        $code = [
            $this->getTabs(1) . '/**',
            $this->getTabs(1) . ' * 数组中的属性会被隐藏',
            $this->getTabs(1) . ' * @var array',
            $this->getTabs(1) . ' */',
            $this->getTabs(1) . 'protected $hidden = [' . implode(',', $hidden) . '];',
            '', //空一行
        ];

        return implode(PHP_EOL, $code);
    }

    /**
     * 生成附加属性
     */
    private function getAppends($appends): string
    {
        $code = [
            $this->getTabs(1) . '/**',
            $this->getTabs(1) . ' * 追加到模型数组表单的访问器',
            $this->getTabs(1) . ' * @var array',
            $this->getTabs(1) . ' */',
            $this->getTabs(1) . 'protected $appends = [' . implode(', ', $appends) . '];',
            '', //空一行
        ];

        return implode(PHP_EOL, $code);
    }

    /**
     * 生成 原生类型的属性
     */
    private function getCasts(array $fields): string
    {
        $code = [];

        // 雪花算法，前端 js 精度丢失，需要转换为字符型
        if ($this->utility->getConfig('snow_flake_id')) {
            $code[] = $this->getTabs(2) . "'id' => 'string',";
        }

        foreach ($fields as $field_name => $attr) {
            if (in_array($field_name, ['deleted_at', 'created_at', 'updated_at'])) {
                continue;
            }

            // 雪花算法，前端 js int 精度丢失，需要转换为字符型，vue3 可以用 bigint 就不需要转换
            // TODO: check in vue3
            if (preg_match('/[a-zA-Z0-9]+_id$/', $field_name) && $this->utility->getConfig('snow_flake_id')) {
                $code[] = $this->getTabs(2) . "'{$field_name}' => 'string',";
            }

            if ($attr['type'] === 'boolean' || $attr['type'] === 'bool') {
                $code[] = $this->getTabs(2) . "'{$field_name}' => 'boolean',";
            }

            if (in_array($attr['type'], ['datetime', 'timestamp'])) {
                $code[] = $this->getTabs(2) . "'{$field_name}' => 'datetime:Y-m-d H:i:s',";
            }

            if ($attr['type'] === 'date') {
                $code[] = $this->getTabs(2) . "'{$field_name}' => 'date:Y-m-d',";
            }

            if ($attr['type'] === 'time') {
                $code[] = $this->getTabs(2) . "'{$field_name}' => 'datetime:H:i:s',";
            }

            if ($attr['type'] === 'json') {
                $code[] = $this->getTabs(2) . "'{$field_name}' => 'json',";
            }

            // todo: 转换更多类型
        }

        $code[] = $this->getTabs(1) . '];';

        $temp = [
            $this->getTabs(1) . '/**',
            $this->getTabs(1) . ' * 属性转换',
            $this->getTabs(1) . ' * @var array',
            $this->getTabs(1) . ' */',
            $this->getTabs(1) . 'protected $casts = [',
        ];

        return implode(PHP_EOL, array_merge($temp, $code));
    }

    /**
     * 生成 整形转浮点数处理函数
     */
    private function getFloatAttribute(array $fields): string
    {
        $code = [];

        foreach ($fields as $field_name => $attr) {
            if (isset($attr['format']) && str_contains($attr['format'], 'float:')) {
                [$float, $divisor] = explode(':', trim($attr['format']));
                $function_name     = str_replace(' ', '', ucwords(str_replace('_', ' ', $field_name)));

                $code[] = $this->getTabs(1) . '/**';
                $code[] = $this->getTabs(1) . " * {$fields[$field_name]['name']} 浮点数转整数 互转";
                $code[] = $this->getTabs(1) . ' */';
                $code[] = $this->getTabs(1) . "public function set{$function_name}Attribute(\$value)";
                $code[] = $this->getTabs(1) . '{';
                $code[] = $this->getTabs(2) . "\$this->attributes['{$field_name}'] = bcmul((string)\$value, '{$divisor}', 0);";
                $code[] = $this->getTabs(1) . '}';

                $number = strlen((string) $divisor) - 1; // 1 后面 0 的个数
                $code[] = $this->getTabs(1) . "public function get{$function_name}Attribute(\$value)";
                $code[] = $this->getTabs(1) . '{';
                $code[] = $this->getTabs(2) . "return \$value === null ? 0 : bcdiv((string)\$value, '{$divisor}', {$number});";
                $code[] = $this->getTabs(1) . '}';
                $code[] = '';
            }
        }

        return implode(PHP_EOL, $code);
    }

    /**
     * 生成 fillable 代码
     */
    private function getFillable(array $fields): string
    {
        $code = [];

        foreach ($fields as $field_name => $attr) {
            if (in_array($field_name, ['id', '_lft', '_rgt', 'deleted_at', 'created_at', 'updated_at'])) {
                continue;
            }
            $code[] = "'{$field_name}'";
        }

        return implode(', ', $code);
    }

    /**
     * 获取 Model Attribute 代码
     */
    private function getModelAttributes($fields): string
    {
        $code = [''];

        foreach ($fields as $field => $v) {
            if (isset($v['default']) && $v['default'] !== 'current') {
                if ($v['default'] === '') {
                    $default = "''";
                } else {
                    $default = $v['default'];
                }

                $code[] = $this->getTabs(2) . "'{$field}' => {$default},";
            }
        }

        if (count($code) <= 1) {
            return '';
        }

        $code[] = $this->getTabs(1);

        return implode(PHP_EOL, $code);
    }

    /**
     * 预处理，model trait 中的代码，附加字段，附加字段值获取函数
     */
    private function prepareFieldCode(string $namespace, array $enums, array $fields): array
    {
        $appends_code    = [];
        $function_code   = [];
        $trait_use_class = [];

        foreach ($enums as $field_name => $attr) {
            $appends_code[] = "'{$field_name}_txt'";

            $function_name = str_replace(' ', '', ucwords(str_replace('_', ' ', $field_name)));

            $trait_use_class[] = "use {$namespace}\Enums\\{$function_name};";

            //            $function_code[] = $this->getTabs(1) . '/**';
            //            $function_code[] = $this->getTabs(1) . " * 设置 {$fields[$field_name]['name']} 值";
            //            $function_code[] = $this->getTabs(1) . ' */';
            //            $function_code[] = $this->getTabs(1) . "public function set{$function_name}Attribute(int \$value): void";
            //            $function_code[] = $this->getTabs(1) . '{';
            //            $function_code[] = $this->getTabs(2) . "\$this->attributes['{$field_name}'] = {$function_name}::from(\$value);";
            //            $function_code[] = $this->getTabs(1) . '}';
            //            $function_code[] = ''; //空一行

            $function_code[] = $this->getTabs(1) . '/**';
            $function_code[] = $this->getTabs(1) . " * 获取 {$fields[$field_name]['name']} TXT";
            $function_code[] = $this->getTabs(1) . ' */';
            $function_code[] = $this->getTabs(1) . "public function get{$function_name}TxtAttribute(): string";
            $function_code[] = $this->getTabs(1) . '{';
            //            $function_code[] = $this->getTabs(2) . "return \$this->{$field_name} instanceof {$function_name}";
            //            $function_code[] = $this->getTabs(3) . "? \$this->{$field_name}->label()";
            //            $function_code[] = $this->getTabs(3) . ": {$function_name}::from(\$this->{$field_name})->label();";
            $function_code[] = $this->getTabs(2) . "return {$function_name}::from((int) \$this->{$field_name})->label();";
            $function_code[] = $this->getTabs(1) . '}';
        }

        return [
            'trait_use_class' => $trait_use_class,
            'appends'         => $appends_code,
            'get_txt_fn'      => implode(PHP_EOL, $function_code),
        ];
    }

    /**
     * 检查 BaseFilter 是否存在，不存在则创建
     */
    public function checkBaseFilter(): void
    {
        $path      = $this->utility->getModelPath();
        $base_file = $path . 'BaseFilter.php';

        // 检查文件是否存在，不存在则创建
        if (! $this->filesystem->isFile($base_file)) {
            $data = [
                'namespace' => trim($this->base_namespace, '\\'),
            ];

            $content = $this->buildStub($data, $this->getStub('model-base-filter'));
            $this->filesystem->put($base_file, $content);

            $this->command->info('+ ' . $this->utility->getModelPath(true) . 'BaseFilter.php');
        }
    }

    /**
     * 模型可操作 Trait
     */
    private function checkBaseTraitFiles(): void
    {
        $path = $this->model_path . 'Traits/';
        $this->checkDirectory($path);

        $files = [
            'EnumExtend'               => 'enum-extend',
            'GetSerializeDate'         => 'model-serialize-date-trait',
            'GetUpdatedAtHumanTime'    => 'model-human-time-trait',
            'Optional'                 => 'model-options-trait',
            'UsingSnowFlakePrimaryKey' => 'model-snowflake-trait',
        ];

        foreach ($files as $file_name => $stub) {
            $file = $path . "{$file_name}.php";
            if (! $this->filesystem->isFile($file)) {
                $meta = [
                    'namespace' => $this->base_namespace . 'Traits',
                ];

                $this->filesystem->put($file, $this->buildStub($meta, $this->getStub($stub)));
                $this->command->info('+ ' . $this->model_relative_path . "Traits/{$file_name}.php");
            }
        }
    }
}
