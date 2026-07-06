<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use RuntimeException;

/**
 * .env 文件编辑器：行级原地替换。
 *
 * 设计原则：
 *   - 保留所有注释、空行、行顺序
 *   - 仅替换已有 key 的 value；不支持新增 key（避免误增）
 *   - 自动决定值是否加引号（含空格 / # / = 等就加双引号并 escape）
 *   - 写入流程：临时文件 → rename，原子操作
 *
 * 不做的事：
 *   - 多行值（heredoc 等）：.env 习惯上单行，不支持
 *   - .env 之外的 dotenv 变体
 */
class EnvFileEditor
{
    /**
     * 批量写 .env。$writes 为 KEY => 字符串值。
     *
     * @param array<string, string> $writes
     */
    public function setKeysInFile(string $filePath, array $writes): void
    {
        if ($writes === []) {
            return;
        }
        if (! is_file($filePath)) {
            throw new RuntimeException(".env 文件不存在：{$filePath}");
        }

        $source = file_get_contents($filePath);
        if ($source === false) {
            throw new RuntimeException(".env 读取失败：{$filePath}");
        }

        $eol   = str_contains($source, "\r\n") ? "\r\n" : "\n";
        $lines = explode($eol, $source);

        // 更新**所有**同 key 行:.env 里 key 重复时 dotenv 语义是「后者生效」,
        // 原先只改第一处就停 → 真正生效的后一处仍是旧值,UI 报成功实际没改(2026-06-10 修)。
        $found = [];
        foreach ($lines as $i => $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (! preg_match('/^(\s*)([A-Z_][A-Z0-9_]*)\s*=/', $line, $m)) {
                continue;
            }
            $indent = $m[1];
            $key    = $m[2];
            if (! array_key_exists($key, $writes)) {
                continue;
            }

            $lines[$i]   = $indent . $key . '=' . $this->formatValue($writes[$key]);
            $found[$key] = true;
        }

        $missing = array_diff_key($writes, $found);
        if ($missing !== []) {
            throw new RuntimeException(
                '以下 env key 在 .env 中不存在，拒绝新增：' . implode(', ', array_keys($missing))
            );
        }

        $next = implode($eol, $lines);

        $tmp = $filePath . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $next) === false) {
            throw new RuntimeException(".env 临时文件写入失败：{$tmp}");
        }
        if (! @rename($tmp, $filePath)) {
            @unlink($tmp);
            throw new RuntimeException(".env rename 失败：{$tmp} → {$filePath}");
        }
    }

    /**
     * 决定值是否需要加引号；含空格 / # / 引号 / = 等就加双引号并 escape。
     */
    private function formatValue(string $value): string
    {
        if ($value === '') {
            return '';
        }
        // 纯安全字符（字母数字 . - _ /）直接裸写
        if (preg_match('/^[A-Za-z0-9._\-\/]+$/', $value)) {
            return $value;
        }
        // 否则用双引号，转义 \" 和 \$ 和 \\ 和反引号
        $escaped = strtr($value, [
            '\\' => '\\\\',
            '"'  => '\\"',
            '$'  => '\\$',
            '`'  => '\\`',
        ]);

        return '"' . $escaped . '"';
    }
}
