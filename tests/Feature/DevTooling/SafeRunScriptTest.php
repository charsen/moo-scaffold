<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function safeRunCheck(array $command): void
{
    $process = new Process($command, dirname(__DIR__, 3));
    $process->setTimeout(60);
    $process->run();

    expect($process->isSuccessful() ? 'OK' : $process->getErrorOutput() . $process->getOutput())->toBe('OK');
}

it('safe-run bash entry point has valid syntax', function () {
    safeRunCheck(['bash', '-n', 'tests/Browser/safe-run.sh']);
});

it('safe-run Node implementation has valid syntax', function () {
    safeRunCheck(['node', '--check', 'tests/Browser/safe-run.cjs']);
});

it('safe-run protects real Git fixtures and reports cleanup failures', function () {
    safeRunCheck(['node', 'tests/javascript/e2e-safe-run.test.js']);
});
