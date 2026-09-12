<?php declare(strict_types=1);

use Illuminate\Validation\Rule;
use Mooeen\Scaffold\Support\FormFrontendRules;

/**
 * FormFrontendRules 直接单测（2026-09-11 收口）。
 *
 * 行为本身已被 `FormConfigCharacterizationTest` 经 `FormRequest::getFormConfig()` 覆盖；
 * 这里补的是**新公共 API 自己**的用例 —— 动态字段的消费方（运行时 schema）会直接调
 * `fromRules()` 产出同形状的 `[{rule, msg}]`，不走 FormRequest。
 */
it('fromRules:空规则返回空数组', function () {
    expect(FormFrontendRules::fromRules([]))->toBe([]);
});

it('fromRules:跳过对象规则 / $this->get / exists:,只留可下发的字符串规则', function () {
    $rules = FormFrontendRules::fromRules([
        'title' => ['required', 'string', 'max:10', '$this->getInEnums', 'exists:foo,id', Rule::in(['a', 'b'])],
        'note'  => ['nullable', 'string'],
    ]);

    expect(array_column($rules['title'], 'rule'))->toBe(['required', 'string', 'max:10']);
    expect(array_column($rules['note'], 'rule'))->toBe(['nullable', 'string']);
    // nullable 的 msg 固定为空串
    expect($rules['note'][0])->toBe(['rule' => 'nullable', 'msg' => '']);
});

it('fromRules:msg 取 validation.<规则名> 并替换 :attribute', function () {
    app('translator')->addLines([
        'validation.required'         => ':attribute 不能为空',
        'validation.attributes.title' => '标题',
    ], 'zh_CN');
    app()->setLocale('zh_CN');

    $rules = FormFrontendRules::fromRules(['title' => ['required']]);

    expect($rules['title'][0]['msg'])->toBe('标题 不能为空');
    // 规则名取冒号前的部分
    expect(FormFrontendRules::fromRules(['n' => ['max:3']])['n'][0]['rule'])->toBe('max:3');
});

it('fromRules:通配子项在父字段存在时整条跳过,仅通配时用去通配字段名承载', function () {
    $both = FormFrontendRules::fromRules([
        'files'   => ['nullable', 'array'],
        'files.*' => ['required', 'string'],
    ]);
    expect($both)->toHaveCount(1);
    expect(array_column($both['files'], 'rule'))->toBe(['nullable', 'array']);

    $wildcardOnly = FormFrontendRules::fromRules([
        'tags.*' => ['required', 'string'],
    ]);
    expect($wildcardOnly)->toHaveKey('tags');
    expect(array_column($wildcardOnly['tags'], 'rule'))->toBe(['required', 'string']);
});
