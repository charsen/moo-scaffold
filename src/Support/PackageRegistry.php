<?php

declare(strict_types=1);

namespace Mooeen\Scaffold\Support;

use Composer\InstalledVersions;
use InvalidArgumentException;

/**
 * 扩展包自动发现(plan-53 Phase 1)。**约定即配置,无 host 注册表**:
 *
 * - 凡 composer 已装包带 `scaffold/database/` 目录即视为 scaffold 管理的扩展包;
 * - 命名空间根取包 composer.json 的 psr-4 首键(全 repo 约定单根 → src/);
 * - key 取包名短段(`charsen/moo-system` → `moo-system`),重名直接抛错(不做消歧兜底);
 * - **写权硬线**:realpath 逃出 vendor(= path 仓软链,写 vendor 即写真仓)才可写;
 *   vcs 拷贝一律只读。这是环境边界(尺子 #2)在多包场景的延伸,store 层守护的依据。
 *
 * 包不合约定(有 marker 但缺 name / psr-4)直接抛错 —— 改包,不改工具(尺子 #5)。
 */
final class PackageRegistry
{
    /** @var array<string,array{key:string,name:string,base_path:string,namespace:string,writable:bool}>|null */
    private ?array $packages = null;

    /**
     * @param list<string>|null $roots 显式包根注入(测试用);null = 从 composer runtime 发现
     */
    public function __construct(private readonly ?array $roots = null) {}

    /**
     * 全部扩展包,按 key 升序。
     *
     * @return array<string,array{key:string,name:string,base_path:string,namespace:string,writable:bool}>
     */
    public function all(): array
    {
        return $this->packages ??= $this->discover();
    }

    /**
     * 取单个包;未发现返回 null(调用方自行决定抛错文案)。
     *
     * @return array{key:string,name:string,base_path:string,namespace:string,writable:bool}|null
     */
    public function get(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    /** @return array<string,array{key:string,name:string,base_path:string,namespace:string,writable:bool}> */
    private function discover(): array
    {
        $out = [];

        foreach ($this->candidateRoots() as $root) {
            $root = rtrim($root, '/');
            if (! is_dir($root . '/scaffold/database')) {
                continue;   // 无 marker → 不是 scaffold 管理的包
            }

            $meta = is_file($root . '/composer.json')
                ? json_decode((string) file_get_contents($root . '/composer.json'), true)
                : null;
            $name = is_array($meta) ? (string) ($meta['name'] ?? '') : '';
            $psr4 = is_array($meta) ? (array) ($meta['autoload']['psr-4'] ?? []) : [];
            if ($name === '' || $psr4 === []) {
                throw new InvalidArgumentException(
                    "扩展包 [{$root}] 带 scaffold/database/ 但 composer.json 缺 name / autoload.psr-4 —— 改包补齐约定，不做兜底。"
                );
            }

            $key = str_contains($name, '/') ? substr($name, strrpos($name, '/') + 1) : $name;
            if (isset($out[$key])) {
                throw new InvalidArgumentException("扩展包 key [{$key}] 重名：[{$out[$key]['name']}] 与 [{$name}]。");
            }

            $out[$key] = [
                'key'       => $key,
                'name'      => $name,
                'base_path' => $root . '/',
                'namespace' => rtrim((string) array_key_first($psr4), '\\'),
                'writable'  => $this->isWritable($root),
            ];
        }

        ksort($out);

        return $out;
    }

    /** @return list<string> */
    private function candidateRoots(): array
    {
        if ($this->roots !== null) {
            return $this->roots;
        }
        if (! class_exists(InstalledVersions::class)) {
            return [];
        }

        // 排除 root package(host 应用自己也有 scaffold/database,不是"扩展包")
        $rootName = InstalledVersions::getRootPackage()['name'] ?? null;
        $seen     = [];
        $roots    = [];
        foreach (InstalledVersions::getInstalledPackages() as $pkg) {
            if ($pkg === $rootName) {
                continue;
            }
            $path = InstalledVersions::getInstallPath($pkg);
            if ($path === null || $path === '') {
                continue;
            }
            $path = rtrim($path, '/');
            if (isset($seen[$path])) {
                continue;   // replaced / alias 会重复报同一路径
            }
            $seen[$path] = true;
            $roots[]     = $path;
        }

        return $roots;
    }

    /**
     * 写权硬线:realpath 逃出 vendor = path 仓软链(写 vendor 即写包真仓)→ 可写;
     * 落在 vendor 内 = vcs 拷贝(composer update 即蒸发)→ 只读。
     */
    private function isWritable(string $root): bool
    {
        $real = realpath($root);
        if ($real === false) {
            return false;
        }
        $vendor = realpath(base_path('vendor'));
        if ($vendor === false) {
            return true;    // 无 vendor 目录(测试沙箱注入的工作副本)→ 可写
        }

        return ! str_starts_with($real . DIRECTORY_SEPARATOR, $vendor . DIRECTORY_SEPARATOR);
    }
}
