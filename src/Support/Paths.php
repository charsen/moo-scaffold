<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use InvalidArgumentException;

/**
 * 路径的**唯一口径** —— 两件事都在这里，别再各自实现：
 *
 * **① 归一（是否绝对 / 怎么拼）**：本仓有两类「相对 → 绝对」需求，形态相近但**基线不同**，收口前各自散落多份：
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
 * **② scaffold 各资产的配置位置**（2026-09-19 自 `Utility` 外迁，第 3 项 · 阶段 3b-2）：
 * `model()` / `resource()` / `appResource()` / `controller()` / `migration()` / `storage()` /
 * `api()` / `acl()` / `database()` / `schema()` / `namespaceOf()`。迁进来时**方法体逐字未动**，
 * 只换了接收者与名字（`Utility::getModelPath()` → `Paths::model()`，`Utility::formatNameSpace()` →
 * `Paths::namespaceOf()`；去掉与类名重复的 `get*Path` 前缀）。`Utility::isApiFileExist()` **没有**跟着来 ——
 * 它做的是「解析 + 断言文件存在」，存在性检查是 IO，不属于纯解析；它现在调 `Paths::api()` 再自己
 * `$this->filesystem->isFile()`。
 *
 * **参数类型是同批收尾时收紧的（方法体仍未动一个字）**：原 `Utility` 那批方法的 `$relative` 与键名参数
 * 大多无类型，于是 `controller(true)` 不报错 —— `true` 被当成**键名**用 ⇒ `config('scaffold.1')` 取到 null
 * ⇒ **静默返回 `base_path()` 本身**；`schema(true)` 更隐蔽，`true` 被拼成字符串 `"1"` ⇒
 * 返回 `".../scaffold/database/1"`，**路径错但像对的**。现在 10 个方法的 `$relative` 一律 `bool`、
 * 键名参数一律 `string`（`schema()` 的 `$file_name` 是 `?string`，`null` 表示「只要目录」），
 * 误用一律 **TypeError（响亮）**。全仓调用点逐个核过（都是 `true` 字面量或字符串，含命名参数
 * `model(relative: true)`）⇒ **零行为变更**；「响亮 vs 静默」这件事由 `PathsTest` 的「参数形状」锚点钉住。
 *
 * 搬过来之后要留意三处**有意保留的不对称**（都有用例钉着，别"顺手统一"）：
 *   - `$relative = true` 去掉的前缀**不全是 `base_path()`**：只有 `storage()` 去掉 `storage_path()`
 *     （`migration()` 取的是 `database_path('migrations/')`，但去掉的仍是 `base_path()`）。
 *   - `database()` 走 {@see fromBasePath()}（**认绝对路径**，绝对则原样），而 `model()` / `api()` /
 *     `acl()` 等走裸 `base_path($config)`（配置写绝对路径会被拼到 `base_path()` 下）——
 *     这是外迁前的既有形态，未改；要统一得单独立项并核下游配置。
 *   - `acl()` 的 `scaffold/acl/` 是硬编码，不读配置。
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

    /**
     * 数据字典与代码生成产物的落地根目录
     */
    public static function database(string $folder = 'schema', bool $relative = false): string
    {
        $configured = (string) config('scaffold.database.' . $folder);
        $path       = self::fromBasePath($configured);

        return $relative ? str_replace(base_path(), '.', $path) : $path;
    }

    /**
     * 获取 schema 文件路径
     */
    public static function schema(?string $file_name = null, bool $relative = false): string
    {
        $path = self::database('schema', $relative);

        return $file_name === null ? $path : ($path . $file_name);
    }

    /**
     * Get Model Path
     */
    public static function model(bool $relative = false): string
    {
        $path = base_path(config('scaffold.model.path'));

        return $relative ? str_replace(base_path(), '.', $path) : $path;
    }

    /**
     * Get Resource Path
     *
     * 2026-09-19 前是 `Utility` 的 private（唯一调用点是 `targetContext()` 的 host 臂），
     * 外迁后成为本类的公开成员 —— 它是 `.` 相对形态的 `resource.path`，与 `model()` 同形。
     */
    public static function resource(bool $relative = false): string
    {
        $path = base_path(config('scaffold.resource.path'));

        return $relative ? str_replace(base_path(), '.', $path) : $path;
    }

    /**
     * Get App Resource Path
     */
    public static function appResource(string $app, bool $relative = false): string
    {
        $target = app(AppTargetRegistry::class)->get($app);
        $path   = trim((string) ($target['resource_path'] ?? ''));
        if ($path === '') {
            throw new InvalidArgumentException("应用端 [{$app}] 未配置 resource_path。");
        }
        $path = base_path($path);

        return $relative ? str_replace(base_path(), '.', $path) : $path;
    }

    /**
     * Get Controller Path
     */
    public static function controller(string $key = 'controller.admin.path', bool $relative = false): string
    {
        $path = base_path(config('scaffold.' . $key));

        return $relative ? str_replace(base_path(), '.', $path) : $path;
    }

    /**
     * Get Migration Path
     */
    public static function migration(bool $relative = false): string
    {
        $path = database_path('migrations/');

        return $relative ? str_replace(base_path(), '.', $path) : $path;
    }

    /**
     * Get Storage Path
     *
     * 注意 `$relative = true` 去掉的是 **`storage_path()`** 而不是 `base_path()` ——
     * 本类里唯一一个基线不是 `base_path()` 的（`migration()` 取的虽是 `database_path()`，
     * 去掉的却仍是 `base_path()`）。用例钉着，别顺手统一。
     */
    public static function storage(bool $relative = false): string
    {
        $path = storage_path('scaffold/');

        return $relative ? str_replace(storage_path(), '.', $path) : $path;
    }

    /**
     * Get API Schema Path
     */
    public static function api(string $folder = 'schema', bool $relative = false): string
    {
        $path = base_path(config('scaffold.api.' . $folder));

        return $relative ? str_replace(base_path(), '.', $path) : $path;
    }

    /**
     * Get ACL Schema Path
     *
     * `scaffold/acl/` 是硬编码，不读配置（外迁前的既有形态）。
     */
    public static function acl(bool $relative = false): string
    {
        $path = base_path('scaffold/acl/');

        return $relative ? str_replace(base_path(), '.', $path) : $path;
    }

    /**
     * 把相对路径格式化成 PSR-4 命名空间。
     *
     * 例：`./app/Models`（`model(true)` 的产物）→ `App\Models`。
     * `./` 先被整体去掉，再把剩下的 `/` 换成 `\`，所以开头的 `./` 不会留下空段。
     */
    public static function namespaceOf(string $path): string
    {
        return ucfirst(str_replace(['./', '/'], ['', '\\'], $path));
    }
}
