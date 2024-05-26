<?php

namespace Mooeen\Scaffold\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Class     ScaffoldController
 *
 * @author Charsen https://github.com/charsen
 */
class ScaffoldController extends Controller
{
    /**
     * Show the dashboard.
     *
     *
     * @return \Illuminate\View\View
     */
    public function index(Request $req)
    {
        $data        = [];
        $data['uri'] = $req->getPathInfo();

        return $this->view('dashboard', $data);
    }
}
