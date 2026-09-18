<?php declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Designer\GitInspector;
use Mooeen\Scaffold\Designer\NotInGitRepoException;
use Symfony\Component\Process\Process;

/**
 * GitInspector 单测 — 用 sys_get_temp_dir() 起临时目录，初始化 git repo。
 *
 * Plan 39：GitInspector 砍到只剩 repoRoot()。
 * 2026-09-18：cwd 变成**参数**（全仓三处 `rev-parse --show-toplevel` 收口 —— 本类 /
 * MigrationCompacter / `moo:scaffold:merge-yaml`），因此补「显式 cwd」与「按 cwd 分桶的 memo」
 * 两条 —— 它们正是收口后新增的能力，收口前根本测不到。
 *
 * ⚠ **本文件的 helper 不再 chdir**（原版 `makeTmpRepo()` 会 `chdir()` 后由 `cleanTmpRepo()`
 * 把当前目录 `rm -rf` 掉 ⇒ 进程 cwd 停在**已删除**的目录上）。这直接威胁本次收口新增的代码：
 * `moo:scaffold:merge-yaml` 的仓根探测走 `getcwd() ?: base_path()`，cwd 若是死目录就会静默回退到
 * base_path，命令跑到错的仓上。改用 `git -C <dir>`，进程 cwd 全程不动。
 */
$GLOBALS['gitInspectorFixtures'] = [];

afterEach(function () {
    $fs = new Filesystem;
    foreach ($GLOBALS['gitInspectorFixtures'] ?? [] as $dir) {
        if (is_dir($dir)) {
            $fs->deleteDirectory($dir);
        }
    }
    $GLOBALS['gitInspectorFixtures'] = [];
});

/** 在 $dir 里跑一条 git 命令（`-C` 而不是 chdir）；失败即抛，避免测试静默绿。 */
function gitInspectorGit(string $dir, array $args): string
{
    $proc = new Process(array_merge(['git', '-C', $dir], $args));
    $proc->run();

    if (! $proc->isSuccessful()) {
        throw new RuntimeException('git ' . implode(' ', $args) . ' 失败：' . $proc->getErrorOutput());
    }

    return $proc->getOutput();
}

/** 造一个已 commit 一次的临时 git 仓，返回目录（未 realpath —— 断言侧统一用 realpath 归一）。 */
function gitInspectorRepo(): string
{
    $dir = sys_get_temp_dir() . '/scaffold-gitinspector-' . bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    $GLOBALS['gitInspectorFixtures'][] = $dir;

    gitInspectorGit($dir, ['init', '-q', '-b', 'main']);
    gitInspectorGit($dir, ['config', 'user.email', 'test@test.local']);
    gitInspectorGit($dir, ['config', 'user.name', 'Test User']);
    file_put_contents($dir . '/hello.txt', "hello world\n");
    gitInspectorGit($dir, ['add', '.']);
    gitInspectorGit($dir, ['commit', '-q', '-m', 'initial']);

    return $dir;
}

/** 造一个**不在**任何 git 仓内的临时目录（sys_get_temp_dir() 下不会嵌在仓里）。 */
function gitInspectorNonRepo(): string
{
    $dir = sys_get_temp_dir() . '/scaffold-no-repo-' . bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    $GLOBALS['gitInspectorFixtures'][] = $dir;

    return $dir;
}

it('repoRoot returns toplevel + caches result', function () {
    $dir  = gitInspectorRepo();
    $g    = new GitInspector($dir);
    $root = $g->repoRoot();
    expect($root)->toBe(realpath($dir));
    // 第二次走 cache
    expect($g->repoRoot())->toBe($root);
});

it('repoRoot throws NotInGitRepoException in non-repo dir', function () {
    $dir = gitInspectorNonRepo();
    $g   = new GitInspector($dir);
    expect(fn () => $g->repoRoot())->toThrow(NotInGitRepoException::class);
});

it('repoRoot($cwd)：问的是传入 cwd 所属的仓，且 memo 按 cwd 分桶（不互相覆盖）', function () {
    $a = gitInspectorRepo();
    $b = gitInspectorRepo();

    $g = new GitInspector($a);

    expect($g->repoRoot())->toBe(realpath($a))
        ->and($g->repoRoot($b))->toBe(realpath($b))     // 显式 cwd 生效
        // 若 memo 是单值（B 覆盖 A），这一条会拿到 B —— 这就是「按 cwd 分桶」的鉴别点
        ->and($g->repoRoot())->toBe(realpath($a));
});

it('repoRoot($cwd)：显式 cwd 不在仓内 → 抛；绑定 cwd 在仓内也不兜底', function () {
    $a       = gitInspectorRepo();
    $outside = gitInspectorNonRepo();

    $g = new GitInspector($a);

    expect(fn () => $g->repoRoot($outside))->toThrow(NotInGitRepoException::class)
        // 失败不污染缓存：绑定 cwd 仍然可查
        ->and($g->repoRoot())->toBe(realpath($a));
});

it('repoRootOrNull()：仓内返根、仓外返 null（不抛）', function () {
    $a  = gitInspectorRepo();
    $no = gitInspectorNonRepo();

    $g = new GitInspector($no);

    expect($g->repoRootOrNull())->toBeNull()
        ->and($g->repoRootOrNull($a))->toBe(realpath($a))
        // 失败不写缓存 ⇒ 再问还是 null（而不是把上一次成功的 a 当默认值返回）
        ->and($g->repoRootOrNull())->toBeNull();
});
