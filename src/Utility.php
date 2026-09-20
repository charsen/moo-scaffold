<?php

declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2025-07-29 14:17
 * @Description: Utility
 */

namespace Mooeen\Scaffold;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Mooeen\Scaffold\Support\AppTargetRegistry;
use Mooeen\Scaffold\Support\ConsoleUi;
use Mooeen\Scaffold\Support\ControllerName;
use Mooeen\Scaffold\Support\PackageRegistry;
use Mooeen\Scaffold\Support\Paths;
use Mooeen\Scaffold\Support\TargetContext;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * 生成器的**门面**：配置与身份、schema/API YAML 解析、多目标上下文、控制器目录扫描、连字符。
 *
 * 2026-09-19 起原来的 5 组职责已按「组间文件重叠」逐批外迁，留在这里的是**还没轮到的**：
 *   - PATHS  → {@see Paths}（阶段 3b-2，纯路径口径）
 *   - DOCMETA → `Support\ActionMeta` / `Support\ActionDoc`（阶段 3b-1，docblock 与动作元信息）
 *   - NAME   → {@see ControllerName}（阶段 3a，两个静态转发留在本类给未迁移的宿主）
 *   - REGISTRY → {@see StorageRegistry}（阶段 3b-3，`storage/scaffold/*.php` 聚合缓存的读取）
 * 本类**再无私有方法**（3b-2 之后即如此）。
 */
class Utility
{
    protected Filesystem $filesystem;

    public function __construct()
    {
        $this->filesystem = new Filesystem;
    }

    /**
     * Helper to get the config values.
     */
    public function getConfig(string $key, mixed $default = null): mixed
    {
        return config("scaffold.$key", $default);
    }

    /**
     * 获取当前执行命令的登录用户
     */
    public function resolveCurrentLoginUser(): string
    {
        $candidates = [
            getenv('SUDO_USER') ?: null,
            getenv('LOGNAME') ?: null,
            getenv('USER') ?: null,
            getenv('USERNAME') ?: null,
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $user     = posix_getpwuid(posix_geteuid());
            $userName = trim((string) ($user['name'] ?? ''));
            if ($userName !== '') {
                return $userName;
            }
        }

        $author = trim((string) $this->getConfig('author', ''));

        return $author !== '' ? $author : 'unknown';
    }

