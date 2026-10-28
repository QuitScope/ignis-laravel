<?php

namespace App\Http\Controllers\Auth;

use App\Auth\InvitationCodes;
use App\Auth\LoginDecision;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/** GET (shareable link) and POST (login form) share one handler; neither keeps the code in a URL. */
class InviteRedemptionController extends Controller
{
    public function __invoke(Request $request, LoginDecision $decision, InvitationCodes $codes): Response
    {
        if ($decision->registrationMode() === 'closed') {
            return DiscordLoginController::fail('closed');
        }

        $code = $request->isMethod('post') ? $request->input('code') : $request->query('code');
        $code = is_string($code) ? trim($code) : '';

        if ($code === '' || strlen($code) > 64 || ! $codes->isRedeemable($code)) {
            return DiscordLoginController::fail('code_invalid');
        }

        $request->session()->put('registration_code', $code);

        return $request->header('X-Inertia') ? Inertia::location(url('/auth/discord')) : redirect('/auth/discord');
    }
}
