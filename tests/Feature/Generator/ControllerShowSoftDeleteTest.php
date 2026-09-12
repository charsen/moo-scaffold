<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Generator\CreateControllerGenerator;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Output\NullOutput;

/**
 * controller-admin.stub show() 软删口径回归。
 *
 * 旧缺陷：stub 无条件生成 `$this->model->withTrashed()->findOrFail($id)`，而
 * CreateModelGenerator 只在表含 deleted_at 时才注入 SoftDeletes trait →
 * 无 deleted_at 的表的 admin 控制器 show() 调模型上不存在的方法 → 500。
 *
 * 契约：
 *   - controller-admin.stub 改用 {{show_find_or_fail}} 占位，不再硬编码 withTrashed()
 *   - CreateControllerGenerator::showFindOrFail 按 Generator::hasSoftDeletes 分两态
 *   - hasSoftDeletes 同时是 CreateModelGenerator 注入 SoftDeletes 的判定（同源，不可各写一份）
 */
function showfix_gen(): CreateControllerGenerator
{
    return new CreateControllerGenerator(new NullOutput, app(Filesystem::class), app(Utility::class));
}

function showfix_call(object $obj, string $method, array $args = []): mixed
{
    $ref = new ReflectionMethod($obj, $method);
    $ref->setAccessible(true);

    return $ref->invokeArgs($obj, $args);
}

function showfix_stub(): string
{
    $file = __DIR__ . '/../../../stubs/controller-admin.stub';
    expect(is_file($file))->toBeTrue("stub not found: {$file}");

    return (string) file_get_contents($file);
}

/** 覆盖 stub 全部占位符、让渲染产物是合法 PHP 的 meta。 */
function showfix_meta(array $fields): array
{
    return [
        'author'                        => 'test',
        'date'                          => '2026-06-11 00:00',
        'package_name'                  => '管理后台',
        'package_en_name'               => 'Admin',
        'module_name'                   => '示例',
        'module_en_name'                => 'Demo',
        'entity_name'                   => '示例',
        'entity_en_name'                => 'Demo',
        'namespace'                     => 'App\\Admin\\Controllers\\Demo',
        'controller_name'               => 'Demo',
        'model_name'                    => 'Demo',
        'model_class'                   => 'App\\Models\\Demo\\Demo',
        'model_key_name'                => 'id',
        'use_base_action'               => 'App\\Admin\\Controllers\\Traits\\BaseActionTrait',
        'use_controller_trait'          => 'App\\Admin\\Controllers\\Demo\\Traits\\DemoTrait',
        'use_base_controller'           => 'Mooeen\\Scaffold\\Foundation\\Controller',
        'use_columns'                   => 'Mooeen\\Scaffold\\Foundation\\ColumnsCollection',
        'use_form_widgets'              => 'Mooeen\\Scaffold\\Foundation\\FormWidgets',
        'use_base_resources'            => 'Mooeen\\Scaffold\\Foundation\\BaseResource',
        'use_base_resources_collection' => 'Mooeen\\Scaffold\\Foundation\\BaseResourceCollection',
        'use_requests'                  => 'use App\\Admin\\Requests\\Demo\\IndexRequest;',
        'use_traits_code'               => "    use BaseActionTrait;\n    use DemoTrait;",
        'show_fields'                   => "'id'",
        'show_find_or_fail'             => showfix_call(showfix_gen(), 'showFindOrFail', [$fields]),
        // 2026-09-12：trashed / forceDestroy / restore 也改为按软删条件生成（对称补全）
        'soft_delete_methods' => showfix_call(showfix_gen(), 'softDeleteMethods', [$fields, '示例', 'Demo']),
        'trashed_list_append' => isset($fields['deleted_at']) ? "'deleted_at'" : '',
    ];
}

function showfix_syntax_error(string $php): ?string
{
    try {
        token_get_all($php, TOKEN_PARSE);

        return null;
    } catch (\ParseError $e) {
        return $e->getMessage();
    }
}

