<?php

namespace Modules\Enotf\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Personnel;
use App\Models\Vehicle;
use App\Vehicles\CrewSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Enotf\Support\CrewService;

/** Joining, leaving and ending a vehicle crew. The rules live in CrewService. */
class CrewController extends Controller
{
    /**
     * POST /enotf/crew/join {position, vehicle_id?, personnel_id?, pin?}. Without personnel_id (or with the own record) the
     * account joins the open crew of vehicle_id itself (with the crew's PIN); with another record a member of the crew names it for a free seat 2/3.
     */
    public function join(Request $request, CrewService $crews): RedirectResponse
    {
        $data = $request->validate([
            'position' => ['required', 'integer', 'in:1,2,3'],
            'vehicle_id' => ['nullable', 'integer'],
            'personnel_id' => ['nullable', 'integer'],
            'pin' => ['nullable', 'string', 'max:16'],
        ]);
        $user = $request->user();
        abort_if($user->personnel_id === null, 403, __('crew.no_personnel'));
        $named = isset($data['personnel_id']) ? (int) $data['personnel_id'] : null;
        $position = (int) $data['position'];

        if ($named === null || $named === $user->personnel_id) {
            $vehicle = Vehicle::query()->find($data['vehicle_id'] ?? 0)
                ?? throw ValidationException::withMessages(['vehicle_id' => __('crew.vehicle_unavailable')]);
            $crews->joinSelf($user, $vehicle, $position, $data['pin'] ?? null);

            return redirect('/enotf/overview');
        }

        $member = $crews->member($user) ?? abort(403);
        if ($position === 1 || ! Personnel::query()->active()->whereKey($named)->exists()) {
            throw ValidationException::withMessages(['members' => __('enotf.login.members_invalid')]);
        }
        $crews->name($member, $position, $named);

        return back();
    }

    /** POST /enotf/crew/leave */
    public function leave(Request $request, CrewService $crews): RedirectResponse
    {
        $crews->leave($crews->member($request->user()) ?? abort(403));

        return redirect('/enotf/loggedout');
    }

    /** POST /enotf/crew/end: the crew ends for every member and device. */
    public function end(Request $request, CrewService $crews): RedirectResponse
    {
        $member = $crews->member($request->user()) ?? abort(403);
        $crews->close($member->crew, 'ended');
        CrewSession::for('enotf')->logout();

        return redirect('/enotf/loggedout');
    }
}
