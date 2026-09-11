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
use Mooeen\Scaffold\Support\Concerns\SharedCodegenHelpers;
use Mooeen\Scaffold\Utility;
use Symfony\Component\Console\Output\OutputInterface;

class Generator
{
    use InteractsWithConsoleUi;
    use ResolvesOriginContext;
    use SharedCodegenHelpers;

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
    // 缩进 / 目录 / stub 读取 / escape 三件套见 SharedCodegenHelpers trait(同上共用)

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
     * 获取 前端模板
     *
     * @throws FileNotFoundException
     */
    protected function getFrontendStub(string $file_name): string
    {
        return $this->filesystem->get($this->getStubPath() . "frontend/{$file_name}.stub");
    }
}
