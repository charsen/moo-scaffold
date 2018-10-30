<?php

use Charsen\Scaffold\Http\Controllers\DatabaseController;
use Charsen\Scaffold\Http\Controllers\ScaffoldController;
use Charsen\Scaffold\Utility;

$prefix = (new Utility)->getConfig('route.prefix');

Route::prefix($prefix)->group(function ()
{
    Route::get('/dbs', DatabaseController::class . '@index');
    Route::get('/dictionaries', DatabaseController::class . '@dictionaries');
    Route::get('/table', DatabaseController::class . '@table');

    Route::get('/', ScaffoldController::class . '@index');
});
