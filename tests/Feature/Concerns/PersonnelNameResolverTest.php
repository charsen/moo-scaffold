<?php

declare(strict_types=1);

/**
 * 跨包「批量人员 ID → 展示名」契约（PersonnelNameResolver, 2026-09-18）形状回归。
 *
 * 覆盖：① 方法签名固定（批量进、映射出，禁止改成单值或返回 list）；
 * ② scaffold 不提供默认实现 —— 未绑定时容器抛错，不允许静默空实现把全站人名变空白；
 * ③ host 绑定后按契约语义解析（缺省键缺省，不伪造占位名）。
 */

use Mooeen\Scaffold\Contracts\PersonnelNameResolver;
use Mooeen\Scaffold\ScaffoldProvider;

it('契约形状固定：resolveNames 批量进、映射出', function () {
    $method = new ReflectionMethod(PersonnelNameResolver::class, 'resolveNames');

    expect($method->isPublic())->toBeTrue()
        ->and($method->getNumberOfParameters())->toBe(1)
        ->and($method->getParameters()[0]->getName())->toBe('ids')
        ->and((string) $method->getReturnType())->toBe('array')
        ->and((string) $method->getParameters()[0]->getType())->toBe('array');
});

it('scaffold 不提供默认实现：未绑定即显式失败', function () {
    (new ScaffoldProvider(app()))->register();

    expect(app()->bound(PersonnelNameResolver::class))->toBeFalse()
        ->and(fn () => app(PersonnelNameResolver::class))->toThrow(Exception::class);
});

it('host 绑定实现后：缺省键缺省、不伪造占位名', function () {
    app()->bind(PersonnelNameResolver::class, fn () => new class implements PersonnelNameResolver
    {
        public function resolveNames(array $ids): array
        {
            $names  = ['7' => '张三', '9' => '李四'];
            $result = [];

            foreach ($ids as $id) {
                if ($id !== null && isset($names[(string) $id])) {
                    $result[(string) $id] = $names[(string) $id];
                }
            }

            return $result;
        }
    });

    $resolved = app(PersonnelNameResolver::class)->resolveNames(['7', '9', '404']);

    expect($resolved)->toBe(['7' => '张三', '9' => '李四'])
        ->and($resolved)->not->toHaveKey('404');
});
