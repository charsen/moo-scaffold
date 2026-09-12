<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Generator\CreateControllerGenerator;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Output\NullOutput;

/**
 * 回收站 / 永久删除 / 恢复端点的软删口径回归（与 ControllerShowSoftDeleteTest 同族）。
 *
 * 旧缺陷：controller-admin.stub 无条件生成 trashed()（onlyTrashed + latest('deleted_at')）、
 * forceDestroy()、restore()，而 CreateModelGenerator 只在表含 deleted_at 时才注入 SoftDeletes
 * trait → 无 deleted_at 的表走这些端点必然 500：
 *   /api/admin/mini-apps/trashed → Call to undefined method Builder::onlyTrashed()
 *   DELETE /api/admin/mini-apps/forever/{id} → Call to undefined method Model::onlyTrashed()
 *
 * 契约（与 show() 那次修复同一手法，判定同源 Generator::hasSoftDeletes）：
 *   - controller-admin.stub 改留 {{soft_delete_methods}} 单占位；
 *   - CreateControllerGenerator::softDeleteMethods 按软删两态：软删表出三个方法，非软删表返回空串；
 *   - 「不生成方法」= AppServiceProvider::iResource 宏不注册 /trashed、/forever/{id}、/restore
 *     （宏按控制器是否有该 public 方法注册）→ 端点 404，而非 500；
 *   - controller-admin-trait.stub 的 getListFields('trashed') append 走 {{trashed_list_append}}：
 *     非软删表不能再 select 不存在的 deleted_at 列；
 *   - 共享动作 trait（host BaseActionTrait / 包 HandlesResourceActions）无法 per-model 条件化，
 *     故 forceDestroyAction / restoreAction 带运行时守卫（非软删 404，语义与端点不存在一致）。
 */
function trashedfix_gen(): CreateControllerGenerator
{
    return new CreateControllerGenerator(new NullOutput, app(Filesystem::class), app(Utility::class));
}

function trashedfix_call(object $obj, string $method, array $args = []): mixed
{
    $ref = new ReflectionMethod($obj, $method);
    $ref->setAccessible(true);

    return $ref->invokeArgs($obj, $args);
}

function trashedfix_stub(string $name): string
{
    $file = __DIR__ . '/../../../stubs/' . $name . '.stub';
    expect(is_file($file))->toBeTrue("stub not found: {$file}");

    return (string) file_get_contents($file);
}

/** 覆盖 controller-admin.stub 全部占位符、让渲染产物是合法 PHP 的 meta。 */
function trashedfix_meta(array $fields): array
{
    return [
        'author'                        => 'test',
        'date'                          => '2026-09-12 00:00',
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
        'use_form_widgets'              => 'Mooeen\\Scaffold\\Foundation\\FormWidgetCollection',
        'use_base_resources'            => 'Mooeen\\Scaffold\\Foundation\\BaseResource',
        'use_base_resources_collection' => 'Mooeen\\Scaffold\\Foundation\\BaseResourceCollection',
        'use_requests'                  => 'use App\\Admin\\Requests\\Demo\\IndexRequest;',
        'use_traits_code'               => "    use BaseActionTrait;\n    use DemoTrait;",
        'show_fields'                   => "'id'",
        'show_find_or_fail'             => trashedfix_call(trashedfix_gen(), 'showFindOrFail', [$fields]),
        'soft_delete_methods'           => trashedfix_call(trashedfix_gen(), 'softDeleteMethods', [$fields, '示例', 'Demo']),
        'trashed_list_append'           => isset($fields['deleted_at']) ? "'deleted_at'" : '',
    ];
}

function trashedfix_syntax_error(string $php): ?string
{
    try {
        token_get_all($php, TOKEN_PARSE);

        return null;
    } catch (\ParseError $e) {
        return $e->getMessage();
    }
}

// ─── 两分支的方法体 ────────────────────────────────────────────────────────
it('softDeleteMethods:软删模型出 trashed/forceDestroy/restore,非软删模型整段为空', function () {
    $gen = trashedfix_gen();

    $soft   = trashedfix_call($gen, 'softDeleteMethods', [['id' => [], 'deleted_at' => []], '示例', 'Demo']);
    $noSoft = trashedfix_call($gen, 'softDeleteMethods', [['id' => [], 'name' => []], '示例', 'Demo']);

    expect($soft)->toContain('public function trashed(IndexRequest $request): BaseResourceCollection')
        ->and($soft)->toContain('public function forceDestroy(int|string $id): BaseResource')
        ->and($soft)->toContain('public function restore(DestroyBatchRequest $request): BaseResource')
        ->and($soft)->toContain('->onlyTrashed()')
        ->and($soft)->toContain("->latest('deleted_at')")
        ->and($soft)->toContain('示例回收站')      // 占位符已就地替换
        ->and($soft)->not->toContain('{{');

    expect($noSoft)->toBe('');
});

// ─── stub 只留占位符 ──────────────────────────────────────────────────────
it('controller-admin.stub 三个端点方法体整段外置为 {{soft_delete_methods}} 占位', function () {
    $stub = trashedfix_stub('controller-admin');

    expect($stub)->toContain('{{soft_delete_methods}}')
        ->and($stub)->not->toContain('onlyTrashed')
        ->and($stub)->not->toContain('public function trashed(')
        ->and($stub)->not->toContain('public function forceDestroy(')
        ->and($stub)->not->toContain('public function restore(');
});

it('controller-admin-trait.stub 的 trashed append 走 {{trashed_list_append}} 占位', function () {
    $stub = trashedfix_stub('controller-admin-trait');

    expect($stub)->toContain('{{trashed_list_append}}')
        ->and($stub)->not->toContain("\$append = ['deleted_at'];");
});

