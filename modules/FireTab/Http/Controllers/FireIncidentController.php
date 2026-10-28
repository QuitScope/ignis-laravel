<?php

namespace Modules\FireTab\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Personnel;
use App\Models\Vehicle;
use App\Modules\ModuleRegistry;
use App\Support\Settings;
use App\Support\SqlLike;
use App\Vehicles\CrewSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Federation\Support\FederatedPersonSearch;
use Modules\FireTab\Enums\AsuRole;
use Modules\FireTab\Http\Requests\IncidentRequest;
use Modules\FireTab\Http\Requests\ReportRequest;
use Modules\FireTab\Models\AsuEvent;
use Modules\FireTab\Models\AsuMember;
use Modules\FireTab\Models\AsuProtocol;
use Modules\FireTab\Models\AsuTrupp;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireIncidentVehicle;
use Modules\FireTab\Support\AsuState;
use Modules\FireTab\Support\FireLog;
use Modules\FireTab\Support\OrganizationTime;

/** The crew's incidents: list, create, detail with tabs, master data and report. Every route also carries `firetab.crew`. */
class FireIncidentController extends Controller
{
    public const TABS = ['stammdaten', 'bericht', 'fahrzeuge', 'lagemeldungen', 'atemschutz', 'lagekarte', 'abschluss', 'log'];

