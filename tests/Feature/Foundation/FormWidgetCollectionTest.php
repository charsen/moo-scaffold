<?php declare(strict_types=1);

/*
 * FormWidgetCollection 的属性写入/删除必须**零全局宏依赖**。
 *
 * 2.1.7 前 putMore/forget 走 `Collection::putMore` / `forgetMore` 宏，而那两个宏是各 host 在自己的
 * AppServiceProvider 里手抄注册的（scaffold 不注册）——漏抄就 BadMethodCallException，且脱离 host 的
 * 包测试环境里整条 form_widgets 链路根本跑不起来。这里的 beforeEach 显式断言宏不存在，把
 * 「不许再依赖回去」钉成回归。
 */

use Illuminate\Support\Collection;
use Mooeen\Scaffold\Foundation\FormWidgetCollection;

beforeEach(function () {
    // scaffold 测试环境只挂 ScaffoldProvider，本就没有 host 的那几个宏 —— 前置条件先钉死
    expect(Collection::hasMacro('putMore'))->toBeFalse()
        ->and(Collection::hasMacro('forgetMore'))->toBeFalse();
});

function widgets(): FormWidgetCollection
{
    return FormWidgetCollection::make([
        'role_name'   => ['type' => 'input'],
        'position_id' => ['type' => 'select', 'options' => []],
        'role_remark' => ['type' => 'input'],
    ]);
}

/** form_widgets 是「每行一组控件」的两层结构，摊平一层再按 field 索引。 */
function byField(array $payload): Collection
{
    return collect($payload)->flatten(1)->keyBy('field');
}

it('disabled / default / options 无宏也能写入控件属性', function () {
    $payload = byField(
        widgets()
            ->disabled('position_id')
            ->default('position_id', '1024')
            ->options('position_id', [['label' => '研发岗', 'value' => '1024']])
            ->toArray(request())
    );

    expect($payload['position_id']['disabled'])->toBeTrue()
        ->and($payload['position_id']['default'])->toBe('1024')
        ->and($payload['position_id']['options'])->toBe([['label' => '研发岗', 'value' => '1024']])
        ->and($payload['role_name'])->not->toHaveKey('disabled'); // 只动目标字段
});

it('putMore 点路径层级不限，且链式调用可累加', function () {
    $payload = byField(
        widgets()
            ->putMore('role_name.control.request', '/api/admin/roles')
            ->putMore('role_name.control.params.scope.deep', 'all') // 原 host 宏 >3 段会抛错，现已放开
            ->hidden('role_remark')
            ->tip('role_name', '不可修改')
            ->toArray(request())
    );

    expect($payload['role_name']['control']['request'])->toBe('/api/admin/roles')
        ->and($payload['role_name']['control']['params']['scope']['deep'])->toBe('all')
        ->and($payload['role_name']['tip'])->toBe('不可修改')
        ->and($payload['role_remark']['hidden'])->toBeTrue();
});

it('forget 无宏也能删字段，支持数组与逗号分隔串', function () {
    expect(byField(widgets()->forget(['role_remark'])->toArray(request()))->keys()->all())
        ->toBe(['role_name', 'position_id']);

    expect(byField(widgets()->forget('role_remark, position_id')->toArray(request()))->keys()->all())
        ->toBe(['role_name']);
});
