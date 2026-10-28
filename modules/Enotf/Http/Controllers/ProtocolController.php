<?php

namespace Modules\Enotf\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Personnel;
use App\Models\Poi;
use App\Models\Vehicle;
use App\Support\AuditLogger;
use App\Vehicles\CrewSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Enotf\Enums\PatientSync;
use Modules\Enotf\Enums\ProtocolOrigin;
use Modules\Enotf\Enums\ProtocolStatus;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Models\MedicationGiven;
use Modules\Enotf\Models\ProtocolCrew;
use Modules\Enotf\Support\ConditionsService;
use Modules\Enotf\Support\CrewContext;
use Modules\Enotf\Support\DispatchProtocols;
use Modules\Enotf\Support\FieldSchema;
use Modules\Enotf\Support\ProtocolCreator;
use Modules\Enotf\Support\ProtocolLifecycle;
use Modules\Enotf\Support\ProtocolMerger;
use Modules\Enotf\Support\ProtocolValues;
use Modules\Enotf\Support\VitalparameterCatalog;

/**
 * Protocol pages and autosave, creating protocols (legacy CreateController cases 1-4) and "withdraw all".
 * The crew vehicle's service picks the slot (ProtocolCreator).
 */
class ProtocolController extends Controller
{
    private const MAX_PATCH_BYTES = 200_000;

    /** GET /enotf/p/{enr}/{section?}: the section pages; read-only unless the viewer may write and it is open. */
    public function show(Request $request, EnotfProtocol $protocol, string $section = 'rettdaten'): Response
    {
        $this->authorize('view', $protocol);

        return Inertia::render('Enotf/Protocol', [
            'protocol' => [
                'enr' => $protocol->enr,
                'status' => $protocol->status->value,
                'revision' => $protocol->revision,
                'patient_sync' => $protocol->patient_sync->value,
                'vehicles' => array_values(array_filter([$protocol->vehicle_transport_label, $protocol->vehicle_na_label])),
                'destination_poi' => $protocol->destination_poi_id !== null ? Poi::query()->whereKey($protocol->destination_poi_id)->value('name') : null,
            ],
            'values' => fn () => ProtocolValues::from($protocol),
            'section' => $section,
            'vitals' => fn () => VitalController::course($protocol),
            'bz_unit' => VitalparameterCatalog::bzUnit(),
            'medications' => fn () => MedicationGiven::listFor($protocol),
            'medication_catalog' => fn () => MedicationController::catalog(),
            'medication_routes' => MedicationGiven::ROUTES,
            'medication_units' => MedicationGiven::UNITS,
            'readonly' => ! ($protocol->isOpen() && $request->user()->can('update', $protocol)),
            'can' => fn () => ['release' => $request->user()->can('release', $protocol), 'withdraw' => $request->user()->can('withdraw', $protocol), 'share' => $request->user()->can('share', $protocol), 'handover' => $request->user()->can('handover', $protocol), 'qm' => $request->user()->can('viewQm', EnotfProtocol::class)],
            'now' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'timezone' => (string) config('ignis.timezone'),
            'crew' => fn () => $protocol->crew()->orderBy('slot')->orderBy('position')->get()
                ->map(fn (ProtocolCrew $c) => ['slot' => $c->slot, 'position' => $c->position, 'name' => $c->name])->all(),
            'vehicle_suggestions' => fn () => [
                'fzg_transp' => self::vehicleSuggestions(Vehicle::SERVICE_EMS),
                'fzg_na' => self::vehicleSuggestions(Vehicle::SERVICE_EMS_PHYSICIAN),
            ],
            'pfname_suggestions' => fn () => array_values(array_unique(array_filter([
                ...$protocol->crew()->pluck('name')->all(),
                Personnel::query()->with('title')->find(app(CrewContext::class)->personnelId())?->displayName() ?? $request->user()->name,
            ], fn ($n) => is_string($n) && $n !== ''))),
            'schema' => fn () => FieldSchema::client(),
            'vital_max' => collect(['spo2', 'atemfreq', 'etco2', 'herzfreq', 'rrsys', 'rrdias', 'bz', 'temp'])->mapWithKeys(fn (string $k) => [$k => FieldSchema::field($k)['max'] ?? null])->all(),
        ]);
    }

