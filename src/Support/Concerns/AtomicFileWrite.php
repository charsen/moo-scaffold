<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Support\Concerns;

use RuntimeException;

/**
 * 原子文件写：同目录 tmp + rename，并保留已存在目标的权限位。
 *
 * 2026-09-11 收口。此前全仓同一件事有 4 种写法：
 *   - `file_put_contents($path, $c, LOCK_EX)` × 9（SchemaLoader 6 + SnapshotStore 3）
 *   - 手写 tmp + rename × 2（EnvFileEditor / PhpFileEditor）
 *   - `Filesystem::replace()` × 1（LocalMarkdownEditor）
 *
 * 两类缺陷：
 *   1. **非原子**：写一半进程崩 / 机器断电 → 磁盘上留半份 YAML。schema YAML 是设计真相源，
 *      半份文件比一次写失败更难恢复（写失败原文件完好，半份文件内容已丢）。并发读者
 *      （designer autosave 与终端 `moo:*` 同时跑、index 页轮询）也可能读到撕裂内容。
 *   2. **丢权限位**：手写的 tmp + rename 都不 chmod。`file_put_contents` 对已存在文件是
 *      原地写、权限不变；而 tmp 文件按 umask 新建（通常 0644），rename 换掉 inode 后
 *      原文件更严的位（如 `.env` 的 0600/0640）**每次保存都被悄悄放宽**。这是确定性的，
 *      不是概率问题。
 *
 * 刻意不做的事（已知取舍，勿当遗漏）：
 *   - **不 fsync**：与本仓既有实现（含 Laravel `Filesystem::replace`）保持一致，不为每次
 *     保存加 syscall。代价是断电场景仍有丢数据可能，但不会留半份文件。
 *   - **不加 flock**：读-改-写的丢更新窗口（两个 tab 同时提交同一 schema）不是锁能解决的
 *     —— 锁只串行化写入动作，读到的仍是旧版本。要真正解决得靠版本号/CAS，成本远超收益。
 *   - **不做 symlink 策略**：目标若是符号链接，`rename` 会把链接本身替换成普通文件
 *     （而 `file_put_contents` 会写穿）。调用方负责先用 `realpath` 解析路径
 *     （SchemaLoader / SnapshotStore 均已如此；LocalMarkdownEditor 另有显式拒绝）。
 *
 * 隐式约束：`$path` 的父目录必须存在且可写，调用方负责先校验。
 */
trait AtomicFileWrite
{
    /**
     * 原子写 `$content` 到 `$path`。
     *
     * 临时文件用随机后缀（`.tmp.{hex}`），不会命中本仓的目录扫描 glob
     * （`*.yaml` / `*_table.php` / `*.php`），因此不会被当成真文件读走。
     *
     * @throws RuntimeException 目录不可写 / 临时文件写入失败 / rename 失败时；
     *                          三种情况都不会改动 `$path`，也不会留下 tmp 文件。
     */
    protected function writeFileAtomically(string $path, string $content): void
    {
        $dir = dirname($path);
        if (! is_dir($dir) || ! is_writable($dir)) {
            throw new RuntimeException("目录不存在或不可写：{$dir}");
        }

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $content) === false) {
            @unlink($tmp);

            throw new RuntimeException("临时文件写入失败：{$tmp}");
        }

        // 保留原文件权限位（含 setuid/setgid/sticky，用 07777 而非 0777 —— SGID 共享目录
        // 下的文件可能合法带这些位）。文件不存在时（首次创建）走 umask 默认，与原来一致。
        if (is_file($path)) {
            $mode = @fileperms($path);
            if ($mode !== false) {
                @chmod($tmp, $mode & 07777);
            }
        }

        if (! @rename($tmp, $path)) {
            @unlink($tmp);

            throw new RuntimeException("rename 失败：{$tmp} → {$path}");
        }
    }
}
