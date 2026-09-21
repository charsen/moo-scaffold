<?php declare(strict_types=1);
/*
 * @Description: 控制器扫描目标的推导 —— 把命令行的 `--scope` 变成一个
 *               「(绝对扫描根, 控制器命名空间, Request 命名空间)」三元组。
 *
 *               2026-09-20 从 `Command\AuditFormContractCommand` 外迁（第 8 项）。原为它的 3 个
 *               私有方法（`resolveRoot` / `controllerNamespace` / `requestNamespace`），搬迁是
 *               **纯搬家**：方法体逐字节未动，只是从「命令的私有实例方法」变成「独立类的公开静态方法」。
 *
 *               为什么能搬（判据三层）：
 *               ① 族外调用点恰好 3 个，各自单入口（`handle()` 用 resolveRoot、
 *                  `resolveNamespace()` 用 controllerNamespace、`inspectController()` 用 requestNamespace）；
 *               ② 族内内聚 —— 三个方法合起来就是上面那一条职责，彼此只通过返回值串接，没有共享状态；
 *               ③ 零状态 —— 3 个方法都不碰 `$this`，也不碰任何容器 / facade / `config()` / `new`。
 *                  因此新家是 `final` + 全 `static` + 零属性 + 零构造函数（唯一保留的外部依赖是既有的
 *                  两个全局：`Support\Paths::isAbsolute()` 与 `base_path()`）。
 *
 *               刻意**不搬**的「同族」：`resolveNamespace()`（读 `--namespace` + 就地报错）与
 *               `resolveControllerFiles()`（glob + 就地报错）—— 它们要命令行的 `$this->option()` /
 *               `$this->console()`，搬走就得把命令上下文注进来，反而把「纯推导」弄脏。
 *               另注：`inspectController()` 与命名空间解析**不是一个职责**（它是执行驱动、还调族外
 *               `inspectFormPath()`），早期「4 方法一簇」的记录已被 2026-09-20 的复核推翻。
 *
 *               行为钉尸见 `tests/Feature/Support/ControllerScanTargetTest.php`
 *               （同一批断言跨两个宿主，宿主解析点收敛到单个助手）。
 */

namespace Mooeen\Scaffold\Support;

use Composer\Autoload\ClassLoader;

final class ControllerScanTarget
{
    public static function resolveRoot(string $scope): string
    {
        if ($scope === '') {
            $scope = 'app/Admin/Controllers';
        }

        // 绝对判定共用 Support\Paths（盘符路径在这儿也算绝对，与全仓一致）；本处额外做 realpath + 去尾斜杠。
        if (! Paths::isAbsolute($scope)) {
            return rtrim(base_path($scope), '/');
        }

        $real = realpath($scope);

        return rtrim($real === false ? $scope : $real, '/');
    }

    /**
     * 控制器根目录 → 命名空间：优先用宿主 composer 的 PSR-4 前缀反推（app/Admin/Controllers
     * → App\Admin\Controllers），回退到 base_path 相对路径。推导失败返回 null。
     */
    public static function controllerNamespace(string $root): ?string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');

        foreach (spl_autoload_functions() as $fn) {
            if (! is_array($fn) || ! is_object($fn[0]) || ! $fn[0] instanceof ClassLoader) {
                continue;
            }

            $best = null;
            foreach ($fn[0]->getPrefixesPsr4() as $prefix => $dirs) {
                foreach ($dirs as $dir) {
                    $dir = (string) $dir;
                    // composer 前缀里的目录常带 `vendor/composer/../../`，先归一化再比边界
                    $dir = realpath($dir) ?: $dir;
                    $dir = rtrim(str_replace('\\', '/', $dir), '/');
                    if ($dir === '' || $root !== $dir && ! str_starts_with($root, $dir . '/')) {
                        continue;
                    }

                    if ($best === null || strlen($dir) > strlen($best[0])) {
                        $best = [$dir, rtrim($prefix, '\\')];
                    }
                }
            }

            if ($best !== null) {
                $relative  = trim(substr($root, strlen($best[0])), '/');
                $namespace = $best[1];
                if ($relative !== '') {
                    $namespace .= '\\' . str_replace('/', '\\', $relative);
                }

                return $namespace;
            }
        }

        // 回退：base_path 下的相对路径（app/ → App\）
        $base = rtrim(str_replace('\\', '/', base_path()), '/');
        if (str_starts_with($root, $base . '/')) {
            $relative  = substr($root, strlen($base) + 1);
            $namespace = '';
            if (str_starts_with($relative, 'app/')) {
                $namespace = 'App\\';
                $relative  = substr($relative, 4);
            }

            return $namespace . str_replace('/', '\\', $relative);
        }

        return null;
    }

    /**
     * 控制器命名空间 → 对应 Request 命名空间（App\Admin\Controllers → App\Admin\Requests）。
     */
    public static function requestNamespace(string $namespace): string
    {
        return str_contains($namespace, '\\Controllers')
            ? str_replace('\\Controllers', '\\Requests', $namespace)
            : $namespace . '\\Requests';
    }
}
