<?php
namespace Charsen\Scaffold\Command;

use Charsen\Scaffold\Utility;
use Illuminate\Console\Command as BaseCommand;
use Illuminate\Filesystem\Filesystem;

/**
 * Command
 *
 * @author   Charsen <780537@gmail.com>
 */
class Command extends BaseCommand
{
    protected $filesystem;

    protected $utility;
    
    /**
     * Create a new command instance.
     *
     * @param \Illuminate\Filesystem\Filesystem $filesystem
     * @param \Charsen\Scaffold\Utility         $utility
     */
    public function __construct(Filesystem $filesystem, Utility $utility)
    {
        parent::__construct();

        $this->filesystem = $filesystem;
        $this->utility    = $utility;
    }

    /**
     * 检查 目录是否存在
     *
     * @return mixed
     */
    public function checkScaffoldFolder()
    {
        $check_scaffold_folder = base_path() . '/scaffold';
        if ( ! $this->filesystem->isDirectory($check_scaffold_folder))
        {
            return $this->call('artisan scaffold:folders');
        }

        return true;
    }
}
