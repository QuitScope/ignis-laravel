<?php

namespace Modules\Enotf\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuleRegistry;
use App\Vehicles\CrewSession;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Enotf\Models\CrewMember;
use Modules\Enotf\Models\EnotfCategory;
use Modules\Enotf\Models\EnotfProtocol;
use Modules\Enotf\Models\EnotfQuicklink;
use Modules\Enotf\Support\CrewService;

/** GET /enotf/overview (tablet entry, path kept): open protocols of the crew's vehicle, the crew panel and the quicklink tiles. */
class OverviewController extends Controller
{
    public function __invoke(Request $request, CrewService $crews): Response
    {
        $this->authorize('viewAny', EnotfProtocol::class);
        $user = $request->user();
        $vehicle = CrewSession::for('enotf')->vehicle() ?? abort(403);

        $protocols = EnotfProtocol::query()->openForVehicle($vehicle->id)->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (EnotfProtocol $p) => [
                'enr' => $p->enr,
                'patient' => trim($p->patient_first_name.' '.$p->patient_last_name),
                'created_at' => $p->created_at?->toIso8601String(),
                'can_withdraw' => $user->can('withdraw', $p),
            ])->all();

        $categories = EnotfCategory::query()->where('active', true)->orderBy('sort_order')->orderBy('id')
            ->with(['quicklinks' => fn ($q) => $q->where('active', true)->orderBy('sort_order')->orderBy('id')])->get()
            ->map(fn (EnotfCategory $c) => [
                'name' => $c->name,
                // Only http(s) or relative links: a stored javascript: URL must never become a clickable tile.
                'links' => $c->quicklinks->filter(fn (EnotfQuicklink $l) => in_array(strtolower((string) parse_url($l->url, PHP_URL_SCHEME)), ['', 'http', 'https'], true))
                    ->map(fn (EnotfQuicklink $l) => ['id' => $l->id, 'title' => $l->title, 'url' => $l->url])->values()->all(),
            ])->filter(fn (array $c) => $c['links'] !== [])->values()->all();

        $self = $crews->member($user);
        $members = $self?->crew->members()->whereNotNull('active_key')->with('personnel.title')->orderBy('position')->get()
            ->map(fn (CrewMember $m) => ['position' => $m->position, 'name' => $m->personnel->displayName(), 'self' => $m->id === $self->id])->all() ?? [];

        return Inertia::render('Enotf/Overview', [
            'vehicle' => ['identifier' => $vehicle->identifier, 'name' => $vehicle->name],
            'crew' => $members,
            'protocols' => $protocols,
            'categories' => $categories,
            'logbook' => ModuleRegistry::active('logbook'),
            'canWithdrawAll' => collect($protocols)->contains('can_withdraw', true),
        ]);
    }
}
