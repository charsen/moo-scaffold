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
     * tables list
     *
     */
    public function index()
    {
        $data = [];
        $file = $this->utility->getDatabasePath('storage') . 'tables.php';

        $data['menus']              = require_once $file;
        $data['table_class']        = ['red', 'orange', 'yellow', 'blue', 'olive', 'teal'];
        $data['table_index']        = 1;
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
        $data         = [];
        $file         = $this->utility->getDatabasePath('storage') . 'dictionaries.php';
        $data['data'] = require_once $file;

        return $this->view('db.dictionaries', $data);
    }

    /**
     * table view
     *
     */
    public function table(Request $req)
    {
        $data      = [];
        $file_name = $req->input('name', null);
        $file      = $this->utility->getDatabasePath('storage') . $file_name . '.php';

        if (!is_file($file))
        {
            throw new InvalidArgumentException('Invalid Argument (Not Found).');
        }

        $data['data'] = require_once $file;

        return $this->view('db.table', $data);
    }
}
