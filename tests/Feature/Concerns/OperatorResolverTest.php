<?php

declare(strict_types=1);

/**
 * B-01 方案 B —— OperatorResolver 注入缝回归。
 *
 * 覆盖：默认容器绑定（GuardOperatorResolver = auth()->id()，未登录 null / 登录返回 id）、
 * host 可 bind 覆盖、以及 model-has-operator-trait.stub 已改经契约取数的内容契约。
 */

use Illuminate\Auth\GenericUser;
use Mooeen\Scaffold\Contracts\OperatorResolver;
use Mooeen\Scaffold\Support\GuardOperatorResolver;

it('默认绑定 GuardOperatorResolver；未登录 id() 返回 null', function () {
    $resolver = app(OperatorResolver::class);

    expect($resolver)->toBeInstanceOf(GuardOperatorResolver::class);
    expect($resolver->id())->toBeNull();
});

it('actingAs 后默认实现 id() = auth()->id()', function () {
    $user = new GenericUser(['id' => 42]);
    $this->actingAs($user);

    expect(app(OperatorResolver::class)->id())->toBe(42);
});

it('host 可 bind 覆盖默认实现', function () {
    app()->bind(OperatorResolver::class, fn () => new class implements OperatorResolver
    {
        public function id(): int|string|null
        {
            return 999;
        }
    });

    expect(app(OperatorResolver::class)->id())->toBe(999);
});

it('model-has-operator-trait.stub 经 OperatorResolver 取操作人 ID，不再裸 auth()->id()', function () {
    $stub = file_get_contents(__DIR__ . '/../../../stubs/model-has-operator-trait.stub');

    expect($stub)->toContain('use Mooeen\Scaffold\Contracts\OperatorResolver;');
    expect($stub)->toContain('app(OperatorResolver::class)->id()');
    expect($stub)->not->toContain('auth()->id()');
});
