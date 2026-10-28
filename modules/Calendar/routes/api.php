<?php

use Illuminate\Support\Facades\Route;
use Modules\Calendar\Http\Controllers\IcalController;
use Modules\Calendar\Support\IcalToken;

// ModuleProvider wraps this file in ['module:calendar', 'api']: stateless, no session, cookies or CSRF.
// The legacy paths /api/v1/calendar/ical/{token} and /api/kalender/ical/{token} reach this route through
// NormalizeLegacyPath (api/v1 → api, kalender → calendar). Throttled per address (bucket calendar-ical).
Route::get('/api/calendar/ical/{token}', [IcalController::class, 'feed'])
    ->where('token', IcalToken::PATTERN)
    ->middleware('throttle:30,1,calendar-ical');
