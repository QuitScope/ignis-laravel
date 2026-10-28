<?php

use Illuminate\Support\Facades\Route;
use Modules\Enotf\Http\Controllers\BillingController;

// ModuleProvider wraps this file in ['module:enotf', 'api']: stateless, no session, cookies or CSRF.
// ef_bridge posts `api/enotf/billing.php`; NormalizeLegacyPath strips `.php` and rewrites the alias `/api/enotf-billing` here.
// Throttle after the key check (bad keys are limited by api.key itself), 6/min for the one system key.
Route::post('/api/enotf/billing', BillingController::class)->middleware(['api.key', 'throttle:enotf-billing']);

// Legacy endpoints without a right: gone, 410. Only the legacy JS called them.
Route::any('/api/hospitals/{rest}', fn () => abort(410))->where('rest', '.*');
Route::any('/api/hospital-availability-{rest}', fn () => abort(410));
// The rest of the legacy plugin API (crew JS: autosave, vitals, medis, share, prereg poll, ...; clinic code generation,
// department sorting) has new routes outside /api; billing stays (POST above, other verbs keep their 405).
Route::any('/api/enotf/{rest}', fn () => abort(410))->where('rest', '(?!billing$).+');
Route::any('/api/klinik/{rest}', fn () => abort(410))->where('rest', '.*');
Route::any('/api/generate-klinikcode', fn () => abort(410));
Route::any('/api/pois/departments-sort', fn () => abort(410));
