<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

/**
 * 记录范围（研发计划 / 发版记录）与它们唯一的配置目录。
 *
 * 一个范围 = 一个 `scaffold.<scope>.path` 配置项 + 一个目录，该目录是**读写唯一边界**：
 * 目录外软链指向的目标既不读、也不算可编辑。这套知识此前散在三处
 * （`PlansRepository` / `ReleaseRecordsRepository` / `LocalMarkdownEditor`）：范围白名单、
 * 配置键拼法、`realpath` + `is_dir` 边界、越界判定各写一份，改口径要同时改三遍。
 * 2026-09-18 收口到本类；两个仓库的其余公共骨架见 {@see MarkdownFileRepository}。
 *
 * 为什么 `docs` 不在此列：文档中心的目录解析走 {@see TargetContext}（要支持扩展包源，
 * 路径可能是包目录），只读 / 写入边界是另一套口径，不共用这里的判定。
 */
final class RecordScope
{
    /** 允许的范围；键即 `scaffold.<scope>.path` 里的 `<scope>`。 */
    public const SCOPES = ['plans', 'release_records'];

    public static function allows(string $scope): bool
    {
        return in_array($scope, self::SCOPES, true);
    }

    /**
     * 配置目录的真实路径；未配置、不存在、不是目录时返回 `null`。
     *
     * `is_dir` 不能省：配置项若写成一个**文件**的路径，少这一步就会把该文件所在目录当成
     * 记录目录扫走（`allFiles()` 在非目录上直接抛异常）。
     */
    public static function directory(string $scope): ?string
    {
        $configured = (string) config('scaffold.' . $scope . '.path');
        if ($configured === '') {
            return null;
        }
        $base = realpath(Paths::fromBasePath($configured));

        return $base === false || ! is_dir($base) ? null : $base;
    }

    /**
     * 已 `realpath` 的绝对路径 `$real` 是否在该目录内 —— 目录外软链目标的越界判定。
     *
     * 比较用 `$base . DIRECTORY_SEPARATOR` 而不是裸 `$base`：否则 `/a/bc/d.md` 会被判成
     * 在 `/a/b` 内。边界目录自身不算「在内」（记录目录下只可能是文件）。
     */
    public static function contains(string $base, string $real): bool
    {
        return str_starts_with($real, $base . DIRECTORY_SEPARATOR);
    }
}
