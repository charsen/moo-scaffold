<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Designer;

use Symfony\Component\Process\Process;

/**
 * Thin git CLI wrapper.
 *
 * Plan 39:砍掉 GUI auto-commit 后,GitInspector 只留 repoRoot()。
 * MigrationWriter::relPath 用它把绝对路径相对化(repo root prefix strip)。
 * shortSha / hashObject / showFile / run / isInGitRepo 全删 — 都是 commit / plan-30 baseline 时代死代码。
 */
class GitInspector
{
    private ?string $repoRoot = null;     // 缓存:`git rev-parse --show-toplevel`

    public function __construct(
        private readonly string $cwd,
    ) {}

    /**
     * 返回 git 仓库的 toplevel 路径(可能是 cwd 自己或它的某个祖先目录)。
     *
     * @throws NotInGitRepoException
     */
    public function repoRoot(): string
    {
        if ($this->repoRoot !== null) {
            return $this->repoRoot;
        }
        $proc = new Process(['git', 'rev-parse', '--show-toplevel'], $this->cwd);
        $proc->setTimeout(20);
        $proc->run();
        if (! $proc->isSuccessful()) {
            throw new NotInGitRepoException("not a git repository: {$this->cwd}");
        }

        return $this->repoRoot = trim($proc->getOutput());
    }
}
