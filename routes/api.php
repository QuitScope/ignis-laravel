<?php

use App\Http\Controllers\Api\CharacterController;
use App\Http\Controllers\Api\EmdSyncController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\TabletLoginController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'index']);
Route::get('/version', [HealthController::class, 'version']);

Route::post('/tablet/login-token', [TabletLoginController::class, 'issue'])->middleware('api.key');

// ef_bridge posts api/emd/sync.php (NormalizeLegacyPath strips `.php`, LegacyUrlMap rewrites /api/emd-sync). The size guard (EmdBodyLimit) is global, before any body decoding.
Route::post('/emd/sync', EmdSyncController::class)->middleware(['api.key', 'throttle:emd-sync']);

// FiveM character ef_bridge redeems the page's one-time token; no session id leaves the server.
Route::post('/character/identify', [CharacterController::class, 'identify'])->middleware(['api.key', 'throttle:character-identify']);
Route::match(['get', 'post'], '/character/get-session-id', fn () => response()->json(['success' => false, 'message' => 'Entfernt'], 410));
