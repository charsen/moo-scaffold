<?php

use Charsen\Scaffold\Http\Controllers\ApiController;
use Charsen\Scaffold\Http\Controllers\DatabaseController;
use Charsen\Scaffold\Http\Controllers\ScaffoldController;
use Charsen\Scaffold\Utility;

$prefix = (new Utility)->getConfig('route.prefix');

Route::get($prefix, ScaffoldController::class . '@index');

Route::prefix($prefix)->group(function ()
{
    Route::get('/api', ApiController::class . '@index')->name('api.list');
    Route::get('/api/show', ApiController::class . '@show')->name('api.show');
    Route::get('/api/request', ApiController::class . '@request')->name('api.request');
    Route::get('/api/param', ApiController::class . '@param')->name('api.param');
    Route::post('/api/cache', ApiController::class . '@cache')->name('api.cache');
    Route::get('/api/result', ApiController::class . '@result')->name('api.result');

    Route::get('/db', DatabaseController::class . '@index')->name('table.list');
    Route::get('/dictionaries', DatabaseController::class . '@dictionaries')->name('dictionaries');
    Route::get('/db/table', DatabaseController::class . '@show')->name('table.show');
});
