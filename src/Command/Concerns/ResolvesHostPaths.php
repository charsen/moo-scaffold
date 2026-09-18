<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Command\Concerns;

use JsonException;

/**
 * 宿主路径解析：把 `--root`（宿主仓根目录，默认 base_path() 的上一级）归一成
 * 「Laravel app 根目录」—— 三份 composer manifest、`public/`、`vendor/` 都在它下面。
 *
 * 实测遇到两种 host 形态，因此不写死 `engine/`：
 *   - 仓根/engine/ 才是 Laravel app（H1、moo-engine-skeleton）
 *   - 仓根本身就是 Laravel app
 * 按 composer.json / artisan 探测候选，取第一个命中。
 *
 * 只读工具：本 trait 只做路径与 JSON 读取，不做任何写操作。
 */
trait ResolvesHostPaths
{
    /**
     * 宿主仓根目录：显式 --root 优先，否则 base_path() 的上一级
     * （宿主里 artisan 跑在 engine/ 下，其上一级即仓根）。
     */
    protected function resolveHostRoot(): string
    {
        $root = $this->option('root');

        if (is_string($root) && trim($root) !== '') {
            return rtrim(trim($root), '/');
        }

        return rtrim(dirname(base_path()), '/');
    }

    /**
     * 在仓根下定位 Laravel app 根目录（manifest / public / vendor 所在层）。
     */
    protected function resolveAppRoot(string $root): string
    {
        foreach ([$root . '/engine', $root] as $candidate) {
            if (is_file($candidate . '/composer.json') || is_file($candidate . '/artisan')) {
                return $candidate;
            }
        }

        return $root;
    }

    /**
     * 读并解析一份 JSON manifest。
     *
     * @return array<string, mixed>|null null = 文件缺失或 JSON 非法
     */
    protected function readJsonManifest(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }
}
