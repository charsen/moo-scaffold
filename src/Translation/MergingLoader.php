<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Translation;

use Illuminate\Contracts\Translation\Loader;

/**
 * 装饰 Laravel 内置 translation.loader，让扩展包 lang/{locale}/{group}.php 与 host 同名文件深度合并
 * （host 永远赢），host 无需拷 yaml / 跑 moo:i18n 即有包内翻译。
 *
 * plan 38：moo 系扩展包基础设施三件套之一，上移到 scaffold 单份共享（原各包自持复制，已归一）。
 * 各包 provider 照常 `$app->extend('translation.loader', fn($inner) => new MergingLoader($inner, __DIR__.'/../lang'))`，
 * **每包传自己的 lang 路径**——本类只是搬到 scaffold，注册与路径仍归各包。
 *
 * - 装饰 `translation.loader` 公共契约，零 reflection，Laravel 升版只要 Loader 契约不变就稳；
 * - `array_replace_recursive` 深合并：自动处理 validation.php 的 attributes 嵌套子键；
 * - 只在 namespace=null/'*' 时介入，不影响 host 的 vendor/published 命名空间翻译（`pkg::xxx` 走原逻辑）。
 */
final class MergingLoader implements Loader
{
    public function __construct(
        private Loader $inner,
        private string $packageLangDir,
    ) {}

    public function load($locale, $group, $namespace = null): array
    {
        $hostLines = $this->inner->load($locale, $group, $namespace);

        // 只合并默认（无 namespace）翻译；`pkg::xxx` 等显式 namespaced lookup 走原逻辑
        if ($namespace !== null && $namespace !== '*') {
            return $hostLines;
        }

        $packageFile = $this->packageLangDir . '/' . $locale . '/' . $group . '.php';
        if (! file_exists($packageFile)) {
            return $hostLines;
        }

        $packageLines = require $packageFile;
        if (! is_array($packageLines)) {
            return $hostLines;
        }

        // host 优先：array_replace_recursive 后位参数覆盖前位
        return array_replace_recursive($packageLines, $hostLines);
    }

    public function addNamespace($namespace, $hint): void
    {
        $this->inner->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path): void
    {
        $this->inner->addJsonPath($path);
    }

    public function namespaces(): array
    {
        return $this->inner->namespaces();
    }
}
