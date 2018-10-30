<?php

namespace Charsen\Scaffold\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Class     ScaffoldController
 *
 * @package  Charsen\Scaffold\Http\Controllers
 * @author   Charsen <780537@gmail.com>
 */
class ScaffoldController extends Controller
{

    /**
     * Show the dashboard.
     *
     * @return \Illuminate\View\View
     */
    public function index(Request $req)
    {

        return $this->view('dashboard', ['route_prefix' => $this->config('route.prefix')]);
    }
}
