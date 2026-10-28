<?php

use Illuminate\Support\Facades\Route;
use Modules\FireTab\Http\Controllers\AsuController;
use Modules\FireTab\Http\Controllers\FinalizeController;
use Modules\FireTab\Http\Controllers\FireIncidentController;
use Modules\FireTab\Http\Controllers\FireLogbookController;
use Modules\FireTab\Http\Controllers\FireQmController;
use Modules\FireTab\Http\Controllers\FireStatusController;
use Modules\FireTab\Http\Controllers\IncidentVehicleController;
use Modules\FireTab\Http\Controllers\LoginController;
use Modules\FireTab\Http\Controllers\MapController;
use Modules\FireTab\Http\Controllers\SitrepController;
use Modules\FireTab\Http\Middleware\FireTabNoStore;

// ModuleProvider wraps this file in ['module:firetab', 'web']. `fivem`: the pages run in FiveM's CEF webview (docs/dev/cef.md).
Route::middleware(['auth', 'fivem', FireTabNoStore::class])->group(function () {
    Route::prefix('firetab')->group(function () {
        Route::get('/', [LoginController::class, 'home']);
        Route::get('/login', [LoginController::class, 'show']);
        Route::post('/login', [LoginController::class, 'store']);
        Route::post('/logout', [LoginController::class, 'destroy']);

        // QM: no crew session, the policy decides (viewQm: QM right, admin, super-admin).
        Route::prefix('qm')->middleware('throttle:firetab-write')->group(function () {
            Route::get('/', [FireQmController::class, 'index'])->withoutMiddleware('throttle:firetab-write');
            Route::get('/empty', [FireQmController::class, 'emptyForm'])->withoutMiddleware('throttle:firetab-write');
            Route::post('/empty', [FireQmController::class, 'emptyRun']);
            Route::post('/bulk-archive', [FireQmController::class, 'bulkArchive']);
            Route::get('/incidents/{incident}', [FireQmController::class, 'show'])->whereNumber('incident')->withoutMiddleware('throttle:firetab-write');
            Route::get('/incidents/{incident}/asu/state', [AsuController::class, 'state'])->whereNumber('incident')
                ->withoutMiddleware('throttle:firetab-write')->middleware('throttle:firetab-asu');
            Route::get('/incidents/{incident}/map', [MapController::class, 'index'])->whereNumber('incident')->withoutMiddleware('throttle:firetab-write');
            Route::prefix('{incident}')->whereNumber('incident')->group(function () {
                Route::patch('/status', [FireQmController::class, 'status']);
                Route::post('/archive', [FireQmController::class, 'archive']);
                Route::delete('/archive', [FireQmController::class, 'unarchive']);
                Route::post('/reopen', [FireQmController::class, 'reopen']);
            });
        });

        // Every crew route carries firetab.crew: the policy trusts the crew session without re-checking the vehicle.
        Route::middleware('firetab.crew')->group(function () {
            // Tablet entry: ef_bridge opens /einsatz/list.php, NormalizeLegacyPath rewrites it here internally. Path stays.
            Route::get('/list', [FireIncidentController::class, 'list']);
            Route::get('/vehicles/search', [IncidentVehicleController::class, 'search'])->middleware('throttle:120,1,firetab-search');
            Route::get('/personnel/search', [FireIncidentController::class, 'personnel'])->middleware('throttle:120,1,firetab-search');
            Route::get('/incidents/create', [FireIncidentController::class, 'create']);
            Route::get('/status', [FireStatusController::class, 'show']);
            Route::post('/status', [FireStatusController::class, 'store'])->middleware('throttle:firetab-status');

            Route::get('/logbook', [FireLogbookController::class, 'index']);
            Route::middleware('throttle:firetab-write')->group(function () {
                Route::post('/logbook', [FireLogbookController::class, 'store']);
                Route::put('/logbook/{entry}', [FireLogbookController::class, 'update'])->whereNumber('entry');
            });

            Route::prefix('incidents')->middleware('throttle:firetab-write')->group(function () {
                Route::post('/', [FireIncidentController::class, 'store']);

                Route::prefix('{incident}')->whereNumber(['incident', 'vehicle'])->scopeBindings()->group(function () {
                    Route::get('/', [FireIncidentController::class, 'show'])->withoutMiddleware('throttle:firetab-write');
                    Route::put('/', [FireIncidentController::class, 'update']);
                    Route::put('/report', [FireIncidentController::class, 'report']);
                    Route::post('/vehicles', [IncidentVehicleController::class, 'store']);
                    Route::delete('/vehicles/{vehicle}', [IncidentVehicleController::class, 'destroy']);
                    Route::post('/sitreps', [SitrepController::class, 'store']);
                    Route::post('/finalize', FinalizeController::class);
                });
            });

            Route::prefix('incidents/{incident}/map')->whereNumber(['incident', 'mapMarker', 'mapZone'])->scopeBindings()->group(function () {
                Route::get('/', [MapController::class, 'index']);
                Route::middleware('throttle:firetab-write')->group(function () {
                    Route::post('/markers', [MapController::class, 'storeMarker']);
                    Route::patch('/markers/{mapMarker}', [MapController::class, 'moveMarker']);
                    Route::delete('/markers/{mapMarker}', [MapController::class, 'destroyMarker']);
                    Route::post('/zones', [MapController::class, 'storeZone']);
                    Route::delete('/zones/{mapZone}', [MapController::class, 'destroyZone']);
                    Route::put('/location', [MapController::class, 'location']);
                });
            });

            Route::prefix('incidents/{incident}/asu')->whereNumber(['incident', 'asuTrupp', 'asuEvent'])->scopeBindings()
                ->middleware('throttle:firetab-asu')->group(function () {
                    Route::get('/state', [AsuController::class, 'state']);
                    Route::post('/trupps', [AsuController::class, 'storeTrupp']);
                    Route::put('/trupps/{asuTrupp}', [AsuController::class, 'updateTrupp']);
                    Route::delete('/trupps/{asuTrupp}', [AsuController::class, 'destroyTrupp']);
                    Route::post('/events', [AsuController::class, 'storeEvent']);
                    Route::post('/events/{asuEvent}/void', [AsuController::class, 'voidEvent']);
                });
        });
    });
});
