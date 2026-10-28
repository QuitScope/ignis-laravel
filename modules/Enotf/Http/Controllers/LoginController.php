<?php

namespace Modules\Enotf\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Personnel;
use App\Models\Vehicle;
use App\Support\SqlLike;
use App\Vehicles\CrewSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Enotf\Models\EnotfCrew;
use Modules\Enotf\Support\CrewService;

/**
 * Vehicle login of the eNOTF tablet (3.3, 9a minimal). Position 1 (driver) is the account's
 * own personnel record, never request input; positions 2 (co-driver) and 3 (trainee) are records picked from the search
 * or empty. No free-text names. Joining, leaving, ending: CrewService and CrewController (9c).
 */
class LoginController extends Controller
{
    public const POSITIONS = [1 => 'driver', 2 => 'codriver', 3 => 'trainee'];

    /** GET /enotf: the vehicle overview with a crew session, the vehicle sign-in otherwise. */
    public function home(Request $request): RedirectResponse
    {
        $crew = $this->crew();

        return redirect($crew->valid($request->user()) ? '/enotf/overview' : $crew->loginPath());
    }

    /** GET /enotf/login?q= : vehicle search (rescue service only), at most 20 hits. */
    public function show(Request $request, CrewService $crews): Response
    {
        $user = $request->user();
        abort_if($user->personnel_id === null && $user->is_super_admin, 403, __('crew.no_personnel'));

        $q = $request->query('q');
        $q = is_string($q) && mb_strlen(trim($q)) >= 2 ? trim($q) : '';
        $vehicles = [];
        if ($q !== '' && mb_strlen($q) <= 50 && $user->personnel_id !== null) {
            $query = $this->crew()->vehicles()->select(['id', 'identifier', 'name']);
            SqlLike::anyOf($query, ['identifier', 'name'], $q);
            $hits = $query->orderBy('sort_order')->orderBy('identifier')->orderBy('id')->limit(20)->get();
            $taken = EnotfCrew::query()->whereIn('vehicle_id', $hits->modelKeys())->whereNull('ended_at')
                ->with(['members' => fn ($m) => $m->whereNotNull('active_key')])->get()
                ->mapWithKeys(fn (EnotfCrew $c) => [$c->vehicle_id => $c->members->pluck('position')->sort()->values()->all()]);
            $vehicles = $hits->map(fn (Vehicle $v) => ['id' => $v->id, 'identifier' => $v->identifier, 'name' => $v->name, 'taken' => $taken[$v->id] ?? null])->all();
        }
        $current = $this->crew()->vehicle();
        $me = $user->personnel_id === null ? null : Personnel::query()->find($user->personnel_id);

        return Inertia::render('Enotf/Login', [
            'q' => $q,
            'vehicles' => $vehicles,
            'me' => $me?->displayName(),
            'current' => $current === null ? null : ['identifier' => $current->identifier, 'name' => $current->name],
            'pinRequired' => $crews->pinEnabled(),
        ]);
    }

    /** POST /enotf/login {vehicle_id, codriver_id?, trainee_id?} */
    public function store(Request $request, CrewService $crews): RedirectResponse
    {
        $data = $request->validate([
            'vehicle_id' => ['required', 'integer'],
            'codriver_id' => ['nullable', 'integer'],
            'trainee_id' => ['nullable', 'integer'],
            'pin' => [Rule::requiredIf($crews->pinEnabled()), 'nullable', 'string', 'regex:/^\d{4,8}$/'],
        ]);
        $user = $request->user();
        $vehicle = Vehicle::query()->find($data['vehicle_id'])
            ?? throw ValidationException::withMessages(['vehicle_id' => __('crew.vehicle_unavailable')]);

        $members = array_filter([1 => $user->personnel_id, 2 => $data['codriver_id'] ?? null, 3 => $data['trainee_id'] ?? null]);
        $others = array_diff_key($members, [1 => true]);
        if (count(array_unique($members)) !== count($members)
            || Personnel::query()->active()->whereKey($others)->count() !== count($others)) {
            throw ValidationException::withMessages(['members' => __('enotf.login.members_invalid')]);
        }

        $crews->open($user, $vehicle, $members, $crews->pinEnabled() ? $data['pin'] : null);

        return redirect('/enotf/overview');
    }

    /** POST /enotf/logout: leaves the crew (= /enotf/crew/leave) and ends the vehicle session; the account stays signed in. */
    public function destroy(Request $request, CrewService $crews): RedirectResponse
    {
        $member = $crews->member($request->user());
        $member === null ? $this->crew()->logout() : $crews->leave($member);

        return redirect('/enotf/loggedout');
    }

    /** GET /enotf/loggedout */
    public function loggedOut(): Response
    {
        return Inertia::render('Enotf/LoggedOut');
    }

    /** GET /enotf/personnel/search?q= : active records for the crew positions, at most 20 hits. */
    public function personnel(Request $request): JsonResponse
    {
        abort_if($request->user()->personnel_id === null, 403);
        $q = $request->query('q', '');
        abort_unless(is_string($q), 422);
        $q = trim($q);
        $hits = [];
        if (mb_strlen($q) >= 2 && mb_strlen($q) <= 50) {
            $query = Personnel::query()->active()->select(['id', 'full_name'])->whereKeyNot($request->user()->personnel_id);
            SqlLike::anyOf($query, ['full_name'], $q);
            $hits = $query->orderBy('full_name')->limit(20)->get()->map(fn (Personnel $p) => ['id' => $p->id, 'name' => $p->full_name])->all();
        }

        return response()->json($hits);
    }

    private function crew(): CrewSession
    {
        return CrewSession::for('enotf');
    }
}
