<?php declare(strict_types=1);

/**
 * plan-审计字段自动注入 —— 2026-05-21 重写
 *
 * 旧契约：fields 含 creator_id/updater_id 时，controller-admin.stub store/update
 * 内联塞 auth()->id()。
 *
 * 新契约：creator_id / updater_id 由 model 端 HasOperator trait 在 creating/updating
 * 事件统一填充。控制器不再做内联审计填充。
 *
 * - CreateModelGenerator.buildModel 检测字段 → 注入 use HasOperator
 * - CreateModelGenerator.checkBaseTraitFiles 用 model-has-operator-trait.stub 落地 trait
 * - CreateControllerGenerator 不再注入 store_audit_hook / update_audit_hook meta
 * - controller-admin.stub 移除两处占位符
 */
it('controller-admin.stub 不再含 {{store_audit_hook}} 跟 {{update_audit_hook}} placeholder', function () {
    $stub = file_get_contents(__DIR__ . '/../../../stubs/controller-admin.stub');
    expect($stub)->not->toContain('{{store_audit_hook}}');
    expect($stub)->not->toContain('{{update_audit_hook}}');
    // 也不能残留内联 auth()->id() 注入痕迹
    expect($stub)->not->toContain("\$validated['creator_id'] = auth()->id();");
    expect($stub)->not->toContain("\$validated['updater_id'] = auth()->id();");
});

it('CreateControllerGenerator 不再注入 audit hook meta', function () {
    $src = file_get_contents(__DIR__ . '/../../../src/Generator/CreateControllerGenerator.php');
    expect($src)->not->toContain("'store_audit_hook'");
    expect($src)->not->toContain("'update_audit_hook'");
});

it('CreateModelGenerator 检测 creator_id/updater_id → use HasOperator', function () {
    $src = file_get_contents(__DIR__ . '/../../../src/Generator/CreateModelGenerator.php');
    // 字段检测分支
    expect($src)->toContain("isset(\$table_attr['fields']['creator_id'])");
    expect($src)->toContain("isset(\$table_attr['fields']['updater_id'])");
    // 注入 trait 跟 use 语句
    expect($src)->toContain("'HasOperator'");
    expect($src)->toContain("Traits\HasOperator;");
});

it('CreateModelGenerator.checkBaseTraitFiles 注册 HasOperator → 自动落地 trait 文件', function () {
    $src = file_get_contents(__DIR__ . '/../../../src/Generator/CreateModelGenerator.php');
    expect($src)->toMatch("/'HasOperator'\\s*=>\\s*'model-has-operator-trait'/");
});

it('model-has-operator-trait.stub 存在且 boot 钩子按 $fillable 填充', function () {
    $stub_path = __DIR__ . '/../../../stubs/model-has-operator-trait.stub';
    expect(file_exists($stub_path))->toBeTrue();

    $stub = file_get_contents($stub_path);
    expect($stub)->toContain('trait HasOperator');
    expect($stub)->toContain('bootHasOperator');
    // creating 同时处理 creator_id + updater_id
    expect($stub)->toContain('static::creating');
    expect($stub)->toContain("in_array('creator_id', \$fillable, true)");
    expect($stub)->toContain("in_array('updater_id', \$fillable, true)");
    // updating 仅处理 updater_id
    expect($stub)->toContain('static::updating');
    expect($stub)->toContain('$model->updater_id = auth()->id();');
});
