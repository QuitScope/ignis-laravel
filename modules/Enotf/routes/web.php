<?php

use Illuminate\Support\Facades\Route;
use Modules\Enotf\Http\Controllers\AccessCodeController;
use Modules\Enotf\Http\Controllers\AdminController;
use Modules\Enotf\Http\Controllers\CategoryController;
use Modules\Enotf\Http\Controllers\ClinicCodeController;
use Modules\Enotf\Http\Controllers\CrewController;
use Modules\Enotf\Http\Controllers\DefectController;
use Modules\Enotf\Http\Controllers\DepartmentController;
use Modules\Enotf\Http\Controllers\EnotfLogbookController;
use Modules\Enotf\Http\Controllers\EnotfQmController;
use Modules\Enotf\Http\Controllers\EnotfStatusController;
use Modules\Enotf\Http\Controllers\HospitalPortalController;
use Modules\Enotf\Http\Controllers\LockController;
use Modules\Enotf\Http\Controllers\LoginController;
use Modules\Enotf\Http\Controllers\MedicationController;
use Modules\Enotf\Http\Controllers\MedicationSettingsController;
use Modules\Enotf\Http\Controllers\OverviewController;
use Modules\Enotf\Http\Controllers\PoiSearchController;
use Modules\Enotf\Http\Controllers\PreregistrationController;
use Modules\Enotf\Http\Controllers\PrintController;
use Modules\Enotf\Http\Controllers\ProtocolController;
use Modules\Enotf\Http\Controllers\QuicklinkController;
use Modules\Enotf\Http\Controllers\ReleaseController;
use Modules\Enotf\Http\Controllers\ShareController;
use Modules\Enotf\Http\Controllers\VitalController;
use Modules\Enotf\Http\Middleware\EnotfNoStore;
use Modules\Enotf\Http\Middleware\EnsureHospitalSession;
use Modules\Enotf\Http\Middleware\EnsurePinUnlocked;
use Modules\Enotf\Support\FieldSchema;

