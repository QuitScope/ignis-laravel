<?php

namespace Modules\FireTab\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuleRegistry;
use App\Support\AuditLogger;
use App\Support\SqlLike;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Federation\Models\FederatedIncident;
use Modules\FireTab\Enums\FireIncidentStatus;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Notifications\FireProtocolType;
use Modules\FireTab\Support\FireLog;
use Modules\FireTab\Support\OrganizationTime;

/** Quality management: crew-free (no firetab.crew), policy only. Protocols are archived, never deleted. */
class FireQmController extends Controller
{
    private const MODULE = 'Feuerwehr';

    private const SORTS = ['number', 'location', 'keyword', 'started_at', 'status', 'finalized_at'];

    public const EMPTY_FIELDS = ['number', 'location', 'keyword', 'leader', 'notes', 'no_vehicles'];

    private const TOKEN_TTL = 600;

    public function index(Request $request): Response
    {
        Gate::authorize('viewQm', FireIncident::class);
        $archived = $request->boolean('archived');
        $q = trim((string) $request->query('q', ''));
        $status = FireIncidentStatus::tryFrom((string) $request->query('status', ''));
        $sort = in_array($request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'started_at';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $rows = FireIncident::query()->with('leader:id,full_name,title_id')->withCount(['vehicles', 'sitreps'])
            ->when($archived, fn (Builder $b) => $b->whereNotNull('archived_at'), fn (Builder $b) => $b->whereNull('archived_at'))
            ->when($status, fn (Builder $b) => $b->where('status', $status->value))
            ->when($q !== '' && mb_strlen($q) <= 100, fn (Builder $b) => SqlLike::anyOf($b, ['number', 'location', 'keyword'], $q))
            ->orderBy($sort, $dir)->orderByDesc('id')->paginate(20)->withQueryString();

        $counts = FireIncident::query()->selectRaw('(archived_at is not null) as is_archived, count(*) as c')->groupBy('is_archived')->pluck('c', 'is_archived');

        return Inertia::render('FireTab/Qm/Index', [
            'incidents' => $rows->through(fn (FireIncident $i) => [
                'id' => $i->id, 'number' => $i->number, 'location' => $i->location, 'keyword' => $i->keyword,
                'started_at' => OrganizationTime::display($i->started_at), 'leader' => $i->leaderName(),
                'status' => $i->status->value, 'finalized' => $i->finalized_at !== null, 'archived' => $i->archived_at !== null,
                'vehicles_count' => $i->vehicles_count, 'sitreps_count' => $i->sitreps_count,
            ]),
            'filters' => ['archived' => $archived, 'q' => $q, 'status' => $status->value ?? '', 'sort' => $sort, 'dir' => $dir],
            'counts' => ['active' => (int) ($counts[0] ?? 0), 'archived' => (int) ($counts[1] ?? 0)],
            'statuses' => array_map(fn (FireIncidentStatus $s) => $s->value, FireIncidentStatus::cases()),
            'federated' => ModuleRegistry::active('federation') ? FederatedIncident::query()->count() : null,
        ]);
    }

    /** GET /firetab/qm/incidents/{incident}: read-only view of the whole protocol, no crew session needed. */
    public function show(FireIncident $incident): Response
    {
        $this->authorize('view', $incident);
        $incident->load('leader');

        return Inertia::render('FireTab/Qm/Show', [
            'incident' => [
                'id' => $incident->id, 'number' => $incident->number, 'location' => $incident->location, 'keyword' => $incident->keyword,
                'started_at' => OrganizationTime::display($incident->started_at), 'finalized_at' => OrganizationTime::display($incident->finalized_at),
                'leader_name' => $incident->leaderName(), 'status' => $incident->status->value,
                'caller_name' => $incident->caller_name, 'caller_contact' => $incident->caller_contact,
                'owner_name' => $incident->owner_name, 'owner_contact' => $incident->owner_contact, 'notes' => $incident->notes,
                'finalized' => $incident->finalized_at !== null, 'archived' => $incident->archived_at !== null,
            ],
            'vehicles' => $incident->vehicles()->orderBy('id')->get(['id', 'name', 'identifier', 'radio_name', 'is_external'])->all(),
            'sitreps' => FireIncidentController::sitrepRows($incident),
            'log' => FireIncidentController::logRows($incident),
            'asu' => FireIncidentController::asuProps($incident->id, "/firetab/qm/incidents/{$incident->id}/asu"),
            'statuses' => array_map(fn (FireIncidentStatus $s) => $s->value, FireIncidentStatus::cases()),
            'can' => ['review' => Gate::allows('review', $incident), 'archive' => Gate::allows('archive', $incident), 'reopen' => Gate::allows('reopen', $incident)],
        ]);
    }

    public function status(Request $request, FireIncident $incident): RedirectResponse
    {
        $this->authorize('review', $incident);
        $new = FireIncidentStatus::from($request->validate(['status' => ['required', Rule::enum(FireIncidentStatus::class)]])['status']);
        $old = $incident->status;

        $changed = DB::transaction(function () use ($request, $incident, &$old, $new) {
            $locked = FireIncident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            if ($locked->archived_at !== null) {
                throw ValidationException::withMessages(['status' => __('firetab.qm.archived_locked')]);
            }
            $old = $locked->status;
            if ($old === $new) {
                return false;
            }
            $locked->forceFill(['status' => $new, 'updated_by' => $request->user()->id])->save();
            FireLog::record($locked, 'status_changed', "{$old->value} -> {$new->value}");

            return true;
        });

        if ($changed) {
            AuditLogger::log($request->user(), 'Einsatzprotokoll-Status geändert', $this->label($incident)." {$old->value} -> {$new->value}", self::MODULE, false, ['fire_incident_id' => $incident->id]);
            if ($new !== FireIncidentStatus::Hidden) {
                FireProtocolType::notifyLeader($incident, __('firetab.notification.status', ['name' => $request->user()->displayName(), 'status' => __("firetab.status.{$new->value}")]), $this->label($incident), $request->user()->id);
            }
        }

        return back();
    }

    public function archive(Request $request, FireIncident $incident): RedirectResponse
    {
        $this->authorize('archive', $incident);
        $this->setArchived($request, $incident, true);

        return back();
    }

    public function unarchive(Request $request, FireIncident $incident): RedirectResponse
    {
        $this->authorize('archive', $incident);
        $this->setArchived($request, $incident, false);

        return back();
    }

    public function bulkArchive(Request $request): RedirectResponse
    {
        Gate::authorize('viewQm', FireIncident::class);
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer']])['ids'];

        $query = FireIncident::query()->whereIn('id', $ids)->whereNull('archived_at');
        $hit = $query->get();
        $n = $query->update($this->archivePatch($request));
        $hit->each(fn (FireIncident $i) => FireLog::record($i, 'archived'));
        AuditLogger::log($request->user(), 'Einsatzprotokolle archiviert', "$n Protokolle", self::MODULE, false, ['count' => $n]);

        return back();
    }

    public function reopen(Request $request, FireIncident $incident): RedirectResponse
    {
        $this->authorize('reopen', $incident);
        DB::transaction(function () use ($request, $incident) {
            $locked = FireIncident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->finalized_at === null, 409);
            if ($locked->archived_at !== null) {
                throw ValidationException::withMessages(['reopen' => __('firetab.qm.archived_locked')]);
            }
            $locked->forceFill(['finalized_at' => null, 'finalized_by' => null, 'status' => FireIncidentStatus::New, 'updated_by' => $request->user()->id])->save();
            FireLog::record($locked, 'reopened');
        });
        AuditLogger::log($request->user(), 'Einsatzprotokoll wieder geöffnet', $this->label($incident), self::MODULE, false, ['fire_incident_id' => $incident->id]);

        return back();
    }

