<?php

namespace Modules\Federation\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Personnel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Federation\Models\FederationLink;
use Modules\Federation\Support\PaginationCursor;

/**
 * GET /api/federation/personnel?after&limit: active personnel (rank not archived), the legacy fields 1:1 minus the
 * badge URL. Never account, Discord, birth date, contact, notes or pictures. The consumer pulls the
 * full list each run; hard-deleted or archived people drop out of it (no tombstones needed).
 */
class PersonnelController extends Controller
{
    public const FIELDS = ['id', 'full_name', 'service_number', 'rank', 'qualifications'];

    public function __invoke(Request $request): JsonResponse
    {
        /** @var FederationLink $link */
        $link = $request->attributes->get('federation_link');
        if (! $link->provide_personnel) {
            return response()->json(['success' => false, 'error' => 'not_provided'], 403);
        }

        $query = Personnel::query()->active()->with(['rank', 'emsQualification', 'fireQualification', 'specialties']);

        return PaginationCursor::respond($query, $request, fn (Personnel $p) => [
            'id' => $p->id,
            'full_name' => $p->full_name,
            'service_number' => $p->service_number,
            'rank' => $p->rank->name,
            'qualifications' => [
                'rd' => $p->emsQualification?->name,
                'rd_short' => $p->emsQualification?->abbreviation,
                'fw' => $p->fireQualification?->name,
                'fd' => $p->specialties->pluck('name')->values()->all(),
            ],
        ]);
    }
}
