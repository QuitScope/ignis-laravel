<?php

namespace Modules\FireTab\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Support\SqlLike;
use App\Vehicles\CrewSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Vehicle login of the tablet. The operator is the account's own personnel record. */
class LoginController extends Controller
{
    /** GET /firetab */
    public function home(Request $request): RedirectResponse
    {
        return redirect($this->crew()->valid($request->user()) ? '/firetab/list' : '/firetab/login');
    }

    /** GET /firetab/login?q= : vehicle search by identifier or name, at most 20 hits (no list of all vehicles). */
    public function show(Request $request): Response
    {
        $user = $request->user();
        abort_if($user->personnel_id === null && $user->is_super_admin, 403, __('crew.no_personnel'));

        $q = $request->query('q');
        $q = is_string($q) && mb_strlen(trim($q)) >= 2 ? trim($q) : '';
        $vehicles = [];
        if ($q !== '' && mb_strlen($q) <= 50 && $user->personnel_id !== null) {
            $query = $this->crew()->vehicles()->select(['id', 'identifier', 'name']);
            SqlLike::anyOf($query, ['identifier', 'name'], $q);
            $vehicles = $query->orderBy('sort_order')->orderBy('identifier')->orderBy('id')->limit(20)->get()
                ->map(fn (Vehicle $v) => ['id' => $v->id, 'identifier' => $v->identifier, 'name' => $v->name])->all();
        }
        $current = $this->crew()->vehicle();

        return Inertia::render('FireTab/Login', [
            'q' => $q,
            'vehicles' => $vehicles,
            'hasPersonnel' => $user->personnel_id !== null,
            'current' => $current === null ? null : ['identifier' => $current->identifier, 'name' => $current->name],
        ]);
    }

    /** POST /firetab/login {vehicle_id} */
    public function store(Request $request): RedirectResponse
    {
        $id = $request->validate(['vehicle_id' => ['required', 'integer']])['vehicle_id'];
        $vehicle = Vehicle::query()->find($id)
            ?? throw ValidationException::withMessages(['vehicle_id' => __('crew.vehicle_unavailable')]);

        $this->crew()->login($request->user(), $vehicle);

        return redirect('/firetab/list');
    }

    /** POST /firetab/logout: ends the vehicle session only, the account stays signed in. */
    public function destroy(): RedirectResponse
    {
        $this->crew()->logout();

        return redirect('/firetab/login');
    }

    private function crew(): CrewSession
    {
        return CrewSession::for('firetab');
    }
}
