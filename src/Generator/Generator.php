<?php
namespace Charsen\Scaffold\Generator;

use Charsen\Scaffold\Utility;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

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
    protected $filesystem;
    /**
     * @var mixed
     */
    protected $command;
    /**
     * @var mixed
     */
    protected $utility;

    /**
     * Create a new command instance.
     *
     * @param \Illuminate\Console\Command       $command
     * @param \Illuminate\Filesystem\Filesystem $filesystem
     * @param \Charsen\Scaffold\Utility         $utility
     */
    public function __construct(Command $command, Filesystem $filesystem, Utility $utility)
    {
        header('Content-Type: charset=utf-8');
        $this->command    = $command;
        $this->filesystem = $filesystem;
        $this->utility    = $utility;
    }

    /**
     * 获取 tabs 缩进
     *
     * @param  integer $size
     * @return string
     */
    protected function getTabs($size = 1)
    {
        return str_repeat(' ', $size * 4);
    }

    /**
     * Build file replacing metas in template.
     *
     * @param array $metas
     * @param string $template
     *
     * @return string
     */
    protected function buildStub(array $metas, $template)
    {
        foreach ($metas as $k => $v)
        {
            $template = str_replace('{{' . $k . '}}', $v, $template);
        }

        return $template;
    }

    /**
     * Get the Stub Path.
     *
     * @return string
     */
    protected function getStubPath()
    {
        return __DIR__ . '/../Stub/';
    }

    /**
     * 获取模板
     *
     * @param $file_name
     *
     * @return string
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    protected function getStub($file_name)
    {
        return $this->filesystem->get($this->getStubPath() . "{$file_name}.stub");
    }

}
