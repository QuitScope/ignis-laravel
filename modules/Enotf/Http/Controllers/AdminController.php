<?php

namespace Modules\Enotf\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuleRegistry;
use App\Support\AuditLogger;
use App\Support\SqlLike;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Support\LocalDateTimeFormatter;
use Modules\Federation\Models\FederatedProtocol;

/** QM list (policy viewQm, no crew session). Protocols are hidden, never deleted. Patient data is not searched or listed. */
class AdminController extends Controller
{
    private const SEGMENTS = ['open', 'released', 'withdrawn', 'hidden'];

    private const SORTS = ['enr', 'mission_date', 'created_at', 'released_at', 'status'];

    public const EMPTY_FIELDS = ['patient_last_name', 'mission_date', 'destination_code', 'alarmed_at', 'no_crew'];

    private const TOKEN_TTL = 600;

    public function index(Request $request): Response
    {
        Gate::authorize('viewQm', EnotfProtocol::class);
        $segment = in_array($request->query('segment'), self::SEGMENTS, true) ? (string) $request->query('segment') : 'open';
        $q = $request->query('q');
        $q = is_string($q) ? mb_substr(trim($q), 0, 100) : '';
        $sort = in_array($request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'created_at';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $rows = EnotfProtocol::query()->select(['id', 'enr', 'status', 'qm_status', 'hidden_at', 'mission_date', 'mission_time', 'created_at', 'released_at', 'vehicle_transport_label', 'vehicle_na_label'])
            ->tap(fn (Builder $b) => self::segment($b, $segment))
            ->when($q !== '', fn (Builder $b) => SqlLike::anyOf($b, ['enr', 'vehicle_transport_label', 'vehicle_na_label'], $q))
            ->orderBy($sort, $dir)->orderByDesc('id')->paginate(20)->withQueryString();

        $counts = EnotfProtocol::query()->toBase()->selectRaw("case when hidden_at is not null then 'hidden' else status end as seg, count(*) as c")->groupBy('seg')->pluck('c', 'seg');

        return Inertia::render('Enotf/Admin/Index', [
            'protocols' => $rows->through(fn (EnotfProtocol $p) => [
                'id' => $p->id, 'enr' => $p->enr, 'status' => $p->status->value, 'hidden' => $p->hidden_at !== null,
                'vehicles' => array_values(array_filter([$p->vehicle_transport_label, $p->vehicle_na_label])),
                'mission_date' => $p->mission_date?->format('d.m.Y'), 'created_at' => LocalDateTimeFormatter::at($p->created_at), 'released_at' => LocalDateTimeFormatter::at($p->released_at),
            ]),
            'filters' => ['segment' => $segment, 'q' => $q, 'sort' => $sort, 'dir' => $dir],
            'counts' => array_combine(self::SEGMENTS, array_map(fn (string $s) => (int) ($counts[$s] ?? 0), self::SEGMENTS)),
            'can' => ['hide' => Gate::allows('bulkDelete', EnotfProtocol::class)],
            'federated' => ModuleRegistry::active('federation') ? FederatedProtocol::query()->count() : null,
        ]);
    }

    /** POST /enotf/admin/hide: soft, idempotent (already hidden rows are skipped). */
    public function hide(Request $request): RedirectResponse
    {
        Gate::authorize('bulkDelete', EnotfProtocol::class);
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer']])['ids'];

        $n = $this->hideQuery(EnotfProtocol::query()->whereIn('id', $ids), $request);
        AuditLogger::log($request->user(), 'Protokolle ausgeblendet', "$n Protokolle", 'eNOTF', false, ['count' => $n]);

        return back();
    }

    public function emptyForm(): Response
    {
        Gate::authorize('bulkDelete', EnotfProtocol::class);

        return Inertia::render('Enotf/Admin/EmptyPreview', ['fields' => self::EMPTY_FIELDS, 'filter' => null, 'ttl' => self::TOKEN_TTL, 'preview' => null]);
    }

    /** POST /enotf/admin/empty: without `token` a preview (issues the token), with it the execution (hides). */
    public function emptyRun(Request $request): Response|RedirectResponse
    {
        Gate::authorize('bulkDelete', EnotfProtocol::class);
        $f = $request->validate([
            'fields' => ['required', 'array', 'min:1'], 'fields.*' => ['string', Rule::in(self::EMPTY_FIELDS)],
            'days' => ['required', Rule::in(['7', '30', '90', '365', 'all'])],
            'state' => ['required', Rule::in(['open', 'released', 'withdrawn'])],
            'token' => ['nullable', 'string', 'size:40'],
        ]);
        $fields = array_values(array_unique($f['fields']));
        sort($fields);
        $filter = ['fields' => $fields, 'days' => (string) $f['days'], 'state' => $f['state']];
        $uid = $request->user()->id;

        if (! isset($f['token'])) {
            $token = Str::random(40);
            Cache::put("enotf.empty.$token", [$uid, $filter], self::TOKEN_TTL);

            return Inertia::render('Enotf/Admin/EmptyPreview', [
                'fields' => self::EMPTY_FIELDS, 'filter' => $filter, 'ttl' => self::TOKEN_TTL,
                'preview' => ['token' => $token, 'count' => $this->emptyQuery($filter)->count(),
                    'rows' => $this->emptyQuery($filter)->orderByDesc('id')->limit(200)->get(['id', 'enr', 'created_at', 'vehicle_transport_label', 'vehicle_na_label'])
                        ->map(fn (EnotfProtocol $p) => ['id' => $p->id, 'enr' => $p->enr, 'created_at' => LocalDateTimeFormatter::at($p->created_at),
                            'vehicles' => array_values(array_filter([$p->vehicle_transport_label, $p->vehicle_na_label]))])->all()],
            ]);
        }

        if (Cache::pull("enotf.empty.{$f['token']}") !== [$uid, $filter]) {
            throw ValidationException::withMessages(['token' => __('enotf.admin.empty.token_invalid')]);
        }

        $n = $this->hideQuery($this->emptyQuery($filter), $request);
        AuditLogger::log($request->user(), 'Leere Protokolle ausgeblendet', "$n Protokolle ({$filter['days']} Tage, {$filter['state']}: ".implode(', ', $filter['fields']).')', 'eNOTF', false, ['count' => $n]);

        return redirect('/enotf/admin')->with('success', __('enotf.admin.empty.done', ['count' => $n]));
    }

    /** @param  Builder<EnotfProtocol>  $query */
    private function hideQuery(Builder $query, Request $request): int
    {
        return $query->whereNull('hidden_at')->update(['hidden_at' => now(), 'hidden_by' => $request->user()->id]);
    }

    /**
     * @param  array{fields: list<string>, days: string, state: string}  $f
     * @return Builder<EnotfProtocol>
     */
    private function emptyQuery(array $f): Builder
    {
        $q = EnotfProtocol::query()->whereNull('hidden_at')->where('status', $f['state']);
        foreach ($f['fields'] as $field) {
            if ($field === 'no_crew') {
                $q->whereDoesntHave('crew');
            } else {
                $q->where(fn (Builder $b) => $b->whereNull($field)->orWhere($field, ''));
            }
        }
        if ($f['days'] !== 'all') {
            $q->where('created_at', '>', now()->subDays((int) $f['days']));
        }

        return $q;
    }

    /** @param  Builder<EnotfProtocol>  $b */
    private static function segment(Builder $b, string $segment): void
    {
        $segment === 'hidden' ? $b->whereNotNull('hidden_at') : $b->whereNull('hidden_at')->where('status', $segment);
    }
}
