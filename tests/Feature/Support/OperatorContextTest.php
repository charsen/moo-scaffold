<?php

declare(strict_types=1);

use Mooeen\Scaffold\Support\OperatorContext;

/**
 * OperatorContext 单测（plan 42 · Phase A · D3）。
 *
 * 锁：默认无上下文、作用域内/外取值、返回值透传、嵌套恢复、异常复位、runAs(null) 语义、clear。
 */
beforeEach(fn () => OperatorContext::clear());
afterEach(fn () => OperatorContext::clear());

it('默认无上下文，current() 返回 null', function () {
    expect(OperatorContext::current())->toBeNull();
});

it('runAs 作用域内 current() = 传入 id；退出后恢复 null', function () {
    $inside = null;

    OperatorContext::runAs(42, function () use (&$inside) {
        $inside = OperatorContext::current();
    });

    expect($inside)->toBe(42)
        ->and(OperatorContext::current())->toBeNull();
});

it('runAs 透传闭包返回值', function () {
    $result = OperatorContext::runAs(7, fn () => 'payload');

    expect($result)->toBe('payload');
});

it('runAs 支持 string id（雪花 / UUID 语境）', function () {
    $inside = null;

    OperatorContext::runAs('1234567890', function () use (&$inside) {
        $inside = OperatorContext::current();
    });

    expect($inside)->toBe('1234567890');
});

it('嵌套：内层结束后恢复外层上下文', function () {
    $seen = [];

    OperatorContext::runAs(1, function () use (&$seen) {
        $seen['outer_before'] = OperatorContext::current();

        OperatorContext::runAs(2, function () use (&$seen) {
            $seen['inner'] = OperatorContext::current();
        });

        $seen['outer_after'] = OperatorContext::current();
    });

    expect($seen)->toBe([
        'outer_before' => 1,
        'inner'        => 2,
        'outer_after'  => 1,
    ])->and(OperatorContext::current())->toBeNull();
});

it('闭包抛异常后仍恢复进入前上下文', function () {
    $caught = false;

    OperatorContext::runAs(5, function () use (&$caught) {
        try {
            OperatorContext::runAs(9, function (): void {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            $caught = true;
        }

        // 内层异常冒泡后，外层上下文（5）应完好
        expect(OperatorContext::current())->toBe(5);
    });

    expect($caught)->toBeTrue()
        ->and(OperatorContext::current())->toBeNull();
});

it('runAs(null) 语义 = 显式无上下文（作用域内 current() 为 null）', function () {
    $inside = 'unset';

    OperatorContext::runAs(3, function () use (&$inside) {
        OperatorContext::runAs(null, function () use (&$inside) {
            $inside = OperatorContext::current();
        });
    });

    expect($inside)->toBeNull();
});

it('clear() 复位当前上下文', function () {
    OperatorContext::runAs(11, function () {
        OperatorContext::clear();
        expect(OperatorContext::current())->toBeNull();
    });
});
