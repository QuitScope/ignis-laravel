<?php

namespace Modules\Enotf\Http\Middleware;

use App\Vehicles\CrewSession;
use Closure;
use Illuminate\Http\Request;
use Modules\Enotf\Support\CrewService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session PIN lock on every eNOTF route but sign-in/out, the lock screen, the status poll and the
 * personnel search, including
 * the policy-gated ones outside crew:enotf (protocol, autosave, release, print, ...): a crew session whose crew is locked
 * gets 423 {message, locked, redirect} as JSON and a redirect to the lock screen otherwise (a GET is remembered for the
 * way back). Accounts without a crew session (QM) pass. A session whose membership is gone (left, ended, replaced) is
 * dropped. Every unlocked request but the background `changes` sync counts as crew activity.
 */
final class EnsurePinUnlocked
{
    public function __construct(private readonly CrewService $crews) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = CrewSession::for('enotf');
        if ($session->current() === null) {
            return $next($request);
        }
        $member = $this->crews->member($request->user());
        if ($member === null) {
            $session->logout();

            return $next($request);
        }

        if ($this->crews->locked($member->crew)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => __('enotf.pin.locked'), 'locked' => true, 'redirect' => '/enotf/lockscreen'], 423);
            }
            if ($request->isMethod('GET')) {
                session()->put('url.intended', $request->fullUrl());
            }

            return redirect('/enotf/lockscreen');
        }
        if (! str_ends_with((string) $request->route()?->uri(), '/changes')) {
            $this->crews->touch($member->crew);
        }

        return $next($request);
    }
}
