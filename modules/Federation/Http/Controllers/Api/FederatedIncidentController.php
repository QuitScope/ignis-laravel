<?php

namespace Modules\Federation\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Federation\Models\FederationLink;
use Modules\Federation\Support\PaginationCursor;
use Modules\FireTab\Enums\FireIncidentStatus;
use Modules\FireTab\Models\FireIncident;

/**
 * GET /api/federation/fire-incidents?after&limit: every incident changed since the cursor. Not archived and not hidden
 * => the legacy fields 1:1, owner and caller contact included (fictional game data; owner_type has no L
 * column); archived or hidden => tombstone {id, visible: false}. L never hard-deletes incidents (checked for 9.4-2).
 */
class FederatedIncidentController extends Controller
{
    public const FIELDS = ['id', 'visible', 'incident_number', 'keyword', 'location', 'location_x', 'location_y', 'status', 'finalized',
        'leader_id', 'leader_name', 'owner_name', 'owner_contact', 'caller_name', 'caller_contact', 'started_at', 'created_at', 'updated_at'];

    public function __invoke(Request $request): JsonResponse
    {
        if (! ModuleRegistry::active('firetab')) {
            return response()->json(['success' => false, 'error' => 'module_off'], 404);
        }
        /** @var FederationLink $link */
        $link = $request->attributes->get('federation_link');
        if (! $link->provide_fire) {
            return response()->json(['success' => false, 'error' => 'not_provided'], 403);
        }

        return PaginationCursor::respond(FireIncident::query()->with('leader'), $request, fn (FireIncident $i) => self::row($i));
    }

    /** @return array<string, mixed> */
    public static function row(FireIncident $i): array
    {
        if ($i->archived_at !== null || $i->status === FireIncidentStatus::Hidden) {
            return ['id' => $i->id, 'visible' => false];
        }

        return [
            'id' => $i->id,
            'visible' => true,
            'incident_number' => $i->number,
            'keyword' => $i->keyword,
            'location' => $i->location,
            'location_x' => $i->location_x,
            'location_y' => $i->location_y,
            'status' => $i->status->value,
            'finalized' => $i->finalized_at !== null,
            'leader_id' => $i->leader_personnel_id,
            'leader_name' => $i->leader->full_name ?? $i->leader_external_name,
            'owner_name' => $i->owner_name,
            'owner_contact' => $i->owner_contact,
            'caller_name' => $i->caller_name,
            'caller_contact' => $i->caller_contact,
            'started_at' => $i->started_at?->toIso8601String(),
            'created_at' => $i->created_at->toIso8601String(),
            'updated_at' => $i->updated_at?->toIso8601String(),
        ];
    }
}
