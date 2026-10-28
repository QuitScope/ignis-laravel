<?php

use Illuminate\Support\Facades\Route;
use Modules\FireTab\Http\Controllers\AsuSyncController;
use Modules\FireTab\Http\Controllers\StatusPollController;

// ModuleProvider wraps this file in ['module:firetab', 'api']: stateless, no session, cookies or CSRF.
Route::post('/api/emd/status-poll', StatusPollController::class)->middleware('api.key');

Route::post('/api/asu/sync', AsuSyncController::class)->middleware(['api.key', 'throttle:60,1,asu-sync']);
Route::post('/api/asu-sync', AsuSyncController::class)->middleware(['api.key', 'throttle:60,1,asu-sync']);
