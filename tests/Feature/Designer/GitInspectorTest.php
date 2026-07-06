<?php declare(strict_types=1);

use Mooeen\Scaffold\Designer\GitInspector;
use Mooeen\Scaffold\Designer\NotInGitRepoException;

/**
 * GitInspector 单测 — 用 sys_get_temp_dir() 起临时目录,初始化 git repo。
 *
 * Plan 39:GitInspector 砍到只剩 repoRoot()。其它方法(shortSha / hashObject / showFile /
 * run / isInGitRepo)已在 plan-39 砍 GUI auto-commit 时移除。
 */
function makeTmpRepo(): string
{
    $dir = sys_get_temp_dir() . '/scaffold-gitinspector-' . uniqid('', true);
    mkdir($dir, 0777, true);
    chdir($dir);
    shell_exec('git init -q -b main 2>&1');
    shell_exec('git config user.email "test@test.local" 2>&1');
    shell_exec('git config user.name "Test User" 2>&1');
    file_put_contents("{$dir}/hello.txt", "hello world\n");
    shell_exec('git add . 2>&1');
    shell_exec('git commit -q -m "initial" 2>&1');

    return $dir;
}

function cleanTmpRepo(string $dir): void
{
    if (is_dir($dir)) {
        shell_exec('rm -rf ' . escapeshellarg($dir));
    }
}

it('repoRoot returns toplevel + caches result', function () {
    $dir  = makeTmpRepo();
    $g    = new GitInspector($dir);
    $root = $g->repoRoot();
    expect($root)->toBe(realpath($dir));
    // 第二次走 cache
    expect($g->repoRoot())->toBe($root);
    cleanTmpRepo($dir);
});

it('repoRoot throws NotInGitRepoException in non-repo dir', function () {
    $dir = sys_get_temp_dir() . '/scaffold-no-repo-' . uniqid('', true);
    mkdir($dir, 0777, true);
    $g = new GitInspector($dir);
    expect(fn () => $g->repoRoot())->toThrow(NotInGitRepoException::class);
    rmdir($dir);
});