// ─── 两分支的实际渲染产物 ─────────────────────────────────────────────────
it('渲染 controller-admin.stub:软删表出三端点,非软删表三端点整段消失且产物均合法 PHP', function () {
    $gen  = trashedfix_gen();
    $stub = trashedfix_stub('controller-admin');

    $soft = trashedfix_call($gen, 'buildStub', [trashedfix_meta(['id' => [], 'deleted_at' => []]), $stub]);
    $hard = trashedfix_call($gen, 'buildStub', [trashedfix_meta(['id' => [], 'name' => []]), $stub]);

    // 无残留占位符
    expect($soft)->not->toContain('{{');
    expect($hard)->not->toContain('{{');

    // 软删：实际生成行
    expect($soft)->toContain('public function trashed(IndexRequest $request): BaseResourceCollection')
        ->and($soft)->toContain("->latest('deleted_at')")
        ->and($soft)->toContain('->onlyTrashed()')
        ->and($soft)->toContain('public function forceDestroy(int|string $id): BaseResource')
        ->and($soft)->toContain('public function restore(DestroyBatchRequest $request): BaseResource');

    // 非软删：三个端点方法整段不存在（路由宏据此不注册 → 404，不是 500）
    expect($hard)->not->toContain('public function trashed(')
        ->and($hard)->not->toContain('public function forceDestroy(')
        ->and($hard)->not->toContain('public function restore(')
        ->and($hard)->not->toContain('onlyTrashed')
        ->and($hard)->not->toContain('deleted_at');

    // 非软删仍保留其余 CRUD 端点
    expect($hard)->toContain('public function index(IndexRequest $request): BaseResourceCollection')
        ->and($hard)->toContain('public function destroyBatch(DestroyBatchRequest $request): BaseResource')
        ->and($hard)->toContain('public function edit(EditRequest $request, int|string $id): BaseResource');

    // 产物是合法 PHP
    expect(trashedfix_syntax_error($soft))->toBeNull();
    expect(trashedfix_syntax_error($hard))->toBeNull();
});

it('渲染 controller-admin-trait.stub:非软删表 trashed append 为空数组,不 select deleted_at', function () {
    $gen  = trashedfix_gen();
    $stub = trashedfix_stub('controller-admin-trait');

    $base = [
        'namespace'           => 'App\\Admin\\Controllers\\Demo\\Traits',
        'controller_name'     => 'Demo',
        'trait_class'         => 'DemoTrait',
        'model_class'         => 'App\\Models\\Demo\\Demo',
        'model_name'          => 'Demo',
        'list_fields'         => "'id', 'name'",
        'list_columns'        => "'name'",
        'form_layout_columns' => '[]',
        'author'              => 'test',
        'date'                => '2026-09-12 00:00',
        'trashed_list_append' => "'deleted_at'",
    ];

    $soft = trashedfix_call($gen, 'buildStub', [$base, $stub]);

    $base['trashed_list_append'] = '';
    $hard                        = trashedfix_call($gen, 'buildStub', [$base, $stub]);

    expect($soft)->toContain("\$append = ['deleted_at'];")
        ->and($hard)->toContain('$append = [];')
        ->and($hard)->not->toContain("['deleted_at']")
        ->and($hard)->not->toContain('{{');

    expect(trashedfix_syntax_error($soft))->toBeNull();
    expect(trashedfix_syntax_error($hard))->toBeNull();
});

// ─── 共享动作 trait 的运行时守卫 ──────────────────────────────────────────
it('host 侧 controller-admin-base-action-trait 的 forceDestroyAction/restoreAction 保留非软删运行时守卫', function () {
    // host 里确有「模型非软删但 forceDestroy/restore 端点仍在」的存量控制器
    // （iResource 按 public 方法注册路由，模型后来不再软删就 drift 出来），这处守卫真拦 500，保留。
    $stub = trashedfix_stub('controller-admin-base-action-trait');

    expect(substr_count($stub, 'in_array(SoftDeletes::class, class_uses_recursive($this->model), true)'))->toBe(2)
        ->and($stub)->toContain('use Illuminate\\Database\\Eloquent\\SoftDeletes;')
        ->and($stub)->toContain("abort(404, 'The model does not support soft deletes.');");
});

it('包侧 controller-resource-actions-trait 不再带非软删守卫（生成出来的包里恒不触发）', function () {
    // 非软删模型本来就不生成 forceDestroy()/restore() 端点，守卫在每个生成出来的包里都是死代码，
    // 且每包一份、要人记得维护 —— 2026-09-12 用户判定不要，已从 11 个包 + 本模板一并移除。
    $stub = trashedfix_stub('controller-resource-actions-trait');

    expect($stub)->not->toContain('class_uses_recursive')
        ->and($stub)->not->toContain('does not support soft deletes')
        ->and($stub)->not->toContain('use Illuminate\\Database\\Eloquent\\SoftDeletes;')
        // 端点方法本身仍在（软删模型要用）
        ->and($stub)->toContain('private function forceDestroyAction')
        ->and($stub)->toContain('private function restoreAction')
        ->and($stub)->toContain('->onlyTrashed()');

    $php = trashedfix_call(trashedfix_gen(), 'buildStub', [[
        'namespace'      => 'Mooeen\\Demo\\Http\\Controllers\\Admin\\Traits',
        'base_resources' => 'Mooeen\\Scaffold\\Foundation\\BaseResource',
    ], $stub]);

    expect($php)->not->toContain('{{');
    expect(trashedfix_syntax_error($php))->toBeNull();
});
