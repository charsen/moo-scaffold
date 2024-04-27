<?php

use Illuminate\Support\Facades\Route;
use Mooeen\Scaffold\Http\Controllers\DatabaseController;
use Mooeen\Scaffold\Http\Controllers\ScaffoldController;
use Mooeen\Scaffold\Utility;

$prefix = (new Utility)->getConfig('route.prefix');

Route::get($prefix, ScaffoldController::class . '@index');

Route::prefix($prefix)->group(function () {
    Route::get('/db', DatabaseController::class . '@index')->name('table.list');
    Route::get('/dictionaries', DatabaseController::class . '@dictionaries')->name('dictionaries');
    Route::get('/db/table', DatabaseController::class . '@show')->name('table.show');
});
