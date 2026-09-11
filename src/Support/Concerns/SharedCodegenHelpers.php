<?php declare(strict_types=1);

namespace Mooeen\Scaffold\Support\Concerns;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;

/**
 * Generator 与 Adder 共用的 codegen 基础设施：目录 / 缩进 / stub 读取 + 三种槽位 escape。
 *
 * 2026-09-11：这两个基类原先各自抄一份（checkDirectory / getTabs / buildStub /
 * getStubPath / getStub 逐字重复），而 escape 三件套只长在 Generator 上 —— 结果
 * Adder 把交互输入拼进 PHP 时没有任何转义工具可用（moo:adder 的 action / 路由 /
 * 控制器名全是人敲的）。收口到本 trait，两边同时具备。
 *
 * 隐式依赖（使用方基类必须提供，Generator 与 Adder 都有）：
 *   - protected Filesystem $filesystem
 *   - console(): ConsoleUi —— 由 InteractsWithConsoleUi 提供
 */
trait SharedCodegenHelpers
{
    /**
     * 检查 文件夹是否存在，不存在则创建
     */
    protected function checkDirectory(string $path): void
    {
        if (! $this->filesystem->isDirectory($path)) {
            $this->filesystem->makeDirectory($path, 0777, true, true);
        }
    }

    /**
     * 获取 tabs 缩进
     */
    protected function getTabs(float $size = 1): string
    {
        // (int) 必须：strict_types 下 str_repeat 第二参收 float 抛 TypeError
        return str_repeat(' ', (int) ($size * 4));
    }

    /**
     * Build file replacing metas in template.
     *
     * 注意：这里**不在**模板层做 escape —— 由 generator 调用方决定每个槽位用什么 escape：
     *   - PHP 字符串字面量（`'{name}'`）槽位：调用方先调 escapePhpString()
     *   - YAML 字符串（`name: '{name}'`）槽位：调用方先调 quoteYamlString()
     *   - HTML 槽位：走 e() / Blade {{ }}
     * 因为 stub 一个槽位多种 context，模板层强 escape 会 over-escape 或漏 escape。
     */
    protected function buildStub(array $metas, string $template): string
    {
        foreach ($metas as $k => $v) {
            $template = str_replace('{{' . $k . '}}', $v, $template);
        }

        return $template;
    }

    /**
     * plan-40 §二：把任意 yaml 数据 escape 成可塞 PHP 单引号字符串字面量的形式。
     *
     * 用途：`->comment('{name}')` / `->default('{default}')` / `'{key}' => '...'` 等
     * 模板槽位，调用前先跑这个 escape，杜绝 `xxx'); system('id'); //` 这类 PHP 注入。
     *
     * 跟 PHP `addcslashes($s, "'\\")` 等价，加 readability 注释。
     */
    protected function escapePhpString(mixed $v): string
    {
        return addcslashes((string) $v, "'\\");
    }

    /**
     * plan-40 §二 C-13：YAML 单引号字符串字面量 escape。
     *
     * YAML 单引号字符串里 `'` 转义为 `''`，其它字符不动（YAML spec 1.2 §7.4.1）。
     */
    protected function quoteYamlString(mixed $v): string
    {
        return str_replace("'", "''", (string) $v);
    }

    /**
     * plan-40 §二 F9d：PHP docblock 文本槽位防注入。
     *
     * `*\/` 会提前闭合 docblock，后续 yaml 内容跑到 PHP 代码空间。
     *
     * 任意 yaml 文本进 ` * @property X $y {comment}` 这类槽位前调一次。
     */
    protected function sanitizeDocblock(mixed $v): string
    {
        return str_replace(['*/', '/*'], ['* /', '/ *'], (string) $v);
    }

    /**
     * Get the Stub Path.
     *
     * ⚠️ 本方法从 Generator / Adder 搬进 trait 时路径必须重算：`__DIR__` 在 trait 里
     * 指向 **trait 文件**所在目录（src/Support/Concerns），不是在哪个类里被 use。
     * 原先两边各自写 `__DIR__ . '/../../stubs/'` 恰好都对（都在包根下两层），
     * 这里要回三层才到包根。
     */
    protected function getStubPath(): string
    {
        return dirname(__DIR__, 3) . '/stubs/';
    }

    /**
     * 获取模板
     *
     * @throws FileNotFoundException
     */
    protected function getStub(string $file_name): string
    {
        return $this->filesystem->get($this->getStubPath() . "{$file_name}.stub");
    }
}
