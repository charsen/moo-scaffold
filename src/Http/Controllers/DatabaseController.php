<?php

namespace Charsen\Scaffold\Http\Controllers;

use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Class     DatabaseController
 *
 * @package  Charsen\Scaffold\Http\Controllers
 * @author   Charsen <780537@gmail.com>
 */
class DatabaseController extends Controller
{
    /**
     * @var array
     */
    private $table_style = ['red', 'orange', 'yellow', 'blue', 'olive', 'teal'];

    /**
     * tables list
     *
     */
    public function index()
    {
        $data                       = [];
        $data['menus']              = $this->utility->getTables();
        $data['table_style']        = $this->table_style[array_rand($this->table_style)];
        $data['first_menu_active']  = false;
        $data['first_table_active'] = false;

        return $this->view('db.index', $data);
    }

    /**
     * dictionaries
     *
     */
    public function dictionaries()
    {
        $data = ['data' => $this->utility->getDictionaries(false)];

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
