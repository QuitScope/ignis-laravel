<?php

use Illuminate\Support\Facades\Route;
use Modules\Federation\Http\Controllers\SettingsController;
use Modules\Federation\Http\Controllers\ViewController;

// ModuleProvider wraps this file in ['module:federation', 'web']: 404 while the module is off.
Route::middleware('auth')->group(function () {
    // system.admin in the controller; every write audited, errors only as translated codes.
    Route::prefix('settings/federation')->controller(SettingsController::class)->whereNumber('link')->group(function () {
        Route::get('/', 'index');
        Route::middleware('throttle:30,1,settings-write')->group(function () {
            Route::post('/invite', 'invite'); // JSON {token}, shown once, never stored in clear or logged
            Route::post('/pair', 'pair');
            Route::put('/links/{link}', 'update');
            Route::delete('/links/{link}', 'destroy');
            Route::post('/links/{link}/sync', 'sync');
        });
    });

    // Read-only peer data, rights of the module's QM list.
    Route::get('/federation/protocols', [ViewController::class, 'protocols']);
    Route::get('/federation/incidents', [ViewController::class, 'incidents']);
});