// ─── 两分支的取值 ─────────────────────────────────────────────────────────
it('showFindOrFail:软删模型 → withTrashed(),非软删模型 → findOrFail()', function () {
    $gen = showfix_gen();

    $soft   = showfix_call($gen, 'showFindOrFail', [['id' => [], 'name' => [], 'deleted_at' => []]]);
    $noSoft = showfix_call($gen, 'showFindOrFail', [['id' => [], 'name' => []]]);

    expect($soft)->toBe('$this->model->withTrashed()->findOrFail($id)');
    expect($noSoft)->toBe('$this->model->findOrFail($id)');
});

it('hasSoftDeletes:deleted_at 键存在即软删(与 CreateModelGenerator 同源)', function () {
    $gen = showfix_gen();

    expect(showfix_call($gen, 'hasSoftDeletes', [['deleted_at' => []]]))->toBeTrue();
    expect(showfix_call($gen, 'hasSoftDeletes', [['id' => [], 'name' => []]]))->toBeFalse();
    expect(showfix_call($gen, 'hasSoftDeletes', [[]]))->toBeFalse();
});

// ─── stub 只留占位符 ──────────────────────────────────────────────────────
it('controller-admin.stub 用 {{show_find_or_fail}} 占位,不再硬编码 withTrashed()', function () {
    $stub = showfix_stub();

    expect($stub)->toContain('{{show_find_or_fail}}');
    expect($stub)->not->toContain('->withTrashed()->findOrFail($id)');
});

// ─── 两分支的实际渲染产物 ─────────────────────────────────────────────────
it('渲染 controller-admin.stub:软删表出 withTrashed(),非软删表出 findOrFail(),两者产物均为合法 PHP', function () {
    $gen  = showfix_gen();
    $stub = showfix_stub();

    $soft = showfix_call($gen, 'buildStub', [showfix_meta(['deleted_at' => []]), $stub]);
    $hard = showfix_call($gen, 'buildStub', [showfix_meta(['id' => [], 'name' => []]), $stub]);

    // 无残留占位符
    expect($soft)->not->toContain('{{');
    expect($hard)->not->toContain('{{');

    // 实际生成行(stub 里 show() 用 `$result  = ` 双空格缩进;update() 是单空格,不会撞)
    expect($soft)->toContain('$result  = $this->model->withTrashed()->findOrFail($id);');
    expect($hard)->toContain('$result  = $this->model->findOrFail($id);');

    // 非软删产物整体不得出现任何 withTrashed
    expect($hard)->not->toContain('withTrashed');
    expect($soft)->toContain('withTrashed');

    // 产物是合法 PHP
    expect(showfix_syntax_error($soft))->toBeNull();
    expect(showfix_syntax_error($hard))->toBeNull();
});

// ─── 同源守卫:判定只长在一处 ──────────────────────────────────────────────
it('CreateModelGenerator 与 CreateControllerGenerator 都走 Generator::hasSoftDeletes(不留第二份判定)', function () {
    $base  = (string) file_get_contents(__DIR__ . '/../../../src/Generator/Generator.php');
    $model = (string) file_get_contents(__DIR__ . '/../../../src/Generator/CreateModelGenerator.php');
    $ctrl  = (string) file_get_contents(__DIR__ . '/../../../src/Generator/CreateControllerGenerator.php');

    // 单一判定活在 Generator 基类
    expect($base)->toContain('protected function hasSoftDeletes(array $fields): bool');
    expect($base)->toContain("return isset(\$fields['deleted_at']);");

    // 注入 SoftDeletes 与 show() 取值都调它
    expect($model)->toContain('$this->hasSoftDeletes($table_attr[\'fields\'])');
    expect($ctrl)->toContain('$this->hasSoftDeletes($fields)');

    // 两边都不再各写一份 isset 判定
    expect($model)->not->toContain("isset(\$table_attr['fields']['deleted_at'])");
    expect($ctrl)->not->toContain("isset(\$fields['deleted_at'])");
});
