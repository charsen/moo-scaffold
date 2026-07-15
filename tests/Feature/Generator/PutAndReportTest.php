<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Generator\Generator;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * 钉死基类 Generator::putAndReport 的三态分支 —— 2026-07-09 收编 11 处手搓三态报告
 * (CreateModel/Controller/Resource/Schema/Test/View/TSModel)统一到这个助手,
 * 但助手本身的 created / overwritten / updated 分支此前无任何测试覆盖。此文件补上。
 */
function putAndReport_invoke(Generator $gen, string $file, string $relative, string $content, string $verb = 'overwritten'): void
{
    $m = new ReflectionMethod($gen, 'putAndReport');
    $m->setAccessible(true);
    $m->invoke($gen, $file, $relative, $content, $verb);
}

function putAndReport_gen(BufferedOutput $buffer, ?Filesystem $filesystem = null): Generator
{
    return new Generator($buffer, $filesystem ?? new Filesystem, app(Utility::class));
}

it('putAndReport:目标不存在 → 写入 + 报 created(不误报 overwritten)', function () {
    $buffer = new BufferedOutput;
    $file   = sys_get_temp_dir() . '/moo_par_' . uniqid() . '.txt';
    expect(is_file($file))->toBeFalse();

    putAndReport_invoke(putAndReport_gen($buffer), $file, './rel/new.txt', 'content-A');

    $out = $buffer->fetch();
    expect($out)->toContain('Created');
    expect($out)->not->toContain('Overwritten');
    expect($out)->toContain('rel/new.txt');
    expect(file_get_contents($file))->toBe('content-A');

    @unlink($file);
});

it('putAndReport:目标已存在 → 覆写 + 报 overwritten(不误报 created)', function () {
    $buffer = new BufferedOutput;
    $file   = sys_get_temp_dir() . '/moo_par_' . uniqid() . '.txt';
    file_put_contents($file, 'old-content');

    putAndReport_invoke(putAndReport_gen($buffer), $file, './rel/exist.txt', 'content-B');

    $out = $buffer->fetch();
    expect($out)->toContain('Overwritten');
    expect($out)->not->toContain('Created');
    expect(file_get_contents($file))->toBe('content-B');   // 内容真被覆写

    @unlink($file);
});

it('putAndReport:existVerb=updated → 已存在时报 updated 而非 overwritten', function () {
    $buffer = new BufferedOutput;
    $file   = sys_get_temp_dir() . '/moo_par_' . uniqid() . '.txt';
    file_put_contents($file, 'old-content');

    putAndReport_invoke(putAndReport_gen($buffer), $file, './rel/upd.txt', 'content-C', 'updated');

    $out = $buffer->fetch();
    expect($out)->toContain('Updated');
    expect($out)->not->toContain('Overwritten');

    @unlink($file);
});

it('putAndReport:existVerb=updated 但目标不存在 → 仍报 created(created 分支不看 verb)', function () {
    $buffer = new BufferedOutput;
    $file   = sys_get_temp_dir() . '/moo_par_' . uniqid() . '.txt';
    expect(is_file($file))->toBeFalse();

    putAndReport_invoke(putAndReport_gen($buffer), $file, './rel/upd-new.txt', 'content-D', 'updated');

    $out = $buffer->fetch();
    expect($out)->toContain('Created');
    expect($out)->not->toContain('Updated');

    @unlink($file);
});

it('putAndReport:写入失败 → 抛异常且不报告成功', function () {
    $buffer     = new BufferedOutput;
    $filesystem = new class extends Filesystem
    {
        public function isFile($file)
        {
            return false;
        }

        public function put($path, $contents, $lock = false)
        {
            return false;
        }
    };
    $file = '/unwritable/generated.php';

    expect(fn () => putAndReport_invoke(
        putAndReport_gen($buffer, $filesystem),
        $file,
        './generated.php',
        'content'
    ))->toThrow(RuntimeException::class, "文件写入失败：{$file}");

    expect($buffer->fetch())->toBe('');
});
