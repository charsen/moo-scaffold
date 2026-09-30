<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('静态守卫完整汇总通过和失败检查，不因首次计数提前退出', function (bool $invalid) {
    $files = new Filesystem;
    $root  = sys_get_temp_dir() . '/scaffold-ui-guards-' . uniqid();
    $files->makeDirectory($root . '/tools/ui-checks', 0755, true);
    $files->makeDirectory($root . '/src/Http/Views', 0755, true);
    $files->copy(dirname(__DIR__, 3) . '/tools/ui-checks/static-guards.sh', $root . '/tools/ui-checks/static-guards.sh');
    $files->put($root . '/src/Http/Views/fixture.blade.php', $invalid ? '<style>.fixture{padding:1px}</style>' : '<div>fixture</div>');

    try {
        $process = new Process(['bash', $root . '/tools/ui-checks/static-guards.sh']);
        $process->run();
    } finally {
        $files->deleteDirectory($root);
    }

    expect($process->getExitCode())->toBe($invalid ? 1 : 0);
    expect($process->getOutput())->toContain($invalid ? 'Pass: 3   Fail: 2' : 'Pass: 5   Fail: 0');
})->with([false, true]);
