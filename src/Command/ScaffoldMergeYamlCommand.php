<?php declare(strict_types=1);

/*
 * @Description: Scaffold YAML 冲突 last-write-wins 解析器（plan 18 §同步）
 *
 * 用法（由 scaffold-sync.sh 在 rebase 冲突时调用）：
 *   php artisan moo:scaffold:merge-yaml scaffold/accounts.yaml
 *
 * 策略：
 *   - accounts.yaml：按 username 行级合并，每个账号取 updated_at 较新的版本；
 *     meta.updated_at = now，meta.updated_by = auto-merge，meta.count 重算。
 *   - 其它 .yaml 文件：若两侧都有 meta.updated_at，整文件取较新的一边覆盖；
 *     不满足时退出非零，需人工解决。
 *
 * 实现细节：从 git index 三阶段读取（:2 = ours / :3 = theirs），不依赖
 * 工作树内的冲突标记文件（含 <<<<<<< 标记，没法直接 YAML parse）。
 */

namespace Mooeen\Scaffold\Command;

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class ScaffoldMergeYamlCommand extends Command
{
    protected bool $requiresLocalEnvironment = false;

    protected $name = 'moo:scaffold:merge-yaml';

    protected $description = 'Auto-merge conflicted scaffold YAML files (last-write-wins, for git sync)';

    protected $signature = 'moo:scaffold:merge-yaml
        {path : Path to the conflicted file (relative to repo root, or absolute)}
        {--dry-run : Print the merged result to stdout without writing the file}';

    public function handle(): int
    {
        $path   = (string) $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');

        // 解析仓库根 + 相对路径
        $repoRoot = $this->gitRoot();
        if ($repoRoot === null) {
            $this->error('当前不在 git 仓库内');

            return self::FAILURE;
        }

        $absolute = $this->absolutePath($path, $repoRoot);
        $relative = $this->relativeToRoot($absolute, $repoRoot);

        if (! file_exists($absolute)) {
            $this->error("文件不存在: {$absolute}");

            return self::FAILURE;
        }

        // 从 git index 读三阶段
        $ours   = $this->readStage($relative, 2, $repoRoot);
        $theirs = $this->readStage($relative, 3, $repoRoot);

        if ($ours === null || $theirs === null) {
            $this->error("无法读取冲突双方（git stage 2/3），可能不在 rebase/merge 状态：{$relative}");

            return self::FAILURE;
        }

        $oursData   = $this->parseYaml($ours);
        $theirsData = $this->parseYaml($theirs);

        if ($oursData === null || $theirsData === null) {
            $this->error('YAML 解析失败');

            return self::FAILURE;
        }

        // 按文件类型分发合并策略
        $merged = $this->dispatchMerge($relative, $oursData, $theirsData);

        if ($merged === null) {
            $this->error("无法自动合并 {$relative}：策略不支持或元数据缺失");

            return self::FAILURE;
        }

        $dumped = $this->dumpYaml($merged, $relative);

        if ($dryRun) {
            $this->line($dumped);

            return self::SUCCESS;
        }

        // plan-40 §三 R-1 横切补漏:跟全仓 LOCK_EX 一致
        file_put_contents($absolute, $dumped, LOCK_EX);
        $this->info("已合并: {$relative}");

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------
    // 分发策略
    // ------------------------------------------------------------------

    private function dispatchMerge(string $relative, array $ours, array $theirs): ?array
    {
        $base = basename($relative);

        if ($base === 'accounts.yaml') {
            return $this->mergeAccounts($ours, $theirs);
        }

        // 通用 fallback：整文件 last-write-wins
        return $this->mergeByMetaUpdatedAt($ours, $theirs);
    }

    /**
     * accounts.yaml 行级合并（key = username, 比 updated_at）
     */
    private function mergeAccounts(array $ours, array $theirs): ?array
    {
        $oursAccounts   = $this->indexByUsername($ours['accounts'] ?? []);
        $theirsAccounts = $this->indexByUsername($theirs['accounts'] ?? []);

        $merged = $oursAccounts;
        foreach ($theirsAccounts as $username => $theirRow) {
            $ourRow = $merged[$username] ?? null;
            if ($ourRow === null) {
                $merged[$username] = $theirRow;

                continue;
            }
            // 双方都有 → 比 updated_at
            if ($this->isLater($theirRow['updated_at'] ?? '', $ourRow['updated_at'] ?? '')) {
                $merged[$username] = $theirRow;
            }
        }

        // 重建 list 形式（YAML 期望 accounts 是 sequence）
        $accountsList = array_values($merged);

        // meta：保留 schema_version，updated_at / by 改为 auto-merge 现场
        $oursMeta   = (array) ($ours['meta'] ?? []);
        $theirsMeta = (array) ($theirs['meta'] ?? []);
        $meta       = array_replace($oursMeta, [
            'schema_version' => $oursMeta['schema_version'] ?? $theirsMeta['schema_version'] ?? 1,
            'updated_at'     => date('Y-m-d H:i:s'),
            'updated_by'     => 'sync:auto-merge',
            'last_action'    => 'merge:last-write-wins',
            'source'         => 'scaffold-sync',
            'count'          => count($accountsList),
        ]);

        return [
            'meta'     => $meta,
            'accounts' => $accountsList,
        ];
    }

    /**
     * 通用整文件 last-write-wins：依赖 meta.updated_at 字段做仲裁。
     */
    private function mergeByMetaUpdatedAt(array $ours, array $theirs): ?array
    {
        $oursAt   = $this->extractUpdatedAt($ours);
        $theirsAt = $this->extractUpdatedAt($theirs);

        if ($oursAt === '' && $theirsAt === '') {
            return null;
        }

        return $this->isLater($theirsAt, $oursAt) ? $theirs : $ours;
    }

    private function extractUpdatedAt(array $data): string
    {
        if (isset($data['meta']['updated_at'])) {
            return (string) $data['meta']['updated_at'];
        }
        if (isset($data['updated_at'])) {
            return (string) $data['updated_at'];
        }

        return '';
    }

    private function indexByUsername(array $list): array
    {
        $out = [];
        foreach ($list as $row) {
            if (! is_array($row)) {
                continue;
            }
            $u = trim((string) ($row['username'] ?? ''));
            if ($u === '') {
                continue;
            }
            $out[$u] = $row;
        }

        return $out;
    }

    /**
     * $a 是否晚于 $b（字符串比较够用，'Y-m-d H:i:s' 字典序与时序一致）
     */
    private function isLater(string $a, string $b): bool
    {
        if ($a === '' && $b === '') {
            return false;
        }
        if ($a === '') {
            return false;
        }
        if ($b === '') {
            return true;
        }

        return strcmp($a, $b) > 0;
    }

    // ------------------------------------------------------------------
    // git / IO 辅助
    // ------------------------------------------------------------------

    private function gitRoot(): ?string
    {
        $proc = Process::fromShellCommandline('git rev-parse --show-toplevel');
        $proc->run();
        if (! $proc->isSuccessful()) {
            return null;
        }
        $out = trim($proc->getOutput());

        return $out === '' ? null : $out;
    }

    private function absolutePath(string $path, string $repoRoot): string
    {
        if ($path !== '' && $path[0] === '/') {
            return $path;
        }

        return rtrim($repoRoot, '/') . '/' . $path;
    }

    private function relativeToRoot(string $absolute, string $repoRoot): string
    {
        $root = rtrim($repoRoot, '/') . '/';
        if (str_starts_with($absolute, $root)) {
            return substr($absolute, strlen($root));
        }

        return $absolute;
    }

    /**
     * 读 git index 某个 stage（2 = ours, 3 = theirs, 1 = base）
     * 返回 null = stage 不存在（文件未冲突 / 非合并状态）
     */
    private function readStage(string $relative, int $stage, string $repoRoot): ?string
    {
        $proc = new Process(['git', 'show', ":{$stage}:{$relative}"], $repoRoot);
        $proc->run();
        if (! $proc->isSuccessful()) {
            return null;
        }

        return $proc->getOutput();
    }

    private function parseYaml(string $content): ?array
    {
        try {
            $data = Yaml::parse($content);

            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function dumpYaml(array $data, string $relative): string
    {
        $body = Yaml::dump($data, 4, 4, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

        // accounts.yaml 保留文件头注释
        if (basename($relative) === 'accounts.yaml') {
            $header = "# Scaffold 开发人员账号（plan 18）\n"
                . "# 由 scaffold UI / `moo:account:*` 命令维护；勿手改 schema_version。\n"
                . "# 本文件**入 git**：团队 + 远程部署通过 scaffold-sync.sh 同步；密码以 bcrypt hash 存储。\n\n";

            return $header . $body;
        }

        return $body;
    }
}
