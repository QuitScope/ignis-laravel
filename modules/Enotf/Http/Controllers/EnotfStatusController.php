<?php

namespace Modules\Enotf\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Enotf\Models\CrewMember;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Models\ShareRequest;
use Modules\Enotf\Support\CrewService;

/**
 * GET /enotf/status: one bundled poll per tablet every 10 s (paused while the page is hidden) instead
 * of the legacy session/sync polls. `crew: null` = no (more) crew on this device; `locked` = PIN lock (no 423 here, the
 * poll is how the device learns it; it is no crew activity). The ETag covers everything but the
 * server time, the client sends it back (If-None-Match) and gets a 304. A few indexed queries per poll.
 */
class EnotfStatusController extends Controller
{
    public function __invoke(Request $request, CrewService $crews, Settings $settings): JsonResponse
    {
        $member = $crews->member($request->user());
        $payload = ['crew' => null, 'locked' => false, 'protocols' => [], 'shares' => []];
        if ($member !== null) {
            $crew = $member->crew;
            $payload = [
                'crew' => [
                    'vehicle_id' => $crew->vehicle_id,
                    'members' => $crew->members()->whereNotNull('active_key')->with('personnel.title')->orderBy('position')->get()
                        ->map(fn (CrewMember $m) => ['position' => $m->position, 'name' => $m->personnel->displayName(), 'self' => $m->id === $member->id])->all(),
                ],
                'locked' => $crews->locked($crew),
                'protocols' => EnotfProtocol::query()->openForVehicle($crew->vehicle_id)->orderBy('id')->get(['enr', 'revision', 'status'])
                    ->map(fn (EnotfProtocol $p) => ['enr' => $p->enr, 'revision' => $p->revision, 'status' => $p->status->value])->all(),
                'shares' => ShareRequest::query()->live()->where('to_vehicle_id', $crew->vehicle_id)->with(['protocol:id,enr', 'fromVehicle:id,identifier'])
                    ->orderBy('id')->limit(20)->get()
                    ->map(fn (ShareRequest $s) => ['id' => $s->id, 'enr' => $s->protocol->enr, 'from' => $s->fromVehicle->identifier, 'expires_at' => $s->expires_at->toIso8601String()])->all(),
            ];
        }

        $payload['emd_last_sync_at'] = $settings->get('emd.last_sync_at');
        $response = response()->json($payload + ['time' => now()->toIso8601String()]);
        $response->setEtag(md5((string) json_encode($payload)));
        $response->isNotModified($request);

        return $response;
    }
}
