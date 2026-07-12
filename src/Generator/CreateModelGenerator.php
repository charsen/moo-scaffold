<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2026-03-01 21:35
 * @Description: Create Model
 */

namespace Mooeen\Scaffold\Generator;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

use function in_array;

class CreateModelGenerator extends Generator
{
    protected string $model_path;

    protected string $model_relative_path;

    protected string $factory_path;

    protected string $base_namespace;

    /**
     * @throws FileNotFoundException
     */
    public function start(string $schema_name, bool $force = false, bool $factory = false, ?string $only_table = null): bool
    {
        $this->model_path          = $this->utility->getModelPath();
        $this->model_relative_path = $this->utility->getModelPath(true);
        $this->base_namespace      = $this->utility->formatNameSpace($this->model_relative_path);
        $this->factory_path        = database_path('factories/');

        $all = $this->filesystem->getRequire($this->utility->getStoragePath() . 'models.php');

        if (! isset($all[$schema_name])) {
            $this->console()->error("未找到 schema 文件 \"{$schema_name}\"。");

            return false;
        }

        // plan-53 出身:包 schema 的 Model 落包目录(平铺,无 module folder 段)、用包命名空间。
        // origin 是 schema 级属性,从任一条目取;写权硬线在此把闸(vcs 拷贝包拒绝)。
        $origin = null;
        foreach ($all[$schema_name] as $attr0) {
            $origin = $attr0['origin'] ?? null;

            break;
        }
        $this->assertOriginWritable($origin);
        $this->originCtx = $this->originContext($origin);
        if ($this->originCtx !== null) {
            $this->model_path     = rtrim($this->originCtx->pathFor('model'), '/') . '/';
            $this->base_namespace = $this->originCtx->namespaceFor('model') . '\\';
        }

        // plan 38：BaseFilter 已上移 Mooeen\Scaffold\Foundation\BaseFilter，不再逐包生成本地副本（同 Concerns\* 上提）
        $this->checkBaseTraitFiles();

        foreach ($all[$schema_name] as $class => $attr) {
            // moo:free --table 过滤:只生成指定表 key 的代码,其它表跳过
            if ($only_table !== null && $attr['table_name'] !== $only_table) {
                continue;
            }

            // 包 schema 平铺(src/Models/X.php,与 moo-system/moo-radar 实证形态一致);host 按 module folder 分层
            $model_path    = $this->originCtx !== null ? rtrim($this->model_path, '/') : $this->model_path . $attr['module']['folder'];
            $model_file    = $model_path . "/{$class}.php";
            $relative_file = $this->relDisplay($model_file, $this->originCtx);

            // Model 目录检查，不存在则创建
            $this->checkDirectory($model_path);

            $table_attr = $this->utility->getOneTable($attr['table_name']);

            // Model 目录及 namespace 处理
            $trait_class     = "{$class}Trait";
            $filter_class    = "{$class}Filter";
            $namespace       = $this->originCtx !== null ? rtrim($this->base_namespace, '\\') : $this->base_namespace . $attr['module']['folder'];
            $trait_namespace = $namespace . '\Traits';

            // model trait 部分代码处理
            $field_codes  = $this->prepareFieldCode($namespace, $table_attr['enums'], $table_attr['fields']);
            $get_float_fn = $this->getFloatAttribute($table_attr['fields']);

            // Model 和 Filter 可手动修改，不强制覆盖（除非 --force）
            if ($this->filesystem->isFile($model_file) && ! $force) {
                $this->console()->exists($relative_file, 'Model 已存在');
            } else {
                $this->buildModel($model_path, $class, $namespace, $attr, $table_attr, $field_codes, $factory);
                $this->buildFilter($model_path, $namespace, $filter_class, $table_attr, $force);
            }

            // Trait / Enum 完全由 schema 驱动，每次强制刷新以保持同步
            $this->buildTrait($model_path, $trait_namespace, $trait_class, $attr['table_name'], $field_codes, $get_float_fn);
            $this->buildEnum($table_attr['name'], $model_path, $namespace, $table_attr['enums'], $table_attr['fields']);

            if ($factory) {
                $this->buildFactory($attr['module']['folder'], $class, $namespace, $table_attr['fields'], $table_attr['enums'], $force);
            }

            $this->console()->newLine();
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
        $relative_file = $this->relDisplay($model_file, $this->originCtx);

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

        // 时间序列化（基础 trait 已上提到 mooeen/scaffold）
        $use_trait[] = 'GetSerializeDate';
        $use_class[] = 'use Mooeen\Scaffold\Concerns\GetSerializeDate;';

        // 人性化 更新于 时间
        if (isset($table_attr['fields']['updated_at'])) {
            $use_trait[] = 'GetUpdatedAtHumanTime';
            $use_class[] = 'use Mooeen\Scaffold\Concerns\GetUpdatedAtHumanTime;';
        }

        // Optional Trait
        $use_trait[] = 'Optional';
        $use_class[] = 'use Mooeen\Scaffold\Concerns\Optional;';

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

        // 操作人填充：表含 creator_id / updater_id 时自动 HasOperator
        if (isset($table_attr['fields']['creator_id']) || isset($table_attr['fields']['updater_id'])) {
            $use_trait[] = 'HasOperator';
            $use_class[] = "use {$this->base_namespace}Traits\HasOperator;";
        }

        $meta = [
            'author'        => $this->utility->getConfig('author'),
            'date'          => date('Y-m-d H:i'),
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
        $this->putAndReport($model_file, $relative_file, $content);
    }

    /**
     * 生成 Trait 文件
     */
    private function buildTrait(string $path, string $namespace, string $class, string $table_name, array $field_codes, string $get_float_fn): void
    {
        $path .= '/Traits/';
        $this->checkDirectory($path);

        $trait_file          = $path . $class . '.php';
        $trait_relative_file = $this->relDisplay($trait_file, $this->originCtx);
        $file_exists         = $this->filesystem->isFile($trait_file);

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
        if ($file_exists) {
            $this->console()->updated($trait_relative_file);
        } else {
            $this->console()->created($trait_relative_file);
        }
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
            $trait_type    = 'int';     // 默认 backing 类型;防空 enum 块({})时未初始化 / 泄漏上一字段的值
            $enum_class    = str_replace(' ', '', ucwords(str_replace('_', ' ', $field_name)));
            $enum_file     = $enum_path . $enum_class . '.php';
            $relative_file = $this->relDisplay($enum_file, $this->originCtx);
            $file_exists   = $this->filesystem->isFile($enum_file);

            foreach ($values as $alias => $item) {
                // 2026-05-21:designer enum 翻译辅助 — yaml 写入 __pending_<n> 占位 key 是 pending
                // 状态,等 user 在 designer 调 AI 翻译填。codegen 端拒生成,引导先 designer 翻译。
                if (str_starts_with((string) $alias, '__pending_')) {
                    $zhHint = is_array($item) && isset($item[2]) ? (string) $item[2] : '';
                    $zhPart = $zhHint !== '' ? "(中文标签:「{$zhHint}」)" : '';
                    throw new \RuntimeException(
                        "[{$model_name}] 模型枚举字段 [{$field_name}] 有未翻译的 key{$zhPart}。\n"
                        . "→ 打开 /scaffold/db/designer,定位到该表 → 枚举区 → 「{$field_name}」组\n"
                        . "→ 整组未译用顶部 [AI 翻译],单行重译用行尾 ↻ 按钮\n"
                        . '→ 翻译完成后重跑 moo:model'
                    );
                }
                $new_alias  = strtoupper($alias);
                $trait_type = is_string($item[0]) ? 'string' : 'int';
                if ($trait_type === 'string') {
                    // plan-40 §二 F9c P1 防御纵深:enum value 来自 yaml,attacker 可塞 `a','b')->dropTable(`
                    // 让 case 行裂开。SchemaLoader::applyEnums 入口 sanitize 是 P0 兜底,
                    // 这里 caller escape 是双层防御
                    $valEsc       = $this->escapePhpString($item[0]);
                    $case_codes[] = $this->getTabs(1) . "case {$new_alias} = '{$valEsc}';";
                } else {
                    // int value 经 SchemaLoader 入口 cast,这里仍 (int) 兜底防注入
                    $case_codes[] = $this->getTabs(1) . "case {$new_alias} = " . (int) $item[0] . ';';
                }
                $case_labels[] = $this->getTabs(3) . "self::{$new_alias} => __('model.{$field_name}_{$alias}'),";
            }

            $data = [
                'namespace'        => $namespace . '\Enums',
                'traits_namespace' => 'Mooeen\Scaffold\Concerns',
                'model_name'       => $model_name,
                'field_name'       => $fields[$field_name]['name'],
                'trait_class'      => $enum_class,
                'trait_type'       => $trait_type,
                'case_codes'       => implode(PHP_EOL, $case_codes),
                'case_labels'      => implode(PHP_EOL, $case_labels),
            ];

            $content = $this->buildStub($data, $this->getStub('model-enum-trait'));
            $this->filesystem->put($enum_file, $content);

            if ($file_exists) {
                $this->console()->updated($relative_file);
            } else {
                $this->console()->created($relative_file);
            }
        }
    }

    /**
     * 生成 model 的 filter 文件
     */
    public function buildFilter(string $model_path, string $namespace, string $filter_class, array $table, bool $force = false): void
    {
        // 检查目录是否存在，不存在则创建
        $filter_path = $model_path . '/Filters/';
        $this->checkDirectory($filter_path);

        $filter_file   = $filter_path . $filter_class . '.php';
        $relative_file = $this->relDisplay($filter_file, $this->originCtx);
        $file_exists   = $this->filesystem->isFile($filter_file);

        // 检查文件是否存在，不存在则创建
        if ($file_exists && ! $force) {
            $this->console()->exists($relative_file, 'Filter 已存在');

            return;
        }

        // $index  = $table['index'];
        $enums  = $table['enums'];
        $fields = $table['fields'];

        // table fields 和 enums
        $codes         = [];
        $enum_fields   = array_keys($enums);
        $enum_fields[] = 'id';
        foreach ($fields as $field_name => $config) {
            if (in_array($field_name, $enum_fields, true) or Str::startsWith($field_name, '_') or str_contains($field_name, 'password')) {
                continue;
            }

            // plan-40 §二 C-6 RCE 防御纵深:即使 yaml 入口 sanitizeFieldName 已拦,
            // 这里二次校验防 cache/createTable 端点绕过。非法字段名直接 skip + warn。
            // method 名要做 PHP identifier(不能含 quote);字符串字面量槽位走 escapePhpString。
            // 首 `_` 放行跟 SchemaLoader 对齐(实际 line 303 已 short-circuit _ 前缀字段)
            if (! preg_match('/^[a-z_][a-z0-9_]*$/', (string) $field_name)) {
                $this->console()->warn("Filter 跳过非法字段名: {$field_name}");

                continue;
            }
            $fn = $this->escapePhpString($field_name);     // 进 'where('xxx')' 字符串字面量

            $codes[] = ''; // 空一行

            // 处理 id 和数字类型(smallint/mediumint 同属整型,2026-06-11 补)
            if (in_array($config['type'], ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'])) {
                $codes[] = $this->getTabs() . "public function {$field_name}(\$int)";
                $codes[] = $this->getTabs() . '{';
                $codes[] = $this->getTabs(2) . '$int = is_array($int) ? $int : [$int];';
                $codes[] = $this->getTabs(2) . "return \$this->whereIn('{$fn}', \$int);";
                $codes[] = $this->getTabs() . '}';
            }

            if (in_array($config['type'], ['varchar', 'char', 'text', 'tinytext'])) {
                $codes[] = $this->getTabs() . "public function {$field_name}(\$str)";
                $codes[] = $this->getTabs() . '{';
                $codes[] = $this->getTabs(2) . "return \$this->where('{$fn}', 'LIKE', \"%{\$str}%\");";
                $codes[] = $this->getTabs() . '}';
            }

            if (in_array($config['type'], ['date', 'datetime', 'timestamp'])) {
                $codes[] = $this->getTabs() . "public function {$field_name}(\$date)";
                $codes[] = $this->getTabs() . '{';
                $codes[] = $this->getTabs(2) . "return \$this->whereDate('{$fn}', \$date);";
                $codes[] = $this->getTabs() . '}';
            }

            if (in_array($config['type'], ['bool', 'boolean'])) {
                $codes[] = $this->getTabs() . "public function {$field_name}(\$bool)";
                $codes[] = $this->getTabs() . '{';
                $codes[] = $this->getTabs(2) . "return \$this->where('{$fn}', \$bool);";
                $codes[] = $this->getTabs() . '}';
            }

            if (in_array($config['type'], ['decimal', 'float', 'double'])) {
                $codes[] = $this->getTabs() . "public function {$field_name}(\$float)";
                $codes[] = $this->getTabs() . '{';
                $codes[] = $this->getTabs(2) . "return \$this->where('{$fn}', \$float);";
                $codes[] = $this->getTabs() . '}';
            }

            if (in_array($config['type'], ['json', 'array'])) {
                $codes[] = $this->getTabs() . "public function {$field_name}(\$json)";
                $codes[] = $this->getTabs() . '{';
                $codes[] = $this->getTabs(2) . "return \$this->whereJsonContains('{$fn}', \$json);";
                $codes[] = $this->getTabs() . '}';
            }
        }

        // 单独处理枚举
        foreach ($enums as $field_name => $config) {
            // plan-40 §二 C-6 防御纵深(同上,enum 字段也走同一道闸)
            if (! preg_match('/^[a-z_][a-z0-9_]*$/', (string) $field_name)) {
                $this->console()->warn("Filter 跳过非法枚举字段名: {$field_name}");

                continue;
            }
            $fn = $this->escapePhpString($field_name);

            $codes[] = ''; // 空一行
            $codes[] = $this->getTabs() . "public function {$field_name}(\$int)";
            $codes[] = $this->getTabs() . '{';
            $codes[] = $this->getTabs(2) . '$int = is_array($int) ? $int : [$int];';
            $codes[] = $this->getTabs(2) . "return \$this->whereIn('{$fn}', \$int);";
            $codes[] = $this->getTabs() . '}';
        }

        $meta = [
            'author'          => $this->utility->getConfig('author'),
            'date'            => date('Y-m-d H:i'),
            'namespace'       => $namespace . '\Filters',
            'use_base_filter' => 'Mooeen\Scaffold\Foundation\BaseFilter', // plan 38：三件套上移，不再逐包 BaseFilter（同 Concerns\* 上提）
            'class_name'      => $filter_class,
            'codes'           => implode(PHP_EOL, $codes),
        ];

        $content = $this->buildStub($meta, $this->getStub('model-filter'));
        $this->putAndReport($filter_file, $relative_file, $content);
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
        $file_exists   = $this->filesystem->isFile($factory_file);

        if ($file_exists && ! $force) {
            $this->console()->exists($relative_file, 'Factory 已存在');

            return;
        }

        $meta = [
            'author'      => $this->utility->getConfig('author'),
            'date'        => date('Y-m-d H:i'),
            'namespace'   => "Database\Factories\\{$folder}",
            'model_class' => $namespace . '\\' . $class,
            'class'       => $class,
        ];

        $codes = [];
        foreach ($fields as $field_name => $attr) {
            if (in_array($field_name, ['id', 'deleted_at'])) {
                continue;
            }

            $rule = "''";

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
            } elseif (in_array($attr['type'], ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'])) {
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
                // array_keys 拿到的是枚举的「值」(item[0])。int-backed 枚举(PHP 把数字串 key 归一成 int)
                // 直接裸写;string-backed 枚举(buildEnum 支持 item[0] 是字符串,如 'A')必须加引号 +
                // escapePhpString,否则发出 `randomElement([A, B])` 裸字 → Undefined constant 致命错误
                // (2026-06-11 修)。
                $temp   = Arr::pluck($enums[$field_name], 1, 0);
                $values = array_map(
                    fn ($v) => is_int($v) ? (string) $v : "'" . $this->escapePhpString((string) $v) . "'",
                    array_keys($temp)
                );
                $rule = 'fake()->randomElement([' . implode(', ', $values) . '])';
            }

            $codes[] = $this->getTabs(3) . "'{$field_name}' => {$rule},";
        }
        $meta['fields'] = implode(PHP_EOL, $codes);

        $this->updateSeeder($meta['model_class']);

        $content = $this->buildStub($meta, $this->getStub('model-factory'));
        $this->putAndReport($factory_file, $relative_file, $content);
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
        $this->console()->updated('./database/seeders/DatabaseSeeder.php');
    }

    /**
     * 生成 class property 代码
     */
    public function getPropertyCode(array $fields): string
    {
        $code = [];

        foreach ($fields as $field_name => $attr) {
            if (in_array($attr['type'], ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'])) {
                $type = 'int';
            } elseif (in_array($attr['type'], ['bool', 'boolean'])) {
                $type = 'bool';
            } elseif (in_array($attr['type'], ['date', 'datetime', 'timestamp'])) {
                $type = 'Carbon|null';
            } elseif ($attr['type'] === 'array') {
                $type = 'array';
            } elseif ($attr['type'] === 'json') {
                $type = 'json';
            } else {
                $type = 'string';
            }
            // 2026-05-21:yaml 字段未声明 name 时不抛错(FreshStorageGenerator:260 同模式 fallback),
            // 用 field_name 兜底当 docblock 描述,docgen 不阻塞业务。
            // plan-40 §二 F9d:attr.name 来自 yaml 任意中文文本,strip `*/` 防 docblock 闭合。
            $code[] = " * @property {$type} \${$field_name} " . $this->sanitizeDocblock($attr['name'] ?? $field_name);
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
            '', // 空一行
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
            '', // 空一行
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
                // plan-40 §二 F9a P0 RCE 修:format 字段不校验内容 ('100');system('id');// 可注入),
                // bcmul/bcdiv divisor 槽位走 escapePhpString 防 PHP 字符串字面量逃逸
                // (40-addendum-escape-coverage-audit.md F9a)
                $code[] = $this->getTabs(2) . "\$this->attributes['{$field_name}'] = bcmul((string)\$value, '" . $this->escapePhpString($divisor) . "', 0);";
                $code[] = $this->getTabs(1) . '}';

                $number = strlen((string) $divisor) - 1; // 1 后面 0 的个数
                $code[] = $this->getTabs(1) . "public function get{$function_name}Attribute(\$value)";
                $code[] = $this->getTabs(1) . '{';
                $code[] = $this->getTabs(2) . "return \$value === null ? 0 : bcdiv((string)\$value, '" . $this->escapePhpString($divisor) . "', {$number});";
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
                    // plan-40 §二 F9b P0 修:default 字段不校验内容 (x');evil();// 可注入 $attributes),
                    // string 槽位走 escapePhpString(40-addendum-escape-coverage-audit.md F9b)
                    $default = is_int($v['default'])
                             ? $v['default']
                             : (is_bool($v['default']) ? ($v['default'] ? 'true' : 'false') : "'" . $this->escapePhpString($v['default']) . "'");
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

        foreach ($enums as $field_name => $values) {
            $firstValue = reset($values);
            $trait_type = is_string($firstValue[0]) ? 'string' : 'int';

            $appends_code[] = "'{$field_name}_txt'";

            $function_name = str_replace(' ', '', ucwords(str_replace('_', ' ', $field_name)));

            $trait_use_class[] = "use {$namespace}\Enums\\{$function_name};";

            $function_code[] = $this->getTabs(1) . '/**';
            $function_code[] = $this->getTabs(1) . " * 获取 {$fields[$field_name]['name']} TXT";
            $function_code[] = $this->getTabs(1) . ' */';
            $function_code[] = $this->getTabs(1) . "public function get{$function_name}TxtAttribute(): ?string";
            $function_code[] = $this->getTabs(1) . '{';
            // 用 try/catch 防止脏数据（枚举值不存在）导致报错
            $function_code[] = $this->getTabs(2) . 'try {';
            $function_code[] = $this->getTabs(3) . "return {$function_name}::from(({$trait_type})\$this->{$field_name})->label();";
            $function_code[] = $this->getTabs(2) . "} catch (\Throwable \$e) {";
            $function_code[] = $this->getTabs(3) . 'return null;';
            $function_code[] = $this->getTabs(2) . '}';
            $function_code[] = $this->getTabs(1) . '}';

            $function_code[] = ''; // 空一行
        }

        return [
            'trait_use_class' => $trait_use_class,
            'appends'         => $appends_code,
            'get_txt_fn'      => implode(PHP_EOL, $function_code),
        ];
    }

    /**
     * 模型可操作 Trait
     */
    private function checkBaseTraitFiles(): void
    {
        $path = $this->model_path . 'Traits/';
        $this->checkDirectory($path);

        // EnumExtend / GetSerializeDate / GetUpdatedAtHumanTime / Optional 已上提到
        // mooeen/scaffold 的 Mooeen\Scaffold\Concerns\*（运行时类），不再生成本地副本。
        $files = [
            'HasOperator'              => 'model-has-operator-trait',
            'UsingSnowFlakePrimaryKey' => 'model-snowflake-trait',
        ];

        foreach ($files as $file_name => $stub) {
            $file = $path . "{$file_name}.php";
            if (! $this->filesystem->isFile($file)) {
                $meta = [
                    'namespace' => $this->base_namespace . 'Traits',
                ];

                $this->filesystem->put($file, $this->buildStub($meta, $this->getStub($stub)));
                $this->console()->created($this->relDisplay($file, $this->originCtx));
            }
        }
    }
}
