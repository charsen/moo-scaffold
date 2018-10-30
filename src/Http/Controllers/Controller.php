<?php

namespace Charsen\Scaffold\Http\Controllers;

use Charsen\Scaffold\Utility;
use Illuminate\Routing\Controller as BaseController;

/**
 * Class     Controller
 *
 * @package  Charsen\Scaffold\Http\Controllers
 * @author   Charsen <780537@gmail.com>
 */
class Controller extends BaseController
{
    protected $utility;

    public function __construct(Utility $utility)
    {
        $this->utility = $utility;
    }

    /**
     * Helper to get the config values.
     *
     * @param  string  $key
     * @param  string  $default
     *
     * @return mixed
     */
    protected function config($key, $default = null)
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
