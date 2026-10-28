<?php

use Illuminate\Support\Facades\Route;
use Modules\Calendar\Http\Controllers\CalendarController;
use Modules\Calendar\Http\Controllers\IcalController;

// ModuleProvider wraps this file in ['module:calendar', 'web']. Rights are checked in the controller/request
// (policy CalendarEventPolicy). Fixed paths (events, personnel, subscription) stay before the numeric `{event}` wildcard.
// The public iCal feed lives in routes/api.php (no session, no cookies).
Route::middleware('auth')->prefix('calendar')->controller(IcalController::class)->group(function () {
    Route::get('/subscription', 'show');
    Route::post('/subscription', 'store')->middleware('throttle:60,1,calendar-write');
    Route::delete('/subscription', 'destroy')->middleware('throttle:60,1,calendar-write');
});

Route::middleware('auth')->prefix('calendar')->whereNumber('event')->controller(CalendarController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/create', 'index');
    Route::get('/events', 'events')->middleware('throttle:120,1,calendar-feed');
    Route::get('/personnel', 'personnel')->middleware('throttle:120,1,calendar-feed');
    Route::post('/', 'store')->middleware('throttle:60,1,calendar-write');
    Route::get('/{event}', 'show');
    Route::put('/{event}', 'update')->middleware('throttle:60,1,calendar-write');
    Route::delete('/{event}', 'destroy')->middleware('throttle:60,1,calendar-write');
    Route::post('/{event}/response', 'respond')->middleware('throttle:60,1,calendar-write');
});
