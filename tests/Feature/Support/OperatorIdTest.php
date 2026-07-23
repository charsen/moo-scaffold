<?php

declare(strict_types=1);

use Mooeen\Scaffold\Support\OperatorId;

/**
 * OperatorId::normalize 单测（plan 42 · Phase F）。
 *
 * 锁：三家哨兵并集（null/''/0/'0'/全零串）→ null；正常 int/string id、前导零非零串、
 * 负数、非数字串一律原样透传（类型保持，=== 比对）。
 */
it('null → null', function () {
    expect(OperatorId::normalize(null))->toBeNull();
});

it('空串 → null', function () {
    expect(OperatorId::normalize(''))->toBeNull();
});

it('int 0 → null', function () {
    expect(OperatorId::normalize(0))->toBeNull();
});

it('字符串 0 → null', function () {
    expect(OperatorId::normalize('0'))->toBeNull();
});

it('全零字符串（trail 语义）→ null', function (string $zeros) {
    expect(OperatorId::normalize($zeros))->toBeNull();
})->with(['00', '000', '0000000000000000000']);

it('正整数原样返回且保持 int 类型', function () {
    expect(OperatorId::normalize(42))->toBe(42);
});

it('数字字符串原样返回且保持 string 类型', function () {
    expect(OperatorId::normalize('42'))->toBe('42');
});

it('雪花字符串原样返回', function () {
    expect(OperatorId::normalize('1234567890123456789'))->toBe('1234567890123456789');
});

it('前导零非零串不是哨兵，原样返回（收窄留给下游 positiveId）', function () {
    expect(OperatorId::normalize('007'))->toBe('007');
});

it('负整数不是哨兵，原样透传（由下游各包处置）', function () {
    expect(OperatorId::normalize(-5))->toBe(-5);
});

it('非数字串（0x 等）不是哨兵，原样透传', function () {
    expect(OperatorId::normalize('0x'))->toBe('0x');
});