    /** GET /firetab/list: open incidents the crew's vehicle is assigned to. */
    public function list(): Response
    {
        $this->authorize('viewAny', FireIncident::class);
        $crew = CrewSession::for('firetab');

        $incidents = FireIncident::query()->whereNull('finalized_at')->whereNull('archived_at')
            ->whereHas('vehicles', fn ($q) => $q->where('vehicle_id', $crew->current()['vehicle_id'] ?? 0))
            ->withCount(['vehicles', 'sitreps'])->orderByDesc('started_at')->orderByDesc('id')->limit(100)->get();
        $vehicle = $crew->vehicle();

        return Inertia::render('FireTab/List', [
            'logbook' => ModuleRegistry::active('logbook'),
            'vehicle' => $vehicle === null ? null : ['id' => $vehicle->id, 'identifier' => $vehicle->identifier, 'name' => $vehicle->name],
            'incidents' => $incidents->map(fn (FireIncident $i) => [
                'id' => $i->id, 'number' => $i->number, 'location' => $i->location, 'keyword' => $i->keyword,
                'started_at' => OrganizationTime::display($i->started_at),
                'vehicles_count' => $i->vehicles_count, 'sitreps_count' => $i->sitreps_count,
            ])->all(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', FireIncident::class);
        $now = OrganizationTime::local(now());
        $me = Personnel::query()->find($request->user()->personnel_id);

        return Inertia::render('FireTab/Create', [
            'defaults' => ['started_date' => $now->format('Y-m-d'), 'started_time' => $now->format('H:i')],
            'leader' => $me === null ? null : ['id' => $me->id, 'name' => $me->displayName()],
        ]);
    }

    public function store(IncidentRequest $request): RedirectResponse
    {
        $data = $request->columns();
        $vehicle = Vehicle::query()->findOrFail(CrewSession::for('firetab')->current()['vehicle_id'] ?? 0);

        $incident = $this->guardNumber(fn () => DB::transaction(function () use ($data, $vehicle, $request) {
            $incident = (new FireIncident)->forceFill([...$data, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
            $incident->save();
            self::attach($incident, $vehicle);
            FireLog::record($incident, 'created', $incident->number);

            return $incident;
        }));

        return redirect("/firetab/incidents/{$incident->id}");
    }

    /** GET /firetab/incidents/{incident}?tab= */
    public function show(Request $request, FireIncident $incident): Response
    {
        $this->authorize('view', $incident);
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'stammdaten';
        $local = OrganizationTime::local($incident->started_at);

        $vehicles = $incident->vehicles()->orderBy('id')->get(['id', 'vehicle_id', 'name', 'identifier', 'radio_name', 'is_external']);
        $log = $tab !== 'log' ? [] : self::logRows($incident);
        $sitreps = $tab !== 'lagemeldungen' ? [] : self::sitrepRows($incident);
        $protocols = $tab !== 'bericht' ? [] : $incident->asuProtocols()->orderBy('id')->get()->map(fn (AsuProtocol $p) => [
            'id' => $p->id, 'supervisor' => $p->supervisor, 'location' => $p->mission_location, 'date' => $p->mission_date?->format('d.m.Y'),
            'trupps' => collect(array_values((array) ($p->payload['trupps'] ?? [])))->map(fn (mixed $t) => self::plain(is_array($t) ? ($t['name'] ?? $t['truppName'] ?? $t['id'] ?? null) : $t))->filter()->values()->all(),
        ])->all();

        return Inertia::render('FireTab/Show', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'incident' => [
                'id' => $incident->id, 'number' => $incident->number, 'location' => $incident->location, 'keyword' => $incident->keyword,
                'started_date' => $local?->format('Y-m-d'), 'started_time' => $local?->format('H:i'), 'started_at' => OrganizationTime::display($incident->started_at),
                'leader_personnel_id' => $incident->leader_personnel_id ?? ($incident->leader_external_name !== null ? 'external' : null), 'leader_name' => $incident->leaderName(),
                'caller_name' => $incident->caller_name, 'caller_contact' => $incident->caller_contact,
                'owner_name' => $incident->owner_name, 'owner_contact' => $incident->owner_contact, 'notes' => $incident->notes,
                'finalized' => $incident->finalized_at !== null, 'archived' => $incident->archived_at !== null,
            ],
            'vehicles' => $vehicles->map(fn (FireIncidentVehicle $v) => ['id' => $v->id, 'name' => $v->name, 'identifier' => $v->identifier, 'radio_name' => $v->radio_name, 'is_external' => $v->is_external])->all(),
            'sitreps' => $sitreps,
            'protocols' => $protocols,
            'registration' => $tab !== 'bericht' ? [] : self::asuRegistration($incident),
            'log' => $log,
            'missing' => array_keys(array_filter(['location' => $incident->location, 'keyword' => $incident->keyword, 'started_at' => $incident->started_at, 'leader' => $incident->leader_personnel_id ?? $incident->leader_external_name], fn ($v) => blank($v))),
            'can' => ['write' => $request->user()->can('update', $incident)],
            'ownVehicleId' => CrewSession::for('firetab')->current()['vehicle_id'] ?? null,
            'asu' => self::asuProps($incident->id, "/firetab/incidents/{$incident->id}/asu"),
        ]);
    }

    /**
     * Registrierung FwDV 7, 7.4 for the report (legacy bericht.php): per Trupp the first valid times and pressures,
     * read from AsuState (voided events ignored there), times as HH:MM in the organisation zone.
     *
     * @return list<array<string, mixed>>
     */
    public static function asuRegistration(FireIncident $incident): array
    {
        $events = $incident->asuEvents()->orderBy('id')->get()->groupBy('trupp_id');
        $time = fn (int|float|null $ms) => $ms === null ? null : OrganizationTime::local(Carbon::createFromTimestampMs($ms))?->format('H:i');

        return $incident->asuTrupps()->with(['members' => fn ($q) => $q->orderBy('position')])->orderBy('id')->get()
            ->map(function (AsuTrupp $t) use ($events, $time) {
                $own = ($events[$t->id] ?? collect())->map(fn (AsuEvent $e) => [
                    'id' => $e->id, 'type' => $e->type->value, 'at' => $e->occurred_at, 'member_id' => $e->member_id, 'bar' => $e->bar, 'note' => $e->note, 'voided' => $e->voided_at !== null,
                ])->values()->all();
                $s = AsuState::derive(['expected_minutes' => $t->expected_minutes, 'role' => $t->role->value], $own, now());
                $acks = array_column($s['asks'], 'ackAt', 'type');
                $mayday = collect($own)->filter(fn (array $e) => $e['type'] === 'mayday' && ! $e['voided'])->map(fn (array $e) => $e['at']->getTimestampMs())->min();

                return [
                    'id' => $t->id, 'name' => $t->name, 'safety' => $t->role === AsuRole::Sicherheit,
                    'members' => $t->members->map(fn (AsuMember $m) => $m->name)->all(),
                    'angeschlossen' => $time($s['startAt']), 'start_bar' => $s['startBar'],
                    'hinweis_13' => $time($acks['hinweis_13'] ?? null), 'hinweis_23' => $time($acks['hinweis_23'] ?? null),
                    'ziel' => $time($s['zielAt']), 'ziel_bar' => $s['zielBar'],
                    'rueckzug' => $time($s['rueckzugAt']), 'draussen' => $time($s['endAt']),
                    'minutes' => $s['startAt'] !== null && $s['endAt'] !== null ? (int) round(($s['endAt'] - $s['startAt']) / 60000) : null,
                    'mayday' => $time($mayday),
                ];
            })->all();
    }

    private static function plain(mixed $v): string
    {
        return is_scalar($v) ? mb_substr((string) $v, 0, 100) : '';
    }

    /** @return array{url: string, config: array{consumption: int, start_bar: int}} for the Atemschutz board (writing goes to `url`/events etc.) */
    public static function asuProps(int $incidentId, string $url): array
    {
        $settings = app(Settings::class);

        return ['url' => $url, 'config' => ['consumption' => (int) $settings->get('firetab.asu_consumption'), 'start_bar' => (int) $settings->get('firetab.asu_start_bar')]];
    }

    /** @return list<array<string, mixed>> */
    public static function logRows(FireIncident $incident): array
    {
        return DB::table('fire_incident_log')->where('fire_incident_id', $incident->id)
            ->leftJoin('personnel', 'personnel.id', '=', 'fire_incident_log.personnel_id')
            ->leftJoin('vehicles', 'vehicles.id', '=', 'fire_incident_log.vehicle_id')
            ->orderByDesc('fire_incident_log.id')->limit(200)
            ->get(['fire_incident_log.action', 'fire_incident_log.description', 'fire_incident_log.created_at', 'personnel.full_name as person', 'vehicles.name as vehicle'])
            ->map(fn ($l) => ['action' => $l->action, 'description' => $l->description, 'at' => OrganizationTime::display(Carbon::parse($l->created_at, 'UTC')), 'person' => $l->person, 'vehicle' => $l->vehicle])->all();
    }

    /** @return list<array<string, mixed>> */
    public static function sitrepRows(FireIncident $incident): array
    {
        return $incident->sitreps()->orderByDesc('reported_at')->orderByDesc('id')->get()
            ->map(fn ($s) => ['id' => $s->id, 'at' => OrganizationTime::display($s->reported_at), 'text' => $s->text, 'reporter' => $s->reporter_name, 'source' => $s->source->value])->all();
    }

    public function update(IncidentRequest $request, FireIncident $incident): RedirectResponse
    {
        $this->guardNumber(fn () => DB::transaction(function () use ($request, $incident) {
            $incident->forceFill([...$request->columns(), 'updated_by' => $request->user()->id])->save();
            FireLog::record($incident, 'updated');
        }));

        return back();
    }

    public function report(ReportRequest $request, FireIncident $incident): RedirectResponse
    {
        DB::transaction(function () use ($request, $incident) {
            $incident->forceFill(['notes' => $request->validated('notes'), 'updated_by' => $request->user()->id])->save();
            FireLog::record($incident, 'report_updated');
        });

        return back();
    }

    /** GET /firetab/personnel/search?q= (leader picker): at most 20 local hits, plus up to 10 federation hits while that module is on. */
    public function personnel(Request $request): JsonResponse
    {
        $this->authorize('create', FireIncident::class);
        $q = $request->query('q', '');
        abort_unless(is_string($q), 422);
        $q = trim($q);
        $hits = [];
        if ($q !== '' && mb_strlen($q) <= 50) {
            $query = Personnel::query()->select(['id', 'full_name']);
            SqlLike::anyOf($query, ['full_name'], $q);
            $hits = $query->orderBy('full_name')->limit(20)->get()->map(fn (Personnel $p) => ['id' => $p->id, 'name' => $p->full_name])->all();
            if (ModuleRegistry::active('federation')) {
                $hits = [...$hits, ...FederatedPersonSearch::search($q, 10)];
            }
        }

        return response()->json($hits);
    }

    private function guardNumber(callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['number' => __('validation.unique', ['attribute' => 'number'])]);
        }
    }

    public static function attach(FireIncident $incident, Vehicle $vehicle): void
    {
        (new FireIncidentVehicle)->forceFill([
            'fire_incident_id' => $incident->id, 'vehicle_id' => $vehicle->id, 'name' => $vehicle->name,
            'identifier' => $vehicle->identifier, 'radio_name' => $vehicle->name, 'is_external' => false,
        ])->save();
    }
}
