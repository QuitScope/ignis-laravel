<?php

namespace Modules\Enotf\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Vehicles\CrewSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Enotf\Support\CrewService;

/** Lock screen of the session PIN (legacy LockscreenController without the global PIN). */
class LockController extends Controller
{
    /** GET /enotf/lockscreen?return=/enotf/... (the page that hit the lock; local eNOTF paths only). */
    public function show(Request $request, CrewService $crews): Response|RedirectResponse
    {
        $member = $crews->member($request->user());
        if ($member === null) {
            return redirect('/enotf/login');
        }
        $return = $request->query('return');
        // A path of this app only (url() puts the own host in front): never an open redirect.
        if (is_string($return) && str_starts_with($return, '/enotf/')) {
            session()->put('url.intended', url($return));
        }
        if (! $crews->locked($member->crew)) {
            return redirect()->intended('/enotf/overview');
        }
        $vehicle = CrewSession::for('enotf')->vehicle();

        return Inertia::render('Enotf/Lockscreen', ['vehicle' => $vehicle === null ? null : $vehicle->identifier.' '.$vehicle->name]);
    }

    /** POST /enotf/pin-activity: the client heartbeat; EnsurePinUnlocked already counted it as activity. */
    public function activity(): HttpResponse
    {
        return response()->noContent();
    }

    /** POST /enotf/unlock {pin}: 10 tries a minute per crew; the 5th wrong PIN ends the crew. */
    public function unlock(Request $request, CrewService $crews): RedirectResponse
    {
        $pin = $request->validate(['pin' => ['required', 'string', 'max:16']])['pin'];
        $member = $crews->member($request->user());
        if ($member === null) {
            return redirect('/enotf/login');
        }
        $crew = $member->crew;
        if (! $crews->locked($crew)) {
            return redirect()->intended('/enotf/overview');
        }
        $key = 'enotf-unlock:'.$crew->id;
        abort_if(RateLimiter::tooManyAttempts($key, 10), 429);
        RateLimiter::hit($key, 60);

        return match ($crews->unlock($crew, $pin)) {
            true => redirect()->intended('/enotf/overview'),
            false => back()->withErrors(['pin' => __('enotf.pin.wrong')]),
            null => redirect('/enotf/loggedout')->with('status', 'enotf.pin.ended'),
        };
    }
}
