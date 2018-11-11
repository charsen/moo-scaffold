<?php

namespace Charsen\Scaffold\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Class     DatabaseController
 *
 * @package  Charsen\Scaffold\Http\Controllers
 * @author Charsen https://github.com/charsen
 */
class DatabaseController extends Controller
{
    /**
     * tables list
     *
     */
    public function index(Request $req)
    {
        $data                       = ['uri' => $req->getPathInfo()];
        $data['menus']              = $this->utility->getTables();
        $data['current_file']       = $req->input('name', null);
        $data['current_table']      = $req->input('table', null);
        $data['first_menu_active']  = $data['current_file'] != null;
        $data['first_table_active'] = $data['current_file'] != null;
    
    
        return $this->view('db.index', $data);
    }

    /**
     * dictionaries
     *
     */
    public function dictionaries(Request $req)
    {
        $data = [
            'menus' => $this->utility->getTables(),
            'uri'   => $req->getPathInfo(),
            'data'  => $this->utility->getDictionaries(false),
        ];
        
        return $this->view('db.dictionaries', $data);
    }
    
    /**
     * table view
     *
     * @param \Illuminate\Http\Request $req
     *
     * @return \Illuminate\View\View
     */
    public function show(Request $req)
    {
        $file_name = $req->input('name', null);
        $data      = ['data' => $this->utility->getOneTable($file_name)];

        return $this->view('db.show', $data);
    }
}
