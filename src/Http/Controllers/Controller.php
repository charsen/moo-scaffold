<?php

namespace Mooeen\Scaffold\Http\Controllers;

use Mooeen\Scaffold\Utility;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Controller as BaseController;

/**
 * Class     Controller
 *
 * @package  Mooeen\Scaffold\Http\Controllers
 * @author Charsen https://github.com/charsen
 */
class Controller extends BaseController
{
    protected $utility;
    protected $filesystem;

    /**
     * Controller constructor.
     *
     * @param \Mooeen\Scaffold\Utility         $utility
     * @param \Illuminate\Filesystem\Filesystem $filesystem
     */
    public function __construct(Utility $utility, Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
        $this->utility    = $utility;
    }

    /**
     * Helper to get the config values.
     *
     * @param  string  $key
     *
     * @return mixed
     */
    protected function config($key)
    {
        return $this->utility->getConfig($key);
    }

    /**
     * Get the evaluated view contents for the given view.
     *
     * @param  string  $view
     * @param  array   $data
     * @param  array   $mergeData
     *
     * @return \Illuminate\View\View
     */
    protected function view($view, $data = [], $mergeData = [])
    {
        return view()->make("scaffold::{$view}", $data, $mergeData);
    }
}
