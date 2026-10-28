<?php

namespace Modules\ManvBoard\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ManvBoard\Enums\ResourceStatus;
use Modules\ManvBoard\Enums\ResourceType;
use Modules\ManvBoard\Http\Requests\ResourceRequest;
use Modules\ManvBoard\Models\ManvIncident;
use Modules\ManvBoard\Models\ManvPatient;
use Modules\ManvBoard\Models\ManvResource;
use Modules\ManvBoard\Policies\ManvIncidentPolicy;
use Modules\ManvBoard\Support\ManvLog;

/** Resources of a Lage (scoped binding). Everyone who may view sees the list; writes need manage and an active Lage. */
class ResourceController extends Controller
{
    private const MODULE = 'MANV';

    private const VEHICLE_LIMIT = 50;

    public function index(Request $request, ManvIncident $incident): Response
    {
        $this->authorize('view', $incident);
        $q = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $search = trim($q['search'] ?? '');
        $can = (new ManvIncidentPolicy)->canWrite($request->user(), $incident);

        return Inertia::render('ManvBoard/Resources', [
            'incident' => ['id' => $incident->id, 'incident_number' => $incident->incident_number, 'status' => $incident->status->value],
            'resources' => $incident->resources()->withCount(['patients as on_site' => fn ($q) => $q->whereNull('departed_at')])
                ->orderBy('label')->get()->map(fn (ManvResource $r) => [
                    'id' => $r->id,
                    'type' => $r->type->value,
                    'label' => $r->label,
                    'callsign' => $r->callsign,
                    'vehicle_type' => $r->vehicle_type,
                    'location' => $r->location,
                    'status' => $r->status->value,
                    'crew' => $r->crew,
                    'notes' => $r->notes,
                    'departed' => $r->departed_at !== null,
                    'on_site' => (int) $r->getAttribute('on_site'),
                ])->all(),
            'stock' => $can ? Vehicle::query()->active()
                ->whereNotIn('id', $incident->resources()->whereNotNull('vehicle_id')->select('vehicle_id'))
                ->when($search !== '', fn ($w) => $w->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('identifier', 'like', "%{$search}%")->orWhere('type', 'like', "%{$search}%")))
                ->orderBy('name')->limit(self::VEHICLE_LIMIT)->get(['id', 'name', 'identifier', 'type'])->all() : [],
            'search' => $search,
            'statuses' => array_column(ResourceStatus::cases(), 'value'),
            'types' => array_column(ResourceType::cases(), 'value'),
            'can' => ['write' => $can],
        ]);
    }

    public function store(ResourceRequest $request, ManvIncident $incident): RedirectResponse
    {
        $data = $request->safe()->only(['status', 'crew', 'location', 'notes', 'type', 'label', 'callsign', 'vehicle_type']);
        $data['status'] ??= ResourceStatus::Available->value;
        $vehicleId = $request->integer('vehicle_id');
        if ($vehicleId > 0) {
            $v = Vehicle::query()->findOrFail($vehicleId);
            $data = ['type' => ResourceType::Vehicle->value, 'label' => $v->name, 'callsign' => $v->identifier, 'vehicle_type' => $v->type, 'vehicle_id' => $v->id] + $data;
        }

        $resource = $this->unique(fn () => DB::transaction(function () use ($incident, $data) {
            $r = (new ManvResource)->forceFill($data + ['manv_incident_id' => $incident->id]);
            $r->save();
            ManvLog::record($incident, 'ressource_erstellt', trans('manvboard.log.ressource_erstellt', ['label' => $r->label]), $r);

            return $r;
        }));
        $this->audit($request->user(), 'Ressource erstellt', $incident, $resource);

        return back()->with('status', 'manvboard.resources.saved');
    }

    public function update(ResourceRequest $request, ManvIncident $incident, ManvResource $resource): RedirectResponse
    {
        $data = $request->safe()->only(['status', 'crew', 'location', 'notes', 'label', 'callsign', 'vehicle_type']);
        $data['status'] ??= $resource->status->value;
        $this->unique(fn () => DB::transaction(function () use ($incident, $resource, $data) {
            $resource->forceFill($data)->save();
            ManvLog::record($incident, 'ressource_aktualisiert', trans('manvboard.log.ressource_aktualisiert', ['label' => $resource->label]), $resource);
        }));
        $this->audit($request->user(), 'Ressource bearbeitet', $incident, $resource);

        return back()->with('status', 'manvboard.resources.saved');
    }

    public function destroy(ResourceRequest $request, ManvIncident $incident, ManvResource $resource): RedirectResponse
    {
        DB::transaction(function () use ($incident, $resource) {
            ManvResource::query()->whereKey($resource->id)->lockForUpdate()->firstOrFail();
            $number = ManvPatient::query()->where('manv_resource_id', $resource->id)->whereNull('departed_at')->lockForUpdate()->value('number');
            if ($number !== null) {
                throw ValidationException::withMessages(['delete' => trans('manvboard.resources.in_use', ['label' => $resource->label, 'number' => $number])]);
            }
            $resource->delete();
            ManvLog::record($incident, 'ressource_geloescht', trans('manvboard.log.ressource_geloescht', ['label' => $resource->label]));
        });
        $this->audit($request->user(), 'Ressource gelöscht', $incident, $resource);

        return back()->with('status', 'manvboard.resources.deleted');
    }

    private function unique(\Closure $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['label' => trans('validation.unique', ['attribute' => 'label'])]);
        }
    }

    private function audit(?User $user, string $action, ManvIncident $incident, ManvResource $resource): void
    {
        AuditLogger::log($user, $action, 'Lage: '.$incident->incident_number.', Ressource: '.$resource->label, self::MODULE, true, ['id' => $resource->id, 'incident_id' => $incident->id]);
    }
}
