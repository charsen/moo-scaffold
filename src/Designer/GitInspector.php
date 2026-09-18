<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Designer;

use Symfony\Component\Process\Process;

/**
 * Thin git CLI wrapper —— 只回答一个问题:**某个目录所属的 git 仓根在哪**。
 *
 * Plan 39:砍掉 GUI auto-commit 后,GitInspector 只剩 repoRoot()。
 * 2026-09-18 收口:全仓三处 `git rev-parse --show-toplevel`(本类 / MigrationCompacter /
 * `moo:scaffold:merge-yaml` 命令)统一走这里 —— **cwd 变成参数**而不是各自 `new Process`。
 * 之所以必须可传:`moo:scaffold:merge-yaml` 要问的是**进程 cwd**(不是 `base_path()`,
 * 它的测试就靠 chdir 到临时仓),而 MigrationCompacter 要问的是**按 schema 出身算出的包根**。
 *
 * shortSha / hashObject / showFile / run / isInGitRepo 全删 — 都是 commit / plan-30 baseline 时代死代码。
 * **别因为「顺手」再把它们加回来**:本类是薄包装,不是 MigrationCompacter 的专用助手
 * (那边 `abbrev-ref HEAD` / `rev-parse --verify` / `log --format=%H` / `show :stage:path`
 * 各自只有一个消费者,留在原地)。
 */
class GitInspector
{
    /** @var array<string,string> cwd => repo root。**按 cwd 分桶**,因为同进程里会问多个仓(宿主 + 各扩展包)。 */
    private array $repoRootCache = [];

    public function __construct(
        private readonly string $cwd,
    ) {}

    /**
     * 返回 git 仓库的 toplevel 路径(可能是 $cwd 自己,或它的某个祖先目录)。
     *
     * @param string|null $cwd     查哪个目录所属的仓;null = 构造时绑定的 cwd
     * @param int         $timeout 秒。各调用点的历史值不同(20 / 10),**刻意不统一** —— 那是既有行为,不是风格
     *
     * @throws NotInGitRepoException 不在 git 仓内,或 git 返回空路径
     */
    public function repoRoot(?string $cwd = null, int $timeout = 20): string
    {
        $cwd = $cwd ?? $this->cwd;

        if (array_key_exists($cwd, $this->repoRootCache)) {
            return $this->repoRootCache[$cwd];
        }

        $proc = new Process(['git', 'rev-parse', '--show-toplevel'], $cwd);
        $proc->setTimeout($timeout);
        $proc->run();

        // 空输出同样算失败(原 MigrationCompacter 内联版显式判过 `trim(...) === ''`):
        // 放过去的话 `MigrationWriter::relPath` 会拿空 root 做前缀 strip —— 而空前缀就是 `/`,
        // 于是绝对路径的**头一个斜杠**被切掉、静默产出错路径。
        $root = trim($proc->getOutput());
        if (! $proc->isSuccessful() || $root === '') {
            throw new NotInGitRepoException("not a git repository: {$cwd}");
        }

        return $this->repoRootCache[$cwd] = $root;
    }

    /**
     * repoRoot() 的「不抛」形态:不在 git 仓内(或 git 失败)返回 null。
     *
     * 给「不在仓内就报错退出」的用途(`moo:scaffold:merge-yaml`)。与 `SnapshotStore::capture()` /
     * `captureTables()` 的「抛 / 吞成对保留」同一先例 —— 两种失败处理都承重,别合并成一个带 flag 的入口:
     * 调用点要能一眼看出自己走的是哪一支。
     */
    public function repoRootOrNull(?string $cwd = null, int $timeout = 20): ?string
    {
        try {
            return $this->repoRoot($cwd, $timeout);
        } catch (NotInGitRepoException) {
            return null;
        }
    }
}
