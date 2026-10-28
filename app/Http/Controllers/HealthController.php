<?php

namespace App\Http\Controllers;

use App\Support\HealthChecks;
use App\Update\InstalledVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HealthController
{
    public function index(Request $request): JsonResponse
    {
        $report = HealthChecks::run($request);

        return response()->json($report, $report['status'] === 'down' ? 503 : 200);
    }

    public function version(): JsonResponse
    {
        return response()->json(['version' => InstalledVersion::current(), 'system' => 'ignis']);
    }
}