// ModuleProvider wraps this file in ['module:enotf', 'web']. `fivem`: the pages run in FiveM's CEF webview (docs/dev/cef.md).
// EnsurePinUnlocked on every route (JSON too, 423) except signing in/out, the lock screen, the status poll and the personnel search.
Route::middleware(['auth', 'fivem', EnotfNoStore::class, EnsurePinUnlocked::class])->prefix('enotf')->group(function () {
    Route::withoutMiddleware(EnsurePinUnlocked::class)->group(function () {
        Route::get('/', [LoginController::class, 'home']);
        Route::get('/login', [LoginController::class, 'show']);
        Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:10,1,enotf-login');
        Route::post('/logout', [LoginController::class, 'destroy']);
        Route::get('/loggedout', [LoginController::class, 'loggedOut']);
        Route::get('/lockscreen', [LockController::class, 'show']);
        Route::post('/unlock', [LockController::class, 'unlock']);
        Route::get('/status', EnotfStatusController::class)->middleware('throttle:30,1,enotf-status');
        Route::get('/personnel/search', [LoginController::class, 'personnel'])->middleware('throttle:120,1,enotf-search');
    });
    Route::post('/pin-activity', [LockController::class, 'activity'])->middleware('throttle:30,1,enotf-activity');
    Route::middleware('throttle:30,1,enotf-crew')->prefix('crew')->group(function () {
        Route::post('/join', [CrewController::class, 'join']);
        Route::post('/leave', [CrewController::class, 'leave']);
        Route::post('/end', [CrewController::class, 'end']);
    });

    // Protocol pages (path kept, Discord links). Outside crew:enotf: QM reads them too; the policy decides (crew vehicle or QM).
    Route::get('/p/{protocol}/plausibility', [ProtocolController::class, 'plausibility']);
    Route::get('/p/{protocol}/changes', [ProtocolController::class, 'changes'])->middleware('throttle:60,1,enotf-changes');
    Route::post('/p/{protocol}/patient-sync', [ProtocolController::class, 'patientSync'])->middleware('throttle:10,1,enotf-patient-sync');
    Route::get('/p/{protocol}/{section?}', [ProtocolController::class, 'show'])
        ->where('section', implode('|', FieldSchema::SECTIONS));
    Route::patch('/p/{protocol}', [ProtocolController::class, 'update'])->middleware('throttle:120,1,enotf-save');
    Route::put('/p/{protocol}/address/{kind}', [ProtocolController::class, 'address'])->where('kind', 'transport|target')->middleware('throttle:120,1,enotf-save');
    Route::scopeBindings()->middleware('throttle:120,1,enotf-save')->whereNumber(['vital', 'medication'])->group(function () {
        Route::get('/p/{protocol}/vitals', [VitalController::class, 'index']);
        Route::post('/p/{protocol}/vitals', [VitalController::class, 'store']);
        Route::delete('/p/{protocol}/vitals/{vital}', [VitalController::class, 'destroy']);
        Route::get('/p/{protocol}/medications', [MedicationController::class, 'index']);
        Route::post('/p/{protocol}/medications', [MedicationController::class, 'store']);
        Route::delete('/p/{protocol}/medications/{medication}', [MedicationController::class, 'destroy']);
    });
    Route::post('/p/{protocol}/release', ReleaseController::class)->middleware('throttle:10,1,enotf-release');
    Route::get('/print/{protocol}', PrintController::class)->where('protocol', '(?!index$)[^/]+');

    // QM: no crew session, the policy decides (viewQm; hiding needs bulkDelete).
    Route::prefix('admin')->group(function () {
        Route::get('/', [AdminController::class, 'index']);
        Route::get('/empty', [AdminController::class, 'emptyForm']);
        Route::middleware('throttle:30,1,enotf-admin')->group(function () {
            Route::post('/hide', [AdminController::class, 'hide']);
            Route::post('/empty', [AdminController::class, 'emptyRun']);
        });
    });

    // QM dialog (9d): no crew session; viewQm reads, review writes (controller).
    Route::prefix('qm')->group(function () {
        Route::get('/{protocol}', [EnotfQmController::class, 'show']);
        Route::middleware('throttle:30,1,enotf-qm')->group(function () {
            Route::patch('/{protocol}/status', [EnotfQmController::class, 'status']);
            Route::post('/{protocol}/comments', [EnotfQmController::class, 'comment']);
            Route::post('/{protocol}/reopen', [EnotfQmController::class, 'reopen']);
        });
    });

    // Every crew route carries crew:enotf; the policy trusts the crew session for the vehicle.
    Route::middleware('crew:enotf')->group(function () {
        Route::get('/overview', OverviewController::class);
        Route::get('/create', [ProtocolController::class, 'create']);
        Route::get('/pois/search', PoiSearchController::class)->middleware('throttle:120,1,enotf-search');
        Route::get('/fahrzeuginfo', [DefectController::class, 'index']);
        Route::get('/hospitals', [DepartmentController::class, 'hospitals']);
        Route::get('/fahrtenbuch', [EnotfLogbookController::class, 'index']);
        Route::middleware('throttle:30,1,enotf-write')->group(function () {
            Route::post('/fahrzeuginfo/defects', [DefectController::class, 'store']);
            Route::post('/fahrtenbuch', [EnotfLogbookController::class, 'store']);
            Route::put('/fahrtenbuch/{entry}', [EnotfLogbookController::class, 'update'])->whereNumber('entry');
        });
        Route::get('/share/vehicles', [ShareController::class, 'vehicles'])->middleware('throttle:30,1,enotf-share-list');
        Route::post('/p/{protocol}/share', [ShareController::class, 'store'])->middleware('throttle:20,1,enotf-share');
        Route::middleware('throttle:30,1,enotf-share-answer')->whereNumber('share')->group(function () {
            Route::post('/share/{share}/accept', [ShareController::class, 'accept']);
            Route::post('/share/{share}/reject', [ShareController::class, 'reject']);
            Route::post('/share/{share}/cancel', [ShareController::class, 'cancel']);
        });
        Route::post('/p/{protocol}/clinic-code', [ClinicCodeController::class, 'store'])->middleware('throttle:10,1,enotf-clinic-code-create');
        Route::get('/p/{protocol}/prereg', [PreregistrationController::class, 'index']);
        Route::middleware('throttle:30,1,enotf-prereg')->group(function () {
            Route::post('/p/{protocol}/prereg', [PreregistrationController::class, 'store']);
            Route::delete('/p/{protocol}/prereg/{prereg}', [PreregistrationController::class, 'cancel'])->whereNumber('prereg');
        });
        Route::delete('/p/{protocol}', [ProtocolController::class, 'destroy'])->middleware('throttle:30,1,enotf-create');
        Route::middleware('throttle:30,1,enotf-create')->group(function () {
            Route::post('/protocols', [ProtocolController::class, 'store']);
            Route::post('/protocols/withdraw-all', [ProtocolController::class, 'withdrawAll']);
        });
    });
});

