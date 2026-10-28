<?php

namespace Modules\FireTab\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\FireTab\Support\StatusQueue;

/** POST /api/emd/status-poll (api.key, stateless): hands out queued FMS status changes once, legacy response shape. */
class StatusPollController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['success' => true, 'status_changes' => StatusQueue::deliver()]);
    }
}