    /**
     * 安全读取 YAML：调试/文档页面遇到损坏文件时跳过，不影响其他模块展示。
     */
    public function parseYamlFile(string $path): array
    {
        if (! $this->filesystem->isFile($path)) {
            return [];
        }

        try {
            $data = Yaml::parseFile($path) ?: [];
        } catch (Throwable) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Get apps list
     */
    public function getApps(): array
    {
        return app(AppTargetRegistry::class)->labels();
    }

    /**
     * 获取按 sort 排序、并补齐旧配置默认值的应用端配置。
     *
     * @return array<string,array<string,mixed>>
     */
    public function getAppTargets(): array
    {
        return app(AppTargetRegistry::class)->all();
    }

    /**
     * 检查 API YAML 文件是否存在
     */
    public function isApiFileExist(string $folderPath, string $fileName, string $folder = 'schema'): string
    {
        $folderPath = trim($folderPath, '/');
        $file       = Paths::api($folder) . (empty($folderPath) ? '' : $folderPath . '/') . $fileName . '.yaml';

        if (! $this->filesystem->isFile($file)) {
            throw new InvalidArgumentException("Invalid File Argument (Not Found): {$file}");
        }

        return $file;
    }

    /**
     * 解析多目标上下文(plan-53:出身模型)。
     *
     * $target 为 null / 'host' → host 隐含默认:沿用现有 host 路径与命名空间(字节不变)。
     * 否则从 PackageRegistry(自动发现,无 config 注册表)取包根 + psr-4 命名空间,
     * 各类路径按全 repo 统一目录约定固化(moo-system / moo-radar 实证形态)。
     */
    public function targetContext(?string $target = null): TargetContext
    {
        if ($target === null || $target === 'host') {
            return new TargetContext(
                target: null,
                basePath: rtrim(base_path(), '/') . '/',
                paths: [
                    'model'     => Paths::model(),
                    'resource'  => Paths::resource(),
                    'migration' => Paths::migration(),
                    'storage'   => Paths::storage(),
                    'database'  => Paths::database('schema'),
                    'api'       => Paths::api('schema'),
                    'acl'       => Paths::acl(),
                    'docs'      => base_path((string) $this->getConfig('docs.path', 'scaffold/docs')) . '/',
                ],
                namespaces: [
                    'model' => Paths::namespaceOf(Paths::model(true)),
                ],
                app: null,
                classes: [],
            );
        }

        $pkg = app(PackageRegistry::class)->get($target);
        if ($pkg === null) {
            throw new InvalidArgumentException("未发现的扩展包：[{$target}]（包根须带 scaffold/database/ 目录才会被自动发现）");
        }

        $base = $pkg['base_path'];
        $ns   = $pkg['namespace'];

        return new TargetContext(
            target: $target,
            basePath: $base,
            // 目录约定全 repo 统一(moo-system / moo-radar 实证):包不合约定改包,不改工具
            paths: [
                'model'      => $base . 'src/Models/',
                'resource'   => $base . 'src/Http/Resources/',
                'request'    => $base . 'src/Http/Requests/',
                'controller' => $base . 'src/Http/Controllers/Admin/',
                'migration'  => $base . 'database/migrations/',
                'database'   => $base . 'scaffold/database/',
                'docs'       => $base . 'docs/',
                'lang'       => $base . 'lang/',
                'route'      => $base . 'routes/admin.php',
                // 缓存是 host 侧聚合物(条目挂 origin 键区分出身),不按包分桶
                'storage' => Paths::storage(),
            ],
            namespaces: [
                'model'      => $ns . '\\Models',
                'resource'   => $ns . '\\Http\\Resources',
                'request'    => $ns . '\\Http\\Requests',
                'controller' => $ns . '\\Http\\Controllers\\Admin',
            ],
            // 包的控制器挂 host 的 admin 组(extra_modules 范式),app 固定 admin
            app: 'admin',
            classes: [],
            writable: (bool) $pkg['writable'],
        );
    }

    /**
     * 添加 git ignore 文件
     *
     * 收 `ConsoleUi` 而非原来的无类型 `$command`（2026-09-19，NOTES 登记的遗留项）——输出出口
     * 由调用方决定，本方法不再内联 `new ConsoleUi(...)`。
     */
    public function addGitIgnore(ConsoleUi $console): void
    {
        $file = storage_path('scaffold/') . '.gitignore';
        if (! $this->filesystem->isFile($file)) {
            $relative_file = '.' . str_replace(base_path(), '', $file);

            if ($this->filesystem->put($file, '*' . PHP_EOL . '!.gitignore') === false) {
                $console->failed($relative_file, '写入失败，文件未变更');

                return;
            }

            $console->created($relative_file);
        }
    }

    /**
     * 获取控制器的命令空间列表
     * !!! 只支持往下两级，更深的层级暂不支持!!!
     */
    public function getControllerNamespaces(string $app = 'admin'): array
    {
        $target    = app(AppTargetRegistry::class)->get($app);
        $base_path = base_path((string) ($target['path'] ?? ''));
        $dirs      = [];
        if ($this->filesystem->isDirectory($base_path)) {
            $dirs = $this->filesystem->directories($base_path);
            array_unshift($dirs, $base_path);
        }

        foreach ($dirs as $path) {
            if ($path === $base_path) {
                continue;
            }
            $more = $this->filesystem->directories($path);
            $dirs = [...$dirs, ...$more];
        }

        foreach ($dirs as $k => &$dir) {
            if (str_contains($dir, 'Traits')) {
                unset($dirs[$k]);

                continue;
            }

            if ($dir === $base_path) {
                $dir = '<ROOT_PATH>'; // 控制器的根目录
            }

            $dir = str_replace($base_path, '', $dir);
        }

        $result = array_values($dirs);

        // 追加包提供的额外模块（无对应目录，由 controller.{app}.extra_modules 声明），
        // 使 moo:api / moo:auth 能遍历到（如搬到 charsen/moo-system 包的 System 模块）。
        foreach (array_keys($this->getExtraModules($app)) as $extra) {
            if (! in_array($extra, $result, true)) {
                $result[] = $extra;
            }
        }

        return $result;
    }

    /**
     * 包提供的额外 admin 模块：模块名 => 控制器命名空间。
     * 例：['System' => 'Mooeen\System\Http\Controllers\Admin']。
     *
     * 这些控制器不在 host 的 controller.{app}.path 下、生产环境又位于 vendor/，
     * 需显式声明才能进入 ACL 生成（moo:auth）/ API 文档（moo:api）/ 路由调试 / 接口调试。
     * 默认空数组 → 未配置的 host 行为不变（向后兼容）。
     */
    public function getExtraModules(string $app = 'admin'): array
    {
        $map = (array) $this->getConfig("controller.{$app}.extra_modules", []);
        $out = [];
        foreach ($map as $module => $namespace) {
            $out[ucfirst((string) $module)] = trim((string) $namespace, '\\');
        }

        return $out;
    }

    /**
     * 获取多语言字段数据
     *
     * ⚠ **没跟着 REGISTRY 组外迁**（2026-09-19，阶段 3b-3）：它读的是
     * `scaffold/database/schema/_fields.yaml`（schema YAML，走本类 `parseYamlFile()`），
     * 不是 `storage/scaffold/` 的聚合缓存 —— 见 {@see \Mooeen\Scaffold\Support\StorageRegistry} 的类注释。
     */
    public function getLangFields(): array
    {
        $file         = Paths::database('schema') . '_fields.yaml';
        $yaml_data    = $this->parseYamlFile($file);
        $tableFields  = is_array($yaml_data['table_fields'] ?? null) ? $yaml_data['table_fields'] : [];
        $appendFields = is_array($yaml_data['append_fields'] ?? null) ? $yaml_data['append_fields'] : [];

        $fields = array_merge($tableFields, $appendFields);

        return empty($fields) ? [] : $fields;
    }

    /**
     * @deprecated 2026-09-19 起实现已外迁到 {@see ControllerName::strip()}，此处仅为尚未迁移的宿主转发。
     *             新代码请直调 `ControllerName::strip()`。
     */
    public static function stripControllerSuffix(string $class): string
    {
        return ControllerName::strip($class);
    }

    /**
     * @deprecated 2026-09-19 起实现已外迁到 {@see ControllerName::ensure()}，此处仅为尚未迁移的宿主转发。
     *             新代码请直调 `ControllerName::ensure()`。
     */
    public static function ensureControllerSuffix(string $class): string
    {
        return ControllerName::ensure($class);
    }
}
