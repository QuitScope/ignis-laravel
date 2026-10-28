<?php

use Illuminate\Support\Facades\Route;
use Modules\ManvBoard\Http\Controllers\BoardController;
use Modules\ManvBoard\Http\Controllers\ManvIncidentController;
use Modules\ManvBoard\Http\Controllers\PatientController;
use Modules\ManvBoard\Http\Controllers\ResourceController;

// ModuleProvider wraps this file in ['module:manvboard', 'web']. Fixed paths stay before the numeric `{incident}`.
Route::middleware('auth')->prefix('mci')->whereNumber('incident')->controller(ManvIncidentController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/create', 'create');
    Route::get('/personnel', 'personnel')->middleware('throttle:120,1,manv-personnel');
    Route::post('/', 'store')->middleware('throttle:60,1,manv-write');
    Route::get('/{incident}/edit', 'edit');
    Route::put('/{incident}', 'update')->middleware('throttle:60,1,manv-write');
    Route::get('/{incident}/log', 'log');
});

// Board and patients. Scoped binding: a patient of another Lage is a 404. No state change on GET.
Route::middleware('auth')->prefix('mci/{incident}')->whereNumber(['incident', 'patient', 'resource'])->scopeBindings()->group(function () {
    Route::get('/', [BoardController::class, 'show']);
    Route::controller(PatientController::class)->prefix('patients')->group(function () {
        Route::get('/create', 'create');
        Route::post('/', 'store')->middleware('throttle:120,1,manv-patient');
        Route::get('/{patient}', 'show');
        Route::put('/{patient}', 'update')->middleware('throttle:120,1,manv-patient');
        Route::patch('/{patient}/triage', 'triageUpdate')->middleware('throttle:120,1,manv-patient');
        Route::post('/{patient}/departure', 'departure')->middleware('throttle:120,1,manv-patient');
    });
    Route::controller(ResourceController::class)->prefix('resources')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store')->middleware('throttle:120,1,manv-resource');
        Route::put('/{resource}', 'update')->middleware('throttle:120,1,manv-resource');
        Route::delete('/{resource}', 'destroy')->middleware('throttle:120,1,manv-resource');
    });
});