    /** GET /firetab/qm/empty: the filter form. */
    public function emptyForm(): Response
    {
        Gate::authorize('viewQm', FireIncident::class);

        return Inertia::render('FireTab/Qm/EmptyPreview', ['fields' => self::EMPTY_FIELDS, 'filter' => null, 'ttl' => self::TOKEN_TTL, 'preview' => null]);
    }

    /** POST /firetab/qm/empty: without `token` a preview (issues the token), with it the execution. */
    public function emptyRun(Request $request): Response|RedirectResponse
    {
        Gate::authorize('viewQm', FireIncident::class);
        $f = $request->validate([
            'fields' => ['required', 'array', 'min:1'], 'fields.*' => ['string', Rule::in(self::EMPTY_FIELDS)],
            'days' => ['required', Rule::in(['7', '30', '90', '365', 'all'])],
            'state' => ['required', Rule::in(['all', 'open', 'finalized'])],
            'token' => ['nullable', 'string', 'size:40'],
        ]);
        $fields = array_values(array_unique($f['fields']));
        sort($fields);
        $filter = ['fields' => $fields, 'days' => (string) $f['days'], 'state' => $f['state']];
        $uid = $request->user()->id;

        if (! isset($f['token'])) {
            $token = Str::random(40);
            Cache::put("firetab.empty.$token", [$uid, $filter], self::TOKEN_TTL);
            $count = $this->emptyQuery($filter)->count();
            $rows = $this->emptyQuery($filter)->orderByDesc('id')->limit(200)->get();

            return Inertia::render('FireTab/Qm/EmptyPreview', [
                'fields' => self::EMPTY_FIELDS, 'filter' => $filter, 'ttl' => self::TOKEN_TTL,
                'preview' => ['token' => $token, 'count' => $count, 'rows' => $rows->map(fn (FireIncident $i) => [
                    'id' => $i->id, 'number' => $i->number, 'location' => $i->location, 'keyword' => $i->keyword,
                    'created_at' => OrganizationTime::display($i->created_at), 'finalized' => $i->finalized_at !== null,
                ])->all()],
            ]);
        }

        $issued = Cache::pull("firetab.empty.{$f['token']}");
        if ($issued !== [$uid, $filter]) {
            throw ValidationException::withMessages(['token' => __('firetab.qm.empty.token_invalid')]);
        }

        $n = $this->emptyQuery($filter)->update($this->archivePatch($request));
        AuditLogger::log($request->user(), 'Leere Einsatzprotokolle archiviert', "$n Protokolle ({$filter['days']} Tage, {$filter['state']}: ".implode(', ', $filter['fields']).')', self::MODULE, false, ['count' => $n]);

        return redirect('/firetab/qm')->with('success', __('firetab.qm.empty.done', ['count' => $n]));
    }

