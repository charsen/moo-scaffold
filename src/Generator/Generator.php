<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2024-07-29 16:22
 * @LastEditors: Charsen
 * @LastEditTime: 2025-07-18 10:02
 * @Description: Generator
 */

namespace Mooeen\Scaffold\Generator;

use Illuminate\Console\Command;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Support\Concerns\InteractsWithConsoleUi;
use Mooeen\Scaffold\Support\Concerns\ResolvesOriginContext;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Output\OutputInterface;

class Generator
{
    use InteractsWithConsoleUi;
    use ResolvesOriginContext;

    protected Filesystem $filesystem;

    protected Command|Factory|OutputInterface $command;

    protected Utility $utility;

    /**
     * Create a new command instance.
     *
     * $command 接受三种:
     *   - Console Command(scaffold artisan command 路径)
     *   - View Components Factory(legacy)
     *   - OutputInterface(controller / job 等 web context — 用 NullOutput 静音)
     */
    public function __construct(Command|Factory|OutputInterface $command, Filesystem $filesystem, Utility $utility)
    {
        $this->command    = $command;
        $this->filesystem = $filesystem;
        $this->utility    = $utility;
    }

    protected function getConsoleTarget(): Command|Factory|OutputInterface
    {
        return $this->command;
    }

    // plan-53 出身(origin)工具见 ResolvesOriginContext trait(Generator / Adder 共用)

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
        return str_repeat(' ', (int) ($size * 4));
    }

    /**
     * Build file replacing metas in template.
     *
     * 注意:这里**不在**模板层做 escape — 由 generator 调用方决定每个槽位用什么 escape:
     *   - PHP 字符串字面量(`'{name}'`)槽位:调用方先调 escapePhpString()
     *   - YAML 字符串(`name: '{name}'`)槽位:调用方先调 quoteYamlString()
     *   - HTML 槽位:走 e() / Blade {{ }}
     * 因为 stub 一个槽位多种 context,模板层强 escape 会 over-escape 或漏 escape。
     */
    protected function buildStub(array $metas, string $template): string
    {
        foreach ($metas as $k => $v) {
            $template = str_replace('{{' . $k . '}}', $v, $template);
        }

        return $template;
    }

    /**
     * plan-40 §二:把任意 yaml 数据 escape 成可塞 PHP 单引号字符串字面量的形式。
     *
     * 用途:`->comment('{name}')` / `->default('{default}')` / `'{key}' => '...'` 等
     * 模板槽位,调用前先跑这个 escape,杜绝 `xxx'); system('id'); //` 这类 PHP 注入。
     *
     * 跟 PHP `addcslashes($s, "'\\")` 等价,加 readability 注释。
     */
    protected function escapePhpString(mixed $v): string
    {
        return addcslashes((string) $v, "'\\");
    }

    /**
     * plan-40 §二 C-13:YAML 单引号字符串字面量 escape。
     *
     * YAML 单引号字符串里 `'` 转义为 `''`,其它字符不动(YAML spec 1.2 §7.4.1)。
     */
    protected function quoteYamlString(mixed $v): string
    {
        return str_replace("'", "''", (string) $v);
    }

    /**
     * plan-40 §二 F9d:PHP docblock 文本槽位防注入。
     *
     * `*\/` 会提前闭合 docblock,后续 yaml 内容跑到 PHP 代码空间。
     *
     * 任意 yaml 文本进 ` * @property X $y {comment}` 这类槽位前调一次。
     */
    protected function sanitizeDocblock(mixed $v): string
    {
        return str_replace(['*/', '/*'], ['* /', '/ *'], (string) $v);
    }

    /**
     * 写入文件并输出状态报告
     */
    protected function putAndReport(string $file, string $relativeFile, string $content, string $existVerb = 'overwritten'): void
    {
        $fileExists = $this->filesystem->isFile($file);
        if ($this->filesystem->put($file, $content) === false) {
            throw new \RuntimeException("文件写入失败：{$file}");
        }

        if ($fileExists) {
            match ($existVerb) {
                'updated' => $this->console()->updated($relativeFile),
                default   => $this->console()->overwritten($relativeFile),
            };
        } else {
            $this->console()->created($relativeFile);
        }
    }

    /**
     * Get the Stub Path.
     */
    protected function getStubPath(): string
    {
        return __DIR__ . '/../../stubs/';
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

    /**
     * 获取 前端模板
     *
     * @throws FileNotFoundException
     */
    protected function getFrontendStub(string $file_name): string
    {
        return $this->filesystem->get($this->getStubPath() . "frontend/{$file_name}.stub");
    }
}
