<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Search\SearchRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GET /api/system/global-search?q=&scope= for the command palette (legacy Api\SystemController::globalSearch,
 * contract): `{q, results: [{key, label, items: [{label, sub, href, approx?}]}], scopes: [{key, label}]}`.
 * Hits differ per account, so the answer is never cached; a failure answers 500 without its text.
 */
class GlobalSearchController extends Controller
{
    public function __invoke(Request $request, SearchRegistry $registry): JsonResponse
    {
        $q = $request->query('q');
        $scope = $request->query('scope');
        try {
            $body = $registry->search($request->user(), is_string($q) ? $q : '', is_string($scope) ? $scope : 'all');
            $response = response()->json($body);
        } catch (Throwable $e) {
            Log::error('Search: request failed', ['exception' => $e::class]);
            $response = response()->json(['error' => __('search.failed')], 500);
        }

        return $response->header('Cache-Control', 'private, no-store');
    }
}
