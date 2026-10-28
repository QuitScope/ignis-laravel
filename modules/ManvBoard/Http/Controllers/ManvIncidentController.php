<?php

namespace Modules\ManvBoard\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Personnel;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\SqlLike;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ManvBoard\Enums\ManvIncidentStatus;
use Modules\ManvBoard\Http\Requests\StoreIncidentRequest;
use Modules\ManvBoard\Http\Requests\UpdateIncidentRequest;
use Modules\ManvBoard\Models\ManvIncident;
use Modules\ManvBoard\Models\ManvLogEntry;
use Modules\ManvBoard\Policies\ManvIncidentPolicy;
use Modules\ManvBoard\Support\IncidentStats;
use Modules\ManvBoard\Support\ManvLog;

/** Lagen: list, create/edit, action log. Every action is a policy-checked web route. */
class ManvIncidentController extends Controller
{
    private const MODULE = 'MANV';

    private const FIELDS = ['incident_number', 'location', 'reason', 'notes'];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ManvIncident::class);
        $filter = $request->validate(['status' => ['nullable', 'string', Rule::in([...array_column(ManvIncidentStatus::cases(), 'value'), 'all'])]])['status'] ?? 'active';

        $incidents = ManvIncident::query()->when($filter !== 'all', fn ($q) => $q->where('status', $filter))
            ->orderByDesc('started_at')->orderByDesc('id')->paginate(25)->withQueryString();
        $counts = IncidentStats::counts($incidents->getCollection()->pluck('id')->all());

        return Inertia::render('ManvBoard/Index', [
            'incidents' => $incidents->through(fn (ManvIncident $i) => [
                'id' => $i->id,
                'incident_number' => $i->incident_number,
                'location' => $i->location,
                'status' => $i->status->value,
                'started_at' => $this->display($i->started_at),
                'ended_at' => $this->display($i->ended_at),
                'lna_name' => $i->lna_name,
                'orgl_name' => $i->orgl_name,
                'counts' => $counts[$i->id],
            ]),
            'filter' => $filter,
            'statuses' => array_column(ManvIncidentStatus::cases(), 'value'),
            'can' => ['manage' => $request->user()?->can('create', ManvIncident::class) === true],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', ManvIncident::class);

        return Inertia::render('ManvBoard/Create');
    }

    public function personnel(Request $request): JsonResponse
    {
        $this->authorize('create', ManvIncident::class);
        $q = $request->query('q');
        $q = is_string($q) ? mb_substr(trim($q), 0, 100) : '';

        return response()->json(Personnel::query()->active()
            ->when($q !== '', fn ($query) => SqlLike::anyOf($query, ['full_name', 'service_number'], $q))
            ->orderBy('full_name')->orderBy('id')->limit(20)->get(['id', 'full_name', 'service_number'])
            ->map(fn (Personnel $p) => ['id' => $p->id, 'full_name' => $p->full_name, 'service_number' => $p->service_number])->all());
    }

    public function store(StoreIncidentRequest $request): RedirectResponse
    {
        $incident = new ManvIncident;
        $incident->forceFill($request->safe()->only(self::FIELDS) + [
            'started_at' => $this->start($request->validated('started_at')) ?? now(),
            'status' => ManvIncidentStatus::Active,
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ] + $this->people($request));
        $incident->save();

        ManvLog::record($incident, 'lage_erstellt', trans('manvboard.log.lage_erstellt', ['number' => $incident->incident_number]));
        $this->audit($request->user(), 'Lage erstellt', $incident);

        return redirect("/mci/{$incident->id}")->with('status', 'manvboard.saved');
    }

    public function edit(ManvIncident $incident): Response
    {
        $this->authorize('update', $incident);

        return Inertia::render('ManvBoard/Edit', ['incident' => [
            'id' => $incident->id,
            'incident_number' => $incident->incident_number,
            'location' => $incident->location,
            'reason' => $incident->reason,
            'notes' => $incident->notes,
            'status' => $incident->status->value,
            'started_at' => $incident->started_at->copy()->timezone(config('ignis.timezone'))->format('Y-m-d\TH:i'),
            'lna' => $incident->lna_name === null ? null : ['id' => $incident->lna_personnel_id, 'name' => $incident->lna_name],
            'orgl' => $incident->orgl_name === null ? null : ['id' => $incident->orgl_personnel_id, 'name' => $incident->orgl_name],
            'writable' => (new ManvIncidentPolicy)->isWritable($incident),
        ], 'statuses' => array_column(ManvIncidentStatus::cases(), 'value')]);
    }

    public function update(UpdateIncidentRequest $request, ManvIncident $incident): RedirectResponse
    {
        $user = $request->user();
        $old = $incident->status;

        if ((new ManvIncidentPolicy)->isWritable($incident)) {
            $incident->forceFill($request->safe()->only(self::FIELDS));
            if ($request->filled('started_at')) {
                $incident->started_at = $this->start($request->validated('started_at'));
            }
            $incident->forceFill($this->people($request, $incident));
        }
        $changed = $incident->isDirty(['incident_number', 'location', 'reason', 'notes', 'started_at', 'lna_personnel_id', 'orgl_personnel_id']);

        $new = ManvIncidentStatus::tryFrom((string) $request->input('status')) ?? $old;
        if ($new !== $old) {
            $incident->status = $new;
            $incident->ended_at = match (true) {
                $new === ManvIncidentStatus::Active => null,
                $old === ManvIncidentStatus::Active => now(),
                default => $incident->ended_at,
            };
        }
        $incident->updated_by = $user?->id;
        $incident->save();

        if ($new !== $old) {
            $action = match ($new) {
                ManvIncidentStatus::Closed => 'lage_abgeschlossen',
                ManvIncidentStatus::Archived => 'lage_archiviert',
                ManvIncidentStatus::Active => 'lage_reaktiviert',
            };
            ManvLog::record($incident, $action, trans('manvboard.log.'.$action, ['number' => $incident->incident_number]));
        }
        if ($changed) {
            ManvLog::record($incident, 'lage_bearbeitet', trans('manvboard.log.lage_bearbeitet', ['number' => $incident->incident_number]));
        }
        $this->audit($user, 'Lage bearbeitet', $incident);

        return redirect('/mci')->with('status', 'manvboard.saved');
    }

    public function log(ManvIncident $incident): Response
    {
        $this->authorize('view', $incident);

        return Inertia::render('ManvBoard/Log', [
            'incident' => ['id' => $incident->id, 'incident_number' => $incident->incident_number, 'location' => $incident->location],
            'entries' => ManvLogEntry::query()->where('manv_incident_id', $incident->id)
                ->orderByDesc('occurred_at')->orderByDesc('id')->paginate(50)->withQueryString()
                ->through(fn (ManvLogEntry $e) => [
                    'id' => $e->id,
                    'occurred_at' => $this->display($e->occurred_at),
                    'action' => $e->action,
                    'description' => $e->description,
                    'user_name' => $e->user_name,
                ]),
        ]);
    }

    /**
     * LNA/OrgL: the personnel file is the truth, the name a snapshot set here. On update, a missing key keeps the value.
     *
     * @return array<string, int|string|null>
     */
    private function people(Request $request, ?ManvIncident $incident = null): array
    {
        $out = [];
        foreach (['lna', 'orgl'] as $role) {
            if ($incident !== null && ! $request->exists($role.'_personnel_id')) {
                continue;
            }
            $id = $request->integer($role.'_personnel_id') ?: null;
            $out[$role.'_personnel_id'] = $id;
            if ($incident !== null && $id !== null && $id === $incident->getAttribute($role.'_personnel_id')) {
                continue;
            }
            $out[$role.'_name'] = $id === null ? null : Personnel::query()->whereKey($id)->value('full_name');
        }

        return $out;
    }

    private function start(mixed $value): ?Carbon
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value, config('ignis.timezone'))->utc() : null;
    }

    private function display(?CarbonInterface $at): ?string
    {
        return $at?->copy()->timezone(config('ignis.timezone'))->format('d.m.Y H:i');
    }

    private function audit(?User $user, string $action, ManvIncident $incident): void
    {
        AuditLogger::log($user, $action, 'ID: '.$incident->id.', Nr: '.$incident->incident_number, self::MODULE, true, ['id' => $incident->id]);
    }
}
