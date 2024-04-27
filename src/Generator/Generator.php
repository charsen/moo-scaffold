<?php

namespace Mooeen\Scaffold\Generator;

use Illuminate\Console\Command;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Filesystem\Filesystem;
use Mooeen\Scaffold\Utility;

/**
 * Generator
 *
 * @author Charsen https://github.com/charsen
 */
class Generator
{
    /**
     * @var mixed
     */
    protected Filesystem $filesystem;

    /**
     * @var mixed
     */
    protected Command|Factory $command;

    /**
     * @var mixed
     */
    protected Utility $utility;

    /**
     * Create a new command instance.
     */
    public function __construct(Command|Factory $command, Filesystem $filesystem, Utility $utility)
    {
        header('Content-Type: charset=utf-8');
        $this->command    = $command;
        $this->filesystem = $filesystem;
        $this->utility    = $utility;
    }

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
    protected function getTabs(int $size = 1): string
    {
        return str_repeat(' ', $size * 4);
    }

    /**
     * Build file replacing metas in template.
     */
    protected function buildStub(array $metas, string $template): string
    {
        foreach ($metas as $k => $v) {
            $template = str_replace('{{' . $k . '}}', $v, $template);
        }

        return $template;
    }

    /**
     * Get the Stub Path.
     */
    protected function getStubPath(): string
    {
        return __DIR__ . '/../Stub/';
    }

    /**
     * 获取模板
     *
     *
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    protected function getStub($file_name): string
    {
        return $this->filesystem->get($this->getStubPath() . "{$file_name}.stub");
    }
}
