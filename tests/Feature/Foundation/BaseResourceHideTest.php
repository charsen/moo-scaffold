<?php declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Mooeen\Scaffold\Foundation\BaseResource;

/*
 * `BaseResource::hide()` 必须**累加**（2026-09-21 修）。
 *
 * 本方法是 `JsonResource::hide()` 的覆写，写的是 `BaseResourceTrait` 里的 `$customFields`
 * —— `filterFields()` 真正读取的字段。原实现直接赋值，于是**连续两次 hide() 只有最后一次生效**。
 *
 * 真实事故：`AuthorizationController::index()` 先 `hide('role_next_actions')`，非 root 再
 * `hide(['role_actions'])`，结果只隐藏了后者；而 `role_next_actions` 是承载**同一份权限清单**
 * 的原始落库列，于是非 root 仍能读到他人角色的全量 key（实测 339 字符逗号串）——
 * 与「修越界读他人角色授权」的意图正好相反。该缺陷此前无任何测试覆盖。
 */

it('hide() 连续调用累加，不覆盖前一次', function () {
    $model = new class extends Model {
        protected $guarded = [];
    };
    $model->setRawAttributes([
        'id'                 => 1,
        'role_name'          => '测试角色',
        'role_next_actions'  => 'k1,k2',
        'role_actions'       => ['k1', 'k2'],
    ], true);

    $resource = (new BaseResource($model))->hide('role_next_actions');
    $resource->hide(['role_actions']);

    $keys = array_keys($resource->toArray(request()));

    expect($keys)->not->toContain('role_next_actions')
        ->and($keys)->not->toContain('role_actions')
        ->and($keys)->toContain('role_name');
});

it('hide() 单次调用仍生效（不因累加而退化）', function () {
    $model = new class extends Model {
        protected $guarded = [];
    };
    $model->setRawAttributes(['id' => 1, 'secret' => 'x', 'keep' => 'y'], true);

    $keys = array_keys((new BaseResource($model))->hide('secret')->toArray(request()));

    expect($keys)->not->toContain('secret')->and($keys)->toContain('keep');
});

it('hide() 重复同一字段不产生重复项', function () {
    $model = new class extends Model {
        protected $guarded = [];
    };
    $model->setRawAttributes(['id' => 1, 'secret' => 'x'], true);

    $resource = (new BaseResource($model))->hide('secret');
    $resource->hide(['secret', 'other']);

    $custom = new ReflectionProperty(get_class($resource), 'customFields');
    $custom->setAccessible(true);

    expect($custom->getValue($resource))->toBe(['secret', 'other']);
});
