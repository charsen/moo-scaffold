<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

/**
 * 路径归一的唯一口径。
 *
 * 本仓有两类「相对 → 绝对」需求，形态相近但**基线不同**，收口前各自散落多份：
 *   1. **挂到给定 base 下**（`absolute()` / `join()`）—— 基线由调用方给，例如宿主仓根（`--root`）、
 *      git toplevel、`getcwd()`。收口前有 4 份（三个命令各一份私有 `absolutePath()` +
 *      `TargetContext::pathFor()` 里那段拼接）。
 *   2. **配置项路径**（`fromBasePath()`）—— 约定「绝对则原样，否则相对 `base_path()`」。
 *      收口前有 6 份（`Utility` / `CreateTestGenerator` / `PlansRepository` /
 *      `ReleaseRecordsRepository` / `LocalMarkdownEditor`，以及
 *      `AuditFormContractCommand::resolveOutPath()`——该方法单调用，已直接内联）。
 *
 * 两类的**绝对路径判定共用 `isAbsolute()`**：`/` 开头，或 Windows 盘符（`C:\` / `C:/`）。
 * 注意收口前只有第 1 类认盘符、第 2 类不认 —— 也就是 Windows 上把 `C:\...` 写进配置项时会被当成
 * 相对路径挂到 `base_path()` 下，静默落到错地方。统一后两类共用同一判定（**仅 Windows 行为变化，且是修 bug**）。
 *
 * **不归本类的**：`rtrim($x, '/')` 这类单纯去尾斜杠（如 `rtrim($target->pathFor('model'), '/')`），
 * 它既不判断绝对/相对、也不做 base 拼接，是另一种语义；20+ 处那样写是各自场景的局部需要，别硬塞进来。
 */
final class Paths
{
    /** Windows 盘符（`C:\` / `C:/`），与 `/` 开头同为绝对路径。 */
    private const WINDOWS_DRIVE = '/^[A-Za-z]:[\\\\\\/]/';

    /**
     * 是否为绝对路径。
     */
    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match(self::WINDOWS_DRIVE, $path) === 1;
    }

    /**
     * 把 `$path` 拼到 `$base` 下（两侧斜杠去重），**不做**绝对性判断。
     */
    public static function join(string $base, string $path): string
    {
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    /**
     * `$path` 已是绝对路径则原样返回，否则拼到 `$base` 下。
     */
    public static function absolute(string $path, string $base): string
    {
        return self::isAbsolute($path) ? $path : self::join($base, $path);
    }

    /**
     * 配置项里写的路径：绝对则原样，否则相对 `base_path()`。
     *
     * 用 `base_path($path)`（而不是 `base_path() . '/' . $path`）与原实现逐字节一致。
     */
    public static function fromBasePath(string $path): string
    {
        return self::isAbsolute($path) ? $path : base_path($path);
    }
}
