<?php

namespace Modules\Federation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuleRegistry;
use App\Support\SqlLike;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Federation\Models\FederatedIncident;
use Modules\Federation\Models\FederatedProtocol;
use Modules\Federation\Models\FederationLink;
use Modules\FireTab\Models\FireIncident;

/**
 * Read-only peer data: eNOTF protocols with the eNOTF QM right, fire incidents with fire.incident.qm; each
 * needs its source module on. Filter by instance, search, pagination. Plain values only (no HTML from peers).
 */
class ViewController extends Controller
{
    public function protocols(Request $request): Response
    {
        abort_unless(ModuleRegistry::active('enotf'), 404);
        Gate::authorize('viewQm', EnotfProtocol::class);
        [$query, $filters] = self::filtered(FederatedProtocol::query(), $request, ['number'], 'consume_enotf');

        return Inertia::render('Federation/Protocols', [
            'rows' => $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate(20)->withQueryString()->through(fn (FederatedProtocol $p) => [
                'id' => $p->id, 'instance' => $p->link->name, 'number' => $p->number, 'occurred_at' => $p->occurred_at?->toIso8601String(),
                'patient' => self::s($p->data, 'patname'), 'vehicles' => array_values(array_filter([self::s($p->data, 'fzg_transp'), self::s($p->data, 'fzg_na')])),
                'target' => self::s($p->data, 'ziel_poi') ?? self::s($p->data, 'ziel_adresse'), 'naca' => self::s($p->data, 'naca'),
                'crew' => array_values(array_filter([self::s($p->data, 'fahrername'), self::s($p->data, 'beifahrername'), self::s($p->data, 'praktikantname')])),
            ]),
            'filters' => $filters,
            'links' => self::links(),
        ]);
    }

    public function incidents(Request $request): Response
    {
        abort_unless(ModuleRegistry::active('firetab'), 404);
        Gate::authorize('viewQm', FireIncident::class);
        [$query, $filters] = self::filtered(FederatedIncident::query(), $request, ['number', 'keyword', 'location'], 'consume_fire');

        return Inertia::render('Federation/Incidents', [
            'rows' => $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate(20)->withQueryString()->through(fn (FederatedIncident $i) => [
                'id' => $i->id, 'instance' => $i->link->name, 'number' => $i->number, 'keyword' => $i->keyword, 'location' => $i->location,
                'status' => $i->status, 'occurred_at' => $i->occurred_at?->toIso8601String(),
                'leader' => self::s($i->data, 'leader_name'), 'finalized' => ($i->data['finalized'] ?? false) === true,
            ]),
            'filters' => $filters,
            'links' => self::links(),
        ]);
    }

    /**
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @param  list<string>  $columns
     * @return array{0: Builder<T>, 1: array{link: string, q: string}}
     */
    private static function filtered(Builder $query, Request $request, array $columns, string $flag): array
    {
        $link = $request->query('link');
        $link = is_string($link) && ctype_digit($link) ? $link : '';
        $q = $request->query('q');
        $q = is_string($q) ? mb_substr(trim($q), 0, 100) : '';

        $query->with('link:id,name')->whereHas('link', fn (Builder $b) => $b->where('is_active', true)->where($flag, true))
            ->when($link !== '', fn (Builder $b) => $b->where('link_id', (int) $link))
            ->when($q !== '', fn (Builder $b) => SqlLike::anyOf($b, $columns, $q));

        return [$query, ['link' => $link, 'q' => $q]];
    }

    /** @return list<array{id: int, name: string}> */
    private static function links(): array
    {
        return FederationLink::query()->orderBy('name')->get(['id', 'name'])->map(fn (FederationLink $l) => ['id' => $l->id, 'name' => $l->name])->all();
    }

    /** @param ?array<string, mixed> $data */
    private static function s(?array $data, string $key): ?string
    {
        $v = $data[$key] ?? null;

        return is_scalar($v) && $v !== '' ? mb_substr((string) $v, 0, 255) : null;
    }
}
