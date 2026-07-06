<?php declare(strict_types=1);

use Mooeen\Scaffold\Support\ConfigManager;

/**
 * ConfigManager::castValueForField 类型转换语义单测。
 *
 * 重点锁 MAP 的 null-vs-empty 语义:表单只回传 `__present`(没有任何 row)时返回 null,
 * 写入路径据此跳过 —— 避免 UI 渲染失败/空表单把已有 map 整个清掉(silent data loss)。
 * 这段逻辑微妙且易回归,故专项锁。
 */
function castConfigValue(mixed $raw, string $type): mixed
{
    $cm  = app(ConfigManager::class);
    $ref = new ReflectionMethod($cm, 'castValueForField');
    $ref->setAccessible(true);

    return $ref->invoke($cm, $raw, $type);
}

it('MAP: 表单行 → 关联数组', function () {
    $raw = [['k' => 'host1', 'v' => '1.1.1.1'], ['k' => 'host2', 'v' => '2.2.2.2'], '__present' => '1'];
    expect(castConfigValue($raw, ConfigManager::TYPE_MAP))->toBe(['host1' => '1.1.1.1', 'host2' => '2.2.2.2']);
});

it('MAP: 只有 __present 无 row → null(不清空已有 map)', function () {
    expect(castConfigValue(['__present' => '1'], ConfigManager::TYPE_MAP))->toBeNull();
});

it('MAP: 非数组 → null', function () {
    expect(castConfigValue('not-an-array', ConfigManager::TYPE_MAP))->toBeNull();
});

it('MAP: 跳过空 key,同 key 后者覆盖前者', function () {
    $raw = [
        ['k' => '', 'v' => 'ignored'],
        ['k'        => 'dup', 'v' => 'first'],
        ['k'        => 'dup', 'v' => 'second'],
        '__present' => '1',
    ];
    expect(castConfigValue($raw, ConfigManager::TYPE_MAP))->toBe(['dup' => 'second']);
});

it('BOOL: 真值 / 假值', function () {
    expect(castConfigValue('true', ConfigManager::TYPE_BOOL))->toBeTrue();
    expect(castConfigValue('1', ConfigManager::TYPE_BOOL))->toBeTrue();
    expect(castConfigValue('on', ConfigManager::TYPE_BOOL))->toBeTrue();
    expect(castConfigValue('false', ConfigManager::TYPE_BOOL))->toBeFalse();
    expect(castConfigValue('0', ConfigManager::TYPE_BOOL))->toBeFalse();
    expect(castConfigValue('', ConfigManager::TYPE_BOOL))->toBeFalse();
});

it('INT: 转 int', function () {
    expect(castConfigValue('42', ConfigManager::TYPE_INT))->toBe(42);
    expect(castConfigValue('0', ConfigManager::TYPE_INT))->toBe(0);
});

it('LIST: 逗号串 trim + 去空;数组输入同理;空串 → []', function () {
    expect(castConfigValue('a, b ,  , c', ConfigManager::TYPE_LIST))->toBe(['a', 'b', 'c']);
    expect(castConfigValue('', ConfigManager::TYPE_LIST))->toBe([]);
    expect(castConfigValue(['x ', ' y', ''], ConfigManager::TYPE_LIST))->toBe(['x', 'y']);
});

it('null raw 对任何 type 都保持 null', function () {
    expect(castConfigValue(null, ConfigManager::TYPE_MAP))->toBeNull();
    expect(castConfigValue(null, ConfigManager::TYPE_STRING))->toBeNull();
    expect(castConfigValue(null, ConfigManager::TYPE_BOOL))->toBeNull();
    expect(castConfigValue(null, ConfigManager::TYPE_LIST))->toBeNull();
});

it('STRING / TEXT: 转字符串', function () {
    expect(castConfigValue(123, ConfigManager::TYPE_STRING))->toBe('123');
    expect(castConfigValue('hi', ConfigManager::TYPE_TEXT))->toBe('hi');
});

// stringifyForEnv:写方向(PHP 值 → .env 串)。TYPE_LIST 字段(route.middleware → SCAFFOLD_MIDDLEWARE)
// 保存时 castValueForField 返数组,必须 implode 成逗号串 —— 否则 (string)$array = "Array" 写坏 env(2026-06-09 修)。
it('stringifyForEnv: TYPE_LIST 数组 → 逗号串(绝不写出字面量 "Array")', function () {
    $cm  = app(ConfigManager::class);
    $ref = new ReflectionMethod($cm, 'stringifyForEnv');
    $ref->setAccessible(true);

    expect($ref->invoke($cm, ['web', 'auth:api']))->toBe('web,auth:api');
    expect($ref->invoke($cm, ['web', 'auth:api']))->not->toBe('Array');
    expect($ref->invoke($cm, []))->toBe('');
    // bool / null / int 既有分支不回归
    expect($ref->invoke($cm, true))->toBe('true');
    expect($ref->invoke($cm, null))->toBe('');
    expect($ref->invoke($cm, 42))->toBe('42');
});
