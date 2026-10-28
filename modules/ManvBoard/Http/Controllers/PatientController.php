<?php

namespace Modules\ManvBoard\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Poi;
use App\Models\User;
use App\Support\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ManvBoard\Enums\ResourceType;
use Modules\ManvBoard\Enums\Sex;
use Modules\ManvBoard\Enums\Triage;
use Modules\ManvBoard\Http\Requests\DepartureRequest;
use Modules\ManvBoard\Http\Requests\StorePatientRequest;
use Modules\ManvBoard\Http\Requests\TriageRequest;
use Modules\ManvBoard\Http\Requests\UpdatePatientRequest;
use Modules\ManvBoard\Models\ManvIncident;
use Modules\ManvBoard\Models\ManvPatient;
use Modules\ManvBoard\Models\ManvResource;
use Modules\ManvBoard\Policies\ManvIncidentPolicy;
use Modules\ManvBoard\Support\ManvLog;
use Modules\ManvBoard\Support\PatientNumber;
use Modules\ManvBoard\Support\TransportRules;

/** Patients of a Lage (scoped binding): create, view/edit, quick triage, departure. Writes need manage and an active Lage. */
class PatientController extends Controller
{
    private const MODULE = 'MANV';

    private const FIELDS = ['last_name', 'first_name', 'birth_date', 'sex', 'injuries', 'notes'];

    public function create(Request $request, ManvIncident $incident): Response
    {
        abort_unless((new ManvIncidentPolicy)->canWrite($request->user(), $incident), 403);

        return Inertia::render('ManvBoard/PatientCreate', ['incident' => $this->incident($incident)] + $this->options($incident, null));
    }

    public function store(StorePatientRequest $request, ManvIncident $incident): RedirectResponse
    {
        $user = $request->user();
        try {
            $patient = $this->createPatient($request, $incident, $user);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['number' => trans('manvboard.number_taken')]);
        }
        $this->audit($user, 'Patient erstellt', $incident, $patient);

