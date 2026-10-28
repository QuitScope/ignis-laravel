<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Auth\DiscordLoginController;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** A signed-in user deactivated meanwhile is signed out on the next request (redirect; 401 JSON for plain JSON clients). */
class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['error' => 'deactivated'], 401);
            }

            return DiscordLoginController::fail('deactivated');
        }

        return $next($request);
    }
}
