<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Mooeen\Scaffold\Rules\Mobile;

it('手机号收到数组时返回字段校验失败，不能变成服务器错误', function (array $value) {
    $validator = Validator::make(['mobile' => $value], ['mobile' => ['required', new Mobile, 'string']]);

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('mobile'))->toBeTrue();
})->with([[['13800138000']], [['nested' => ['13800138000']]]]);

it('手机号规则保留合法号码字符串和整数的兼容性', function (string|int $value) {
    expect(Validator::make(['mobile' => $value], ['mobile' => [new Mobile]])->passes())->toBeTrue();
})->with(['13800138000', 13800138000]);