Route::middleware(['auth', 'throttle:60,1,enotf-settings'])->prefix('settings/enotf')->whereNumber(['category', 'quicklink'])->group(function () {
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{category}', [CategoryController::class, 'update']);
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);
    Route::get('/quicklinks', [QuicklinkController::class, 'index']);
    Route::post('/quicklinks', [QuicklinkController::class, 'store']);
    Route::put('/quicklinks/{quicklink}', [QuicklinkController::class, 'update']);
    Route::delete('/quicklinks/{quicklink}', [QuicklinkController::class, 'destroy']);
});

Route::middleware(['auth', 'throttle:60,1,enotf-settings'])->prefix('settings/medications')->whereNumber('medication')->group(function () {
    Route::get('/', [MedicationSettingsController::class, 'index']);
    Route::post('/', [MedicationSettingsController::class, 'store']);
    Route::put('/{medication}', [MedicationSettingsController::class, 'update']);
    Route::delete('/{medication}', [MedicationSettingsController::class, 'destroy']);
});

// Clinic portal: no account, no crew context. A clinic device signs in with its hospital's access code
// (5/min per IP); the session key enotf_hospital is all it has, checked on every portal route (EnsureHospitalSession).
Route::middleware(['fivem', EnotfNoStore::class])->prefix('enotf/schnittstelle')->group(function () {
    Route::get('/login', [HospitalPortalController::class, 'showLogin']);
    Route::post('/login', [HospitalPortalController::class, 'login'])->middleware('throttle:enotf-hospital-login');
    Route::post('/logout', [HospitalPortalController::class, 'logout']);
    // Clinic code: 10/min per IP; the session reads the one bound protocol, nothing else.
    Route::get('/klinikcode', [ClinicCodeController::class, 'form']);
    Route::post('/klinikcode', [ClinicCodeController::class, 'redeem'])->middleware('throttle:enotf-clinic-code');
    Route::get('/protocol', [ClinicCodeController::class, 'show']);
    Route::middleware(EnsureHospitalSession::class)->group(function () {
        Route::get('/', [PreregistrationController::class, 'board']);
        Route::get('/prereg', [PreregistrationController::class, 'poll'])->middleware('throttle:30,1,enotf-portal-poll');
        Route::put('/prereg/{prereg}', [PreregistrationController::class, 'mark'])->whereNumber('prereg')->middleware('throttle:60,1,enotf-portal');
        Route::get('/availability', [HospitalPortalController::class, 'availability']);
        Route::put('/availability/{department}', [HospitalPortalController::class, 'updateAvailability'])->whereNumber('department')->middleware('throttle:60,1,enotf-portal');
    });
});

Route::middleware(['auth', 'throttle:60,1,poi-settings'])->prefix('settings/pois/{poi}')->whereNumber(['poi', 'department'])->group(function () {
    Route::get('/departments', [DepartmentController::class, 'index']);
    Route::post('/departments', [DepartmentController::class, 'store']);
    Route::put('/departments/{department}', [DepartmentController::class, 'update']);
    Route::delete('/departments/{department}', [DepartmentController::class, 'destroy']);
    Route::post('/access-code', [AccessCodeController::class, 'rotate']);
    Route::delete('/access-code', [AccessCodeController::class, 'destroy']);
});
