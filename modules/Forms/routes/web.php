<?php

use Illuminate\Support\Facades\Route;
use Modules\Forms\Http\Controllers\FormAdminController;
use Modules\Forms\Http\Controllers\FormSubmissionController;
use Modules\Forms\Http\Controllers\FormTypeController;

// ModuleProvider wraps this file in ['module:forms', 'web'].
// Settings: everything is system.admin (checked in the controller).
// Fixed paths (create, order) stay before the `{type}` wildcard, which is numeric only.
Route::middleware('auth')->prefix('settings/forms')->whereNumber(['type', 'field'])->controller(FormTypeController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/create', 'create');
    Route::post('/', 'store')->middleware('throttle:60,1,forms-write');
    Route::put('/order', 'order')->middleware('throttle:60,1,forms-write');
    Route::get('/{type}/edit', 'edit');
    Route::put('/{type}', 'update')->middleware('throttle:60,1,forms-write');
    Route::patch('/{type}/active', 'active')->middleware('throttle:60,1,forms-write');
    Route::delete('/{type}', 'destroy')->middleware('throttle:60,1,forms-write');
    Route::put('/{type}/fields/order', 'orderFields')->middleware('throttle:60,1,forms-write');
    Route::post('/{type}/fields', 'storeField')->middleware('throttle:60,1,forms-write');
    Route::delete('/{type}/fields/{field}', 'destroyField')->middleware('throttle:60,1,forms-write');
});

// Submissions: the fixed paths (new, admin) come before the six-digit `{publicId}`, which is no permission (policy per call).
Route::middleware('auth')->whereNumber('type')->where(['publicId' => '[0-9]{6}'])->group(function () {
    Route::controller(FormSubmissionController::class)->group(function () {
        Route::get('/forms', 'select');
        Route::get('/forms/new/{type}', 'create');
        Route::post('/forms/new/{type}', 'store')->middleware('throttle:20,1,forms-submit');
        Route::get('/me/applications', 'mine');
    });
    Route::controller(FormAdminController::class)->prefix('forms/admin')->group(function () {
        Route::get('/', 'index');
        Route::get('/{publicId}', 'show');
        Route::put('/{publicId}', 'update')->middleware('throttle:60,1,forms-write');
    });
    Route::get('/forms/{publicId}', [FormSubmissionController::class, 'show']);
});
