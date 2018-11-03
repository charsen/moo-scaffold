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
     * @param \Illuminate\Http\Request $req
     *
     * @return \Illuminate\View\View
     */
    public function index(Request $req)
    {
        return $this->view('dashboard');
    }
}
