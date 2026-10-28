<?php

use Illuminate\Support\Facades\Route;
use Modules\Logbook\Http\Controllers\LogbookController;

Route::middleware('auth')->prefix('logbook')->whereNumber('entry')->controller(LogbookController::class)->group(function () {
    Route::get('/', 'index');
    Route::post('/', 'store')->middleware('throttle:60,1,logbook-write');
    Route::put('/{entry}', 'update')->middleware('throttle:60,1,logbook-write');
    Route::delete('/{entry}', 'destroy')->middleware('throttle:60,1,logbook-write');
});
