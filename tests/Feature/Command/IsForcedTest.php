<?php

declare(strict_types=1);

use Illuminate\Console\Command as IlluminateCommand;
use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Command\CreateSchemaCommand;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Input\StringInput;

/**
 * 钉死 Command::isForced() 的 VALUE_OPTIONAL→null 反直觉映射(2026-07-09 从 7 处 inline 习语抽出)。
 * 此前无任何测试覆盖「传了 -f 就是强制」这条 —— 若有人把 `=== null` 写反或把某命令的 force 声明
 * 改成 VALUE_NONE,整套 -f 覆盖行为会静默反转却全绿。此文件正向锁三种输入态。
 *
 * CreateSchemaCommand 是代表(force 声明为 VALUE_OPTIONAL + 默认 false,与其余 6 个命令同构)。
 */
function isForced_probe(string $argv): bool
{
    $cmd   = new CreateSchemaCommand(app(Filesystem::class), app(Utility::class));
    $input = new StringInput($argv);
    $input->bind($cmd->getDefinition());   // 按命令定义解析 token,复刻真实 CLI 路径

    $inputProp = new ReflectionProperty(IlluminateCommand::class, 'input');
    $inputProp->setAccessible(true);
    $inputProp->setValue($cmd, $input);

    $m = new ReflectionMethod($cmd, 'isForced');
    $m->setAccessible(true);

    return $m->invoke($cmd);
}

it('isForced:传 -f 不带值 → true(VALUE_OPTIONAL→null→强制)', function () {
    expect(isForced_probe('-f'))->toBeTrue();
});

it('isForced:传长名 --force 不带值 → true', function () {
    expect(isForced_probe('--force'))->toBeTrue();
});

it('isForced:完全不传 → false(默认 false→不强制)', function () {
    expect(isForced_probe(''))->toBeFalse();
});

it('isForced:--force=xyz 带值 → false(忠实保留原 inline 习语:非 null 即不强制)', function () {
    expect(isForced_probe('--force=xyz'))->toBeFalse();
});
