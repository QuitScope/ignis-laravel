<?php

namespace Modules\Federation\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Enotf\Enums\ProtocolStatus;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Federation\Models\FederationLink;
use Modules\Federation\Support\PaginationCursor;

/**
 * GET /api/federation/enotf?after&limit: every protocol changed since the cursor. Released and not hidden => the legacy
 * fields 1:1 (patient identity included; fictional game data); anything else (open, withdrawn, hidden) =>
 * tombstone {id, visible: false}. L never hard-deletes protocols (checked for 9.4-2), so tombstones cover every exit.
 */
class ProtocolController extends Controller
{
    public const FIELDS = ['id', 'visible', 'enr', 'edatum', 'ezeit', 'patname', 'pat_vorname', 'pat_nachname', 'pfname', 'patgebdat', 'patsex',
        'einsatzort', 'elokation', 'fzg_transp', 'fzg_na', 'ziel_poi', 'ziel_adresse', 'naca', 'sendezeit', 'updated_at',
        'fahrername', 'fahrerquali', 'beifahrername', 'beifahrerquali', 'praktikantname', 'praktikantquali'];

    public function __invoke(Request $request): JsonResponse
    {
        if (! ModuleRegistry::active('enotf')) {
            return response()->json(['success' => false, 'error' => 'module_off'], 404);
        }
        /** @var FederationLink $link */
        $link = $request->attributes->get('federation_link');
        if (! $link->provide_enotf) {
            return response()->json(['success' => false, 'error' => 'not_provided'], 403);
        }

        return PaginationCursor::respond(EnotfProtocol::query()->with('crew'), $request, fn (EnotfProtocol $p) => self::row($p));
    }

    /** @return array<string, mixed> */
    public static function row(EnotfProtocol $p): array
    {
        if ($p->status !== ProtocolStatus::Released || $p->hidden_at !== null) {
            return ['id' => $p->id, 'visible' => false];
        }
        $data = $p->data ?? [];
        $legacy = $p->legacy ?? [];
        $crew = $p->crew->where('slot', 'transport')->keyBy('position');
        $derived = trim(implode(', ', array_filter([$p->patient_last_name, $p->patient_first_name])));

        return [
            'id' => $p->id,
            'visible' => true,
            'enr' => $p->enr,
            'edatum' => $p->mission_date?->format('Y-m-d'),
            'ezeit' => $p->mission_time,
            'patname' => $legacy['patname'] ?? ($derived !== '' ? $derived : null),
            'pat_vorname' => $p->patient_first_name,
            'pat_nachname' => $p->patient_last_name,
            'pfname' => $data['pfname'] ?? null,
            'patgebdat' => $p->patient_birthdate?->format('Y-m-d'),
            'patsex' => $p->getAttribute('patient_sex'),
            'einsatzort' => $legacy['einsatzort'] ?? null,
            'elokation' => $data['elokation'] ?? null,
            'fzg_transp' => $p->vehicle_transport_label,
            'fzg_na' => $p->vehicle_na_label,
            'ziel_poi' => $data['ziel_poi'] ?? null,
            'ziel_adresse' => $data['ziel_adresse'] ?? null,
            'naca' => $legacy['naca'] ?? $data['naca_uebergabe'] ?? $data['naca_initial'] ?? null,
            'sendezeit' => $p->created_at?->toIso8601String(),
            'updated_at' => $p->updated_at?->toIso8601String(),
            'fahrername' => $crew->get(1)?->name, 'fahrerquali' => $crew->get(1)?->qualification,
            'beifahrername' => $crew->get(2)?->name, 'beifahrerquali' => $crew->get(2)?->qualification,
            'praktikantname' => $crew->get(3)?->name, 'praktikantquali' => $crew->get(3)?->qualification,
        ];
    }
}
