<?php
namespace Charsen\Scaffold\Generator;

use Charsen\Scaffold\Utility;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generator
 *
 * @author   Charsen <780537@gmail.com>
 */
class Generator
{
    protected $filesystem;
    protected $command;
    protected $utility;

    /**
     * Create a new command instance.
     *
     * @param Filesystem $files
     * @param Composer $composer
     * @return void
     */
    public function __construct(Command $command, Filesystem $filesystem, Utility $utility)
    {
        $this->command    = $command;
        $this->filesystem = $filesystem;
        $this->utility    = $utility;
    }

    /**
     * Build file replacing metas in template.
     *
     * @param array $metas
     * @param string &$template
     * @return void
     */
    protected function buildStub(array $metas, &$template)
    {
        foreach ($metas as $k => $v)
        {
            $template = str_replace('{{' . $k . '}}', $v, $template);
        }

        return $template;
    }

    protected function getStubPath()
    {
        return substr(__DIR__, 0, -9) . 'Stub/';
    }

    protected function getFilesRecursive($path)
    {
        $files = [];
        $scan  = array_diff(scandir($path), ['.', '..']);

        foreach ($scan as $file)
        {
            $file = realpath("$path$file");
            if (is_dir($file))
            {
                $files = array_merge($files, $this->getFilesRecursive($file . DIRECTORY_SEPARATOR));
                continue;
            }

            $files[] = $file;
        }

        return $files;
    }

    /**
     * Get the application namespace.
     *
     * @return string
     */
    protected function getAppNamespace()
    {
        return Container::getInstance()->getNamespace();
    }
}
