<?php

namespace App\Http\Controllers\Api;

use App\Emd\EmdPayload;
use App\Emd\EmdSync;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/**
 * POST api/emd/sync (ef_bridge heartbeat and vehicle registry; `.php` and the alias /api/emd-sync reach it too).
 * Middleware: EmdBodyLimit (global), api.key, throttle:emd-sync. All syncs run one after another under a global lock.
 */
final class EmdSyncController
{
    public function __invoke(Request $request, EmdSync $sync): JsonResponse
    {
        if (! json_decode($request->getContent()) instanceof stdClass) {
            return response()->json(['success' => false, 'error' => 'Ungültiges JSON'], 400);
        }
        $json = $request->json()->all();

        try {
            // ponytail: one global lock, a sync at a time; a lock per dispatch number when that ever gets too slow.
            return Cache::lock('emd-sync', 30)->block(10, fn () => $sync->run(EmdPayload::fromRequest($json)));
        } catch (LockTimeoutException) {
            return response()->json(['success' => false, 'error' => 'Synchronisation belegt'], 503);
        } catch (Throwable $e) {
            // Only the class: a query exception's message carries its bindings (patient names).
            Log::error('EMD sync failed', ['exception' => $e::class]);

            return response()->json(['success' => false, 'error' => 'Interner Fehler'], 500);
        }
    }
}
