<?php

namespace Modules\ManvBoard\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ManvBoard\Enums\ResourceType;
use Modules\ManvBoard\Models\ManvIncident;
use Modules\ManvBoard\Models\ManvPatient;
use Modules\ManvBoard\Models\ManvResource;
use Modules\ManvBoard\Policies\ManvIncidentPolicy;
use Modules\ManvBoard\Support\IncidentStats;
use Modules\ManvBoard\Support\TransportRules;

/**
 * Board of one Lage: patients still on site (lanes per triage + sortable table), vehicles with their load, counters.
 * Personal data (name, birth date, injuries) stays on the patient page. The page polls patients/resources/stats.
 */
class BoardController extends Controller
{
    private const SORTS = ['triage' => 'triage', 'number' => 'number', 'vehicle' => 'callsign', 'destination' => 'destination'];

    private const LIMIT = 1000;

    public function show(Request $request, ManvIncident $incident): Response
    {
        $this->authorize('view', $incident);
        $q = $request->validate([
            'sort' => ['nullable', 'string', Rule::in(array_keys(self::SORTS))],
            'dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ]);
        $sort = $q['sort'] ?? 'triage';
        $dir = $q['dir'] ?? 'asc';

        return Inertia::render('ManvBoard/Board', [
            'incident' => [
                'id' => $incident->id,
                'incident_number' => $incident->incident_number,
                'location' => $incident->location,
                'status' => $incident->status->value,
                'started_at' => $incident->started_at->copy()->timezone(config('ignis.timezone'))->format('d.m.Y H:i'),
                'lna_name' => $incident->lna_name,
                'orgl_name' => $incident->orgl_name,
            ],
            'patients' => fn () => $incident->patients()->whereNull('departed_at')
                ->with('resource.vehicle:id,service')
                ->orderBy(self::SORTS[$sort], $dir)->orderBy('number')->orderBy('id')
                ->limit(self::LIMIT)->get()
                ->map(fn (ManvPatient $p) => [
                    'id' => $p->id,
                    'number' => $p->number,
                    'triage' => $p->triage?->value,
                    'age' => $p->birth_date?->age,
                    'sex' => $p->sex->value,
                    'vehicle' => $p->resource->label ?? $p->callsign,
                    'destination' => $p->destination,
                    'can_depart' => TransportRules::canDepart($p),
                ])->all(),
            'resources' => fn () => $incident->resources()->where('type', ResourceType::Vehicle)->whereNull('departed_at')
                ->withCount(['patients as on_site' => fn ($q) => $q->whereNull('departed_at')])
                ->orderBy('label')->get()
                ->map(fn (ManvResource $r) => [
                    'id' => $r->id,
                    'label' => $r->label,
                    'vehicle_type' => $r->vehicle_type,
                    'status' => $r->status->value,
                    'load' => (int) $r->getAttribute('on_site'),
                ])->all(),
            'stats' => fn () => IncidentStats::counts([$incident->id])[$incident->id],
            'sort' => ['key' => $sort, 'dir' => $dir],
            'can' => [
                'write' => (new ManvIncidentPolicy)->canWrite($request->user(), $incident),
                'manage' => $request->user()?->can('update', $incident) === true,
            ],
        ]);
    }
}
