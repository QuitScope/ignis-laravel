<?php

use Illuminate\Support\Facades\Route;
use Modules\Federation\Http\Controllers\Api\FederatedIncidentController;
use Modules\Federation\Http\Controllers\Api\HandshakeController;
use Modules\Federation\Http\Controllers\Api\PairController;
use Modules\Federation\Http\Controllers\Api\PersonnelController;
use Modules\Federation\Http\Controllers\Api\ProtocolController;
use Modules\Federation\Http\Middleware\VerifyPeerSignature;

// ModuleProvider wraps this file in ['module:federation', 'api']: 404 while the module is off (like legacy).
Route::prefix('/api/federation')->group(function () {
    // Unsigned: the one-time code is the credential.
    Route::post('/pair', PairController::class)->middleware('throttle:10,1,federation-pair');

    // Signature first (failures throttled per IP inside), then 120/min per link.
    Route::middleware([VerifyPeerSignature::class, 'throttle:federation-peer'])->group(function () {
        Route::get('/handshake', HandshakeController::class);
        Route::get('/personnel', PersonnelController::class);
        Route::get('/enotf', ProtocolController::class);
        Route::get('/fire-incidents', FederatedIncidentController::class);
    });
});