        return redirect("/mci/{$incident->id}/patients/{$patient->id}")->with('status', 'manvboard.patient_saved');
    }

    private function createPatient(StorePatientRequest $request, ManvIncident $incident, ?User $user): ManvPatient
    {
        return DB::transaction(function () use ($request, $incident, $user) {
            $patient = new ManvPatient;
            $patient->forceFill($request->safe()->only(self::FIELDS) + [
                'manv_incident_id' => $incident->id,
                'number' => PatientNumber::next($incident),
                'created_by' => $user?->id,
                'updated_by' => $user?->id,
            ]);
            $this->triage($patient, $request->enum('triage', Triage::class), $user);
            $this->assign($patient, $incident, $request);
            $patient->save();
            ManvLog::record($incident, 'patient_erstellt', trans('manvboard.log.patient_erstellt', ['number' => $patient->number]), $patient);

            return $patient;
        });
    }

    public function show(Request $request, ManvIncident $incident, ManvPatient $patient): Response
    {
        $this->authorize('view', $incident);
        $patient->load('resource.vehicle:id,service');

        return Inertia::render('ManvBoard/PatientShow', [
            'incident' => $this->incident($incident),
            'patient' => [
                'id' => $patient->id,
                'number' => $patient->number,
                'last_name' => $patient->last_name,
                'first_name' => $patient->first_name,
                'birth_date' => $patient->birth_date?->format('Y-m-d'),
                'sex' => $patient->sex->value,
                'triage' => $patient->triage?->value,
                'triaged_at' => $this->display($patient->triaged_at),
                'manv_resource_id' => $patient->manv_resource_id,
                'vehicle' => $patient->resource->label ?? $patient->callsign,
                'destination' => $patient->destination,
                'destination_poi_id' => $patient->destination_poi_id,
                'injuries' => $patient->injuries,
                'notes' => $patient->notes,
                'departed_at' => $this->display($patient->departed_at),
                'can_depart' => TransportRules::canDepart($patient),
            ],
            'can' => ['write' => (new ManvIncidentPolicy)->canWrite($request->user(), $incident)],
        ] + $this->options($incident, $patient));
    }

    public function update(UpdatePatientRequest $request, ManvIncident $incident, ManvPatient $patient): RedirectResponse
    {
        $user = $request->user();
        DB::transaction(function () use ($request, $incident, $patient, $user) {
            $p = ManvPatient::query()->whereKey($patient->id)->lockForUpdate()->firstOrFail();
            $old = $p->triage;
            $triage = $request->enum('triage', Triage::class);
            if ($p->departed_at !== null) {
                $this->assertFrozen($p, $request, $triage);
            }
            $p->forceFill($request->safe()->only(self::FIELDS) + ['updated_by' => $user?->id]);
            $triaged = $this->triage($p, $triage, $user);
            if ($p->departed_at === null) {
                $this->assign($p, $incident, $request);
            }
            $p->save();
            if ($triaged) {
                $this->logTriage($incident, $p, $old);
            }
            ManvLog::record($incident, 'patient_aktualisiert', trans('manvboard.log.patient_aktualisiert', ['number' => $p->number]), $p);
        });
        $this->audit($user, 'Patient bearbeitet', $incident, $patient);

        return redirect("/mci/{$incident->id}/patients/{$patient->id}")->with('status', 'manvboard.patient_saved');
    }

    public function triageUpdate(TriageRequest $request, ManvIncident $incident, ManvPatient $patient): RedirectResponse
    {
        DB::transaction(function () use ($request, $incident, $patient) {
            $p = ManvPatient::query()->whereKey($patient->id)->lockForUpdate()->firstOrFail();
            $old = $p->triage;
            $triage = $request->enum('triage', Triage::class);
            if ($p->departed_at !== null) {
                $this->assertFrozen($p, null, $triage);
            }
            if ($this->triage($p, $triage, $request->user())) {
                $p->updated_by = $request->user()?->id;
                $p->save();
                $this->logTriage($incident, $p, $old);
            }
        });

        return back();
    }

    private function assertFrozen(ManvPatient $patient, ?Request $request, ?Triage $triage): void
    {
        $errors = [];
        if ($triage !== null && $triage !== $patient->triage) {
            $errors['triage'] = trans('manvboard.departed_frozen');
        }
        if ($request !== null) {
            if (($request->integer('manv_resource_id') ?: null) !== $patient->manv_resource_id) {
                $errors['manv_resource_id'] = trans('manvboard.departed_frozen');
            }
            $poi = $request->integer('destination_poi_id') ?: null;
            $destination = $request->input('destination');
            if ($poi !== $patient->destination_poi_id) {
                $errors['destination_poi_id'] = trans('manvboard.departed_frozen');
            } elseif ($poi === null && (is_string($destination) && $destination !== '' ? $destination : null) !== $patient->destination) {
                $errors['destination'] = trans('manvboard.departed_frozen');
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function departure(DepartureRequest $request, ManvIncident $incident, ManvPatient $patient): RedirectResponse
    {
        DB::transaction(function () use ($request, $incident, $patient) {
            $p = ManvPatient::query()->whereKey($patient->id)->lockForUpdate()->firstOrFail();
            $resource = $p->manv_resource_id === null ? null : ManvResource::query()->whereKey($p->manv_resource_id)->lockForUpdate()->first();
            if ($p->departed_at !== null) {
                throw ValidationException::withMessages(['departure' => trans('manvboard.departure.already')]);
            }
            if (! TransportRules::canDepart($p)) {
                throw ValidationException::withMessages(['departure' => trans('manvboard.departure.not_allowed')]);
            }

            $p->forceFill(['departed_at' => now(), 'updated_by' => $request->user()?->id])->save();
            ManvLog::record($incident, 'patient_abfahrt', trans('manvboard.log.patient_abfahrt', ['number' => $p->number]), $p);

            $occupied = $resource !== null && ManvPatient::query()->where('manv_resource_id', $resource->id)
                ->whereNull('departed_at')->lockForUpdate()->value('id') !== null;
            if ($resource !== null && $resource->departed_at === null && ! $occupied) {
                $resource->forceFill(['departed_at' => now()])->save();
                ManvLog::record($incident, 'ressource_abgefahren', trans('manvboard.log.ressource_abgefahren', ['label' => $resource->label, 'number' => $p->number]), $resource);
            }
        });

        return back()->with('status', 'manvboard.departed');
    }

    private function triage(ManvPatient $patient, ?Triage $triage, ?User $user): bool
    {
        if ($triage === null || $triage === $patient->triage) {
            return false;
        }
        $patient->forceFill(['triage' => $triage, 'triaged_at' => now(), 'triaged_by' => $user?->id]);

        return true;
    }

    private function logTriage(ManvIncident $incident, ManvPatient $patient, ?Triage $old): void
    {
        ManvLog::record($incident, 'sichtung_geaendert', trans('manvboard.log.sichtung_geaendert', [
            'number' => $patient->number,
            'from' => $old->value ?? trans('manvboard.triage.none'),
            'to' => $patient->triage?->value,
        ]), $patient);
    }

    private function assign(ManvPatient $patient, ManvIncident $incident, Request $request): void
    {
        $id = $request->integer('manv_resource_id') ?: null;
        if ($id === null) {
            if ($patient->manv_resource_id !== null) {
                $patient->forceFill(['manv_resource_id' => null, 'vehicle_type' => null, 'callsign' => null, 'vehicle_location' => null]);
            }
        } elseif ($id !== $patient->manv_resource_id) {
            $resource = ManvResource::query()->whereKey($id)->where('manv_incident_id', $incident->id)
                ->where('type', ResourceType::Vehicle)->whereNull('departed_at')->lockForUpdate()->first();
            if ($resource === null) {
                throw ValidationException::withMessages(['manv_resource_id' => trans('validation.exists', ['attribute' => 'manv_resource_id'])]);
            }
            $busy = ManvPatient::query()->where('manv_resource_id', $id)->whereNull('departed_at')
                ->when($patient->exists, fn ($q) => $q->whereKeyNot($patient->id))->value('number');
            if ($busy !== null) {
                throw ValidationException::withMessages(['manv_resource_id' => trans('manvboard.vehicle_busy', ['label' => $resource->label, 'number' => $busy])]);
            }
            $patient->forceFill(['manv_resource_id' => $id, 'vehicle_type' => $resource->vehicle_type, 'callsign' => $resource->label, 'vehicle_location' => $resource->location]);
        }

        $poi = $request->integer('destination_poi_id') ?: null;
        if ($poi === null) {
            $destination = $request->input('destination');
            $patient->forceFill(['destination_poi_id' => null, 'destination' => is_string($destination) && $destination !== '' ? $destination : null]);
        } elseif ($poi !== $patient->destination_poi_id) {
            $patient->forceFill(['destination_poi_id' => $poi, 'destination' => Poi::query()->whereKey($poi)->value('name')]);
        }
    }

    /** @return array<string, mixed> */
    private function options(ManvIncident $incident, ?ManvPatient $patient): array
    {
        $own = $patient?->manv_resource_id;

        return [
            'vehicles' => $incident->resources()->where('type', ResourceType::Vehicle)
                ->where(fn ($q) => $q->where(fn ($q) => $q->whereNull('departed_at')->whereDoesntHave('patients', fn ($q) => $q->whereNull('departed_at')))
                    ->when($own !== null, fn ($q) => $q->orWhere('id', $own)))
                ->orderBy('label')->get()
                ->map(fn (ManvResource $r) => ['id' => $r->id, 'label' => $r->label, 'vehicle_type' => $r->vehicle_type, 'location' => $r->location])->all(),
            'hospitals' => Poi::hospitals()->orderBy('name')->get(['id', 'name', 'city'])
                ->map(fn (Poi $p) => ['id' => $p->id, 'name' => $p->name, 'city' => $p->city])->all(),
            'triages' => array_column(Triage::cases(), 'value'),
            'sexes' => array_column(Sex::cases(), 'value'),
            'no_transport' => TransportRules::NO_TRANSPORT,
        ];
    }

    /** @return array<string, mixed> */
    private function incident(ManvIncident $incident): array
    {
        return ['id' => $incident->id, 'incident_number' => $incident->incident_number, 'location' => $incident->location, 'status' => $incident->status->value];
    }

    private function display(?CarbonInterface $at): ?string
    {
        return $at?->copy()->timezone(config('ignis.timezone'))->format('d.m.Y H:i');
    }

    /** Audit detail carries the patient number only, never personal data. */
    private function audit(?User $user, string $action, ManvIncident $incident, ManvPatient $patient): void
    {
        AuditLogger::log($user, $action, 'Lage: '.$incident->incident_number.', Patient: '.$patient->number, self::MODULE, true, ['id' => $patient->id, 'incident_id' => $incident->id]);
    }
}