    /** @return list<array{value: string, hint: string}> identifier (the stored label, legacy format) + name */
    private static function vehicleSuggestions(int $service): array
    {
        return Vehicle::query()->active()->where('service', $service)->orderBy('sort_order')->orderBy('identifier')->get(['identifier', 'name'])
            ->map(fn (Vehicle $v) => ['value' => $v->identifier, 'hint' => $v->name])->values()->all();
    }

    /** DELETE /enotf/p/{enr}: crew withdraws ("löschen" in the legacy UI); the row stays (never a hard delete). */
    public function destroy(Request $request, EnotfProtocol $protocol, ProtocolLifecycle $lifecycle): RedirectResponse
    {
        $this->authorize('withdraw', $protocol);
        abort_unless($lifecycle->withdraw($protocol, $request->user()), 409);

        return redirect('/enotf/overview')->with('status', 'enotf.protocol.withdrawn')->with('status_params', ['enr' => $protocol->enr]);
    }

    /**
     * PATCH /enotf/p/{enr} {revision, fields: {key: value}} (autosave, JSON). Keys from the FieldSchema allowlist only, typed
     * there; merged field-wise under the row lock (ProtocolMerger). 409 with the current state once the protocol is not open.
     */
    public function update(Request $request, EnotfProtocol $protocol, ProtocolMerger $merger): JsonResponse
    {
        $this->authorize('view', $protocol);
        abort_if(strlen($request->getContent()) > self::MAX_PATCH_BYTES, 413);
        $in = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'fields' => ['required', 'array', 'min:1']]);
        $checked = FieldSchema::validate($in['fields']);
        if ($checked['errors'] !== []) {
            throw ValidationException::withMessages(collect($checked['errors'])->mapWithKeys(fn ($m, $k) => ["fields.{$k}" => $m])->all());
        }

        return $this->merge($request, $protocol, $merger, (int) $in['revision'], $checked['values']);
    }

    /**
     * PUT /enotf/p/{enr}/address/{kind} {revision, address?, poi_id?} (kind transport|target): the scene or destination address as
     * validated JSON (transp_adresse / ziel_adresse), through the same revisioned merge as the autosave. For `target`, `poi_id`
     * picks an active POI (its address and name are copied, destination_poi_id set, the catalog code cleared); `poi_id: null`
     * drops the POI again.
     */
    public function address(Request $request, EnotfProtocol $protocol, string $kind, ProtocolMerger $merger): JsonResponse
    {
        $this->authorize('view', $protocol);
        $target = $kind === 'target';
        $withPoi = $request->input('poi_id') !== null;
        $in = $request->validate([
            'revision' => ['required', 'integer', 'min:0'],
            'address' => ['nullable', 'array', $withPoi ? 'prohibited' : 'present'],
            'poi_id' => [$target ? 'nullable' : 'prohibited', 'integer', Rule::exists('pois', 'id')->where('is_active', true)],
        ]);
        $poi = $withPoi ? Poi::query()->findOrFail($in['poi_id']) : null;
        $address = $poi !== null
            ? ['strasse' => $poi->street, 'hnr' => $poi->house_number, 'ort' => $poi->city, 'ortsteil' => $poi->district]
            : $in['address'];
        $fields = [($target ? 'ziel_adresse' : 'transp_adresse') => $address];
        $columns = [];
        if ($target && $request->has('poi_id')) {
            $fields += ['ziel_poi' => $poi?->name, 'transportziel' => null];
            $columns['destination_poi_id'] = $poi?->id;
        }
        $checked = FieldSchema::validate($fields);
        if ($checked['errors'] !== []) {
            throw ValidationException::withMessages(['address' => array_values($checked['errors'])]);
        }

        return $this->merge($request, $protocol, $merger, (int) $in['revision'], $checked['values'], $columns);
    }

    /**
     * The one write path of a protocol's fields: row lock, 409 with the current state once closed, policy `update`, field-wise merge.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $columns
     */
    private function merge(Request $request, EnotfProtocol $protocol, ProtocolMerger $merger, int $revision, array $values, array $columns = []): JsonResponse
    {
        return DB::transaction(function () use ($protocol, $revision, $values, $columns, $merger, $request) {
            $locked = EnotfProtocol::query()->whereKey($protocol->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                return response()->json([
                    'message' => __('enotf.protocol.closed'),
                    'status' => $locked->status->value,
                    'revision' => $locked->revision,
                    'values' => ProtocolMerger::values($locked),
                ], 409);
            }
            abort_unless($request->user()->can('update', $locked), 403);

            return response()->json($merger->apply($locked, $revision, $values, $columns));
        });
    }

    /** GET /enotf/p/{enr}/plausibility: open required entries grouped by page section (crew of the protocol and QM). */
    public function plausibility(EnotfProtocol $protocol): JsonResponse
    {
        $this->authorize('view', $protocol);
        $sections = [];
        foreach (ConditionsService::evaluate(ProtocolValues::from($protocol)) as $v) {
            $sections[$v['section']][] = $v + ['message' => __($v['message_key'])];
        }

        return response()->json(['count' => array_sum(array_map('count', $sections)), 'sections' => (object) $sections]);
    }

    /**
     * GET /enotf/p/{enr}/changes?since=N (device sync): the fields written after revision N with their current
     * value, plus the status; another device's release or withdrawal switches the page to read only.
     */
    public function changes(Request $request, EnotfProtocol $protocol): JsonResponse
    {
        $this->authorize('view', $protocol);
        $since = (int) $request->validate(['since' => ['required', 'integer', 'min:0']])['since'];
        $keys = array_filter($protocol->field_revisions ?? [], fn (int $at) => $at > $since);
        $changes = array_intersect_key(ProtocolMerger::values($protocol), $keys);
        if (array_intersect_key($keys, array_flip(ProtocolMerger::DESTINATION_KEYS)) !== []) {
            $changes['destination_poi_id'] = $protocol->destination_poi_id;
        }

        return response()->json([
            'revision' => $protocol->revision,
            'status' => $protocol->isOpen() ? 'open' : ($protocol->hidden_at !== null ? 'hidden' : $protocol->status->value),
            'released' => $protocol->status === ProtocolStatus::Released,
            'changes_since' => (object) $changes,
        ]);
    }

    /**
     * POST /enotf/p/{enr}/patient-sync: the crew hands the patient data to dispatch (legacy pat_synced 2 = waits for EMD).
     * none -> pending once, needs a patient name; pending/synced stay (idempotent). One conditional UPDATE, logged without patient data.
     */
    public function patientSync(Request $request, EnotfProtocol $protocol): JsonResponse
    {
        $this->authorize('update', $protocol);
        abort_unless($protocol->isOpen(), 409, __('enotf.protocol.closed'));
        if ($protocol->patient_sync === PatientSync::None && $protocol->patient_first_name === null && $protocol->patient_last_name === null) {
            throw ValidationException::withMessages(['patient_sync' => __('enotf.patient_sync.no_name')]);
        }
        $changed = EnotfProtocol::query()->whereKey($protocol->id)->where('status', ProtocolStatus::Open)->whereNull('hidden_at')
            ->where('patient_sync', PatientSync::None)->update(['patient_sync' => PatientSync::Pending]);
        if ($changed > 0) {
            AuditLogger::log($request->user(), 'Patientendaten an Leitstelle gemeldet', $protocol->enr, 'eNOTF', context: ['protocol_id' => $protocol->id]);
        }

        return response()->json(['patient_sync' => $protocol->fresh()?->patient_sync->value]);
    }

    /** GET /enotf/create */
    public function create(): Response
    {
        $this->authorize('create', EnotfProtocol::class);

        return Inertia::render('Enotf/Create');
    }

    /**
     * POST /enotf/protocols {enr, prot_by, force} (JSON; the ENR is usually the dispatch mission number).
     * 1 ENR exists, own vehicle in its slot, still open -> back to it.
     * 3 ENR exists, own slot free, open, origin dispatch, and the own vehicle has a dispatch protocol of the same base
     *   ENR (it stands in that EMD dispatch) -> own vehicle and crew attached, logged.
     * 4 ENR new -> created (origin manual).
     * Everything else is a 409 and never overwrites; `force` then creates the next free ENR with suffix _n (case 2).
     * The unique index on enr is the lock: a lost race is a validation error, not a 500.
     */
    public function store(Request $request, ProtocolCreator $creator): JsonResponse
    {
        $this->authorize('create', EnotfProtocol::class);
        $in = $request->validate([
            'enr' => ['required', 'string', 'max:64', 'regex:/^[0-9_]+$/D'],
            'prot_by' => ['required', 'integer', 'in:0,1'],
            'force' => ['boolean'],
        ]);
        $user = $request->user();
        $vehicle = CrewSession::for('enotf')->vehicle() ?? abort(403);
        $slot = ProtocolCreator::slot($vehicle);
        $column = "vehicle_{$slot}_id";

        try {
            return DB::transaction(function () use ($in, $user, $vehicle, $slot, $column, $request, $creator) {
                $enr = $in['enr'];
                $existing = EnotfProtocol::query()->where('enr', $enr)->lockForUpdate()->first();
                if ($existing === null) {
                    return $this->created($creator->create($enr, (int) $in['prot_by'], $user, $vehicle));
                }
                if ($existing->isOpen() && $existing->getAttribute($column) === $vehicle->id) {
                    return $this->open($existing);
                }
                if ($existing->isOpen() && $existing->getAttribute($column) === null && $this->sameDispatch($existing, $vehicle)) {
                    $existing->forceFill([$column => $vehicle->id, "vehicle_{$slot}_label" => $vehicle->identifier])->save();
                    $creator->copyCrew($existing, $user, $slot);
                    AuditLogger::log($user, 'Fahrzeug an Protokoll angehängt', $existing->enr, 'eNOTF', context: ['protocol_id' => $existing->id, 'vehicle_id' => $vehicle->id]);

                    return $this->open($existing);
                }
                if (! $request->boolean('force')) {
                    $other = $existing->isOpen() ? Vehicle::query()->find($existing->getAttribute($column)) : null;

                    return response()->json([
                        'message' => $other !== null
                            ? __('enotf.create.conflict_vehicle', ['enr' => $enr, 'name' => $other->identifier])
                            : __('enotf.create.conflict_closed', ['enr' => $enr]),
                        'forceable' => true,
                    ], 409);
                }

                return $this->created($creator->create($creator->nextFreeEnr($enr), (int) $in['prot_by'], $user, $vehicle));
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['enr' => __('enotf.create.enr_taken')]);
        }
    }

    /** POST /enotf/protocols/withdraw-all: every open protocol of the vehicle the crew may withdraw (never dispatch ones). */
    public function withdrawAll(Request $request, ProtocolLifecycle $lifecycle): RedirectResponse
    {
        $this->authorize('viewAny', EnotfProtocol::class);
        $vehicle = CrewSession::for('enotf')->vehicle() ?? abort(403);
        $count = 0;
        foreach (EnotfProtocol::query()->openForVehicle($vehicle->id)->get() as $protocol) {
            if ($request->user()->can('withdraw', $protocol) && $lifecycle->withdraw($protocol, $request->user())) {
                $count++;
            }
        }

        return redirect('/enotf/overview')->with('status', 'enotf.overview.withdrawn')->with('status_params', ['count' => $count]);
    }

    private function sameDispatch(EnotfProtocol $protocol, Vehicle $vehicle): bool
    {
        $base = explode('_', $protocol->enr)[0];

        return $protocol->origin === ProtocolOrigin::Dispatch && EnotfProtocol::query()->where('origin', ProtocolOrigin::Dispatch)
            ->where(fn ($q) => $q->where('vehicle_transport_id', $vehicle->id)->orWhere('vehicle_na_id', $vehicle->id))
            ->where(fn ($q) => $q->where('enr', $base)->orWhere('enr', 'like', $base.'_%'))->pluck('enr')
            ->contains(fn (string $enr) => DispatchProtocols::isOfBase($enr, $base));
    }

    private function created(EnotfProtocol $protocol): JsonResponse
    {
        return response()->json(['enr' => $protocol->enr, 'url' => $this->url($protocol)], 201);
    }

    private function open(EnotfProtocol $protocol): JsonResponse
    {
        return response()->json(['enr' => $protocol->enr, 'url' => $this->url($protocol)]);
    }

    private function url(EnotfProtocol $protocol): string
    {
        return '/enotf/p/'.rawurlencode($protocol->enr);
    }
}
