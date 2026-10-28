<?php

namespace App\Http\Controllers\Settings;

use App\Auth\ApiKey;
use App\Http\Controllers\Controller;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The key ef_bridge uses against /api: fetched on click (never part of a page), regenerated on demand. */
class ApiKeyController extends Controller
{
    public function show(Request $request, ApiKey $apiKey): JsonResponse
    {
        $this->authorize('system.admin');
        $key = $apiKey->stored();
        if ($key !== null) {
            AuditLogger::log($request->user(), 'API-Schlüssel angezeigt', null, 'System', true);
        }

        return self::json($key);
    }

    public function regenerate(Request $request, ApiKey $apiKey): JsonResponse
    {
        $this->authorize('system.admin');
        $key = $apiKey->regenerate();
        AuditLogger::log($request->user(), 'API-Schlüssel neu erzeugt', null, 'System', true);

        return self::json($key);
    }

    private static function json(?string $key): JsonResponse
    {
        return response()->json(['key' => $key])->header('Cache-Control', 'no-store, private');
    }
}