    /**
     * @param  array{fields: list<string>, days: string, state: string}  $f
     * @return Builder<FireIncident>
     */
    private function emptyQuery(array $f): Builder
    {
        $q = FireIncident::query()->whereNull('archived_at');
        foreach ($f['fields'] as $field) {
            match ($field) {
                'no_vehicles' => $q->whereDoesntHave('vehicles'),
                'leader' => $q->whereNull('leader_personnel_id')->whereNull('leader_external_name'),
                default => $q->where(fn (Builder $b) => $b->whereNull($field)->orWhere($field, '')),
            };
        }
        if ($f['days'] !== 'all') {
            $q->where('created_at', '>', now()->subDays((int) $f['days']));
        }
        match ($f['state']) {
            'open' => $q->whereNull('finalized_at'),
            'finalized' => $q->whereNotNull('finalized_at'),
            default => null,
        };

        return $q;
    }

    /** @return array<string, mixed> */
    private function archivePatch(Request $request): array
    {
        return ['archived_at' => now(), 'archived_by' => $request->user()->id, 'status' => FireIncidentStatus::Hidden->value, 'updated_by' => $request->user()->id];
    }

    private function setArchived(Request $request, FireIncident $incident, bool $on): void
    {
        $changed = DB::transaction(function () use ($request, $incident, $on) {
            $locked = FireIncident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            if (($locked->archived_at !== null) === $on) {
                return false;
            }
            $locked->forceFill($on ? $this->archivePatch($request) : ['archived_at' => null, 'archived_by' => null, 'updated_by' => $request->user()->id])->save();
            FireLog::record($locked, $on ? 'archived' : 'unarchived');

            return true;
        });
        if (! $changed) {
            return;
        }
        AuditLogger::log($request->user(), $on ? 'Einsatzprotokoll archiviert' : 'Einsatzprotokoll wiederhergestellt', $this->label($incident), self::MODULE, false, ['fire_incident_id' => $incident->id]);
    }

    private function label(FireIncident $incident): string
    {
        return $incident->number ?? '#'.$incident->id;
    }
}
