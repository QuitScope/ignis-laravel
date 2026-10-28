<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\DiscordLoginController;
use App\Http\Middleware\FiveMWebview;
use App\Models\Personnel;
use App\Models\TabletLoginToken;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign-in through ef_bridge. Discord OAuth does not work in FiveM's CEF, so the FiveM server
 * fetches a one-time token for the player's Discord ID with the API key, and the tablet opens
 * /auth/tablet with it. The token signs in any active account (admins too), so only the API key
 * may issue one. Never log the token. Contract: ef_bridge server/main.lua (requestTabletLogin).
 */
class TabletLoginController extends Controller
{
    private const PREVIEW_AGENTS = '/Discordbot|facebookexternalhit|Slackbot|Twitterbot|TelegramBot|WhatsApp|SkypeUriPreview|LinkedInBot/i';

    public function __construct(private readonly Settings $settings) {}

    /** POST /api/tablet/login-token (api.key), body {"discord_id": "…"} */
    public function issue(Request $request): JsonResponse
    {
        if (! $this->enabled()) {
            return self::error('disabled', 404);
        }
        if ($request->attributes->get('api_auth') !== 'key') {
            return self::error('api_key_required', 403);
        }

        $discordId = $request->json()->all()['discord_id'] ?? null;
        if (! is_string($discordId) || preg_match('/^[0-9]{17,20}$/D', $discordId) !== 1) {
            return self::error('invalid_discord_id', 422);
        }

        // Inactive accounts count as unknown. discord_id is unique on accounts, so `ambiguous_user`
        // only comes from the legacy fallback over the personnel record (legacy ADR-0002): no
        // active account with this ID -> the records with it; one record -> its active linked
        // account; several records -> 409. ef_bridge knows both answers (server/main.lua,
        // tests/tablet_login_check.lua) and shows "several accounts" resp. "no account".
        $user = User::query()->where('discord_id', $discordId)->where('is_active', true)->first();
        if ($user === null) {
            $people = Personnel::query()->where('discord_id', $discordId)->limit(2)->pluck('id');
            if ($people->count() > 1) {
                return self::error('ambiguous_user', 409);
            }
            $user = $people->isEmpty() ? null : User::query()->where('personnel_id', $people[0])->where('is_active', true)->first();
        }
        if ($user === null) {
            return self::error('unknown_user', 404);
        }

        if (! RateLimiter::attempt('tablet-token:'.$user->id, (int) config('ignis.tablet_tokens_per_minute'), fn () => true, 60)) {
            return self::error('rate_limited', 429)->header('Retry-After', '60');
        }
        TabletLoginToken::query()->where('expires_at', '<', now())->delete();

        return self::noStore(response()->json([
            'success' => true,
            'token' => TabletLoginToken::issue($user),
            'expires_in' => (int) config('ignis.tablet_token_ttl'),
            'login_url' => url('/auth/tablet'),
        ]));
    }

    /** GET /auth/tablet?token=… */
    public function login(Request $request): RedirectResponse|Response
    {
        abort_unless($this->enabled(), 404);

        if ($request->isMethod('HEAD') || preg_match(self::PREVIEW_AGENTS, (string) $request->userAgent()) === 1) {
            return self::noStore(response()->noContent());
        }

        $token = $request->query('token');
        $userId = is_string($token) && $token !== '' && strlen($token) <= 128 ? TabletLoginToken::redeem($token) : null;
        $user = $userId === null ? null : User::query()->whereKey($userId)->where('is_active', true)->first();

        if ($user === null) {
            // Deliberately one message for every cause: expired, used, forged or deactivated look the same.
            return self::private(DiscordLoginController::fail('tablet_failed'));
        }

        // Another account on this browser: kill its session (data and id) before signing in. The same account (second
        // frame, eNOTF and fireTab share the cookies) keeps its session id and CSRF token, else the other frame breaks.
        if (Auth::id() !== $user->id) {
            if (Auth::id() !== null) {
                $request->session()->invalidate();
            }
            Auth::login($user);
            $request->session()->regenerate();
        }
        AuditLogger::log($user, 'Anmeldung über ef_bridge', null, 'System', false, ['user_id' => $user->id]);

        // In the FiveM webview the next page must be a `fivem` route too: on `/` Laravel would rewrite the session and
        // XSRF-TOKEN cookies as Lax and the cross-site NUI iframe would lose the session. `to` picks the tablet from a
        // fixed allowlist (never a URL, so no open redirect); anything else means fireTab.
        $tablet = in_array($request->query('to'), ['firetab', 'enotf'], true) ? $request->query('to') : 'firetab';

        return self::private(redirect(FiveMWebview::isFiveM($request) ? '/'.$tablet : '/'));
    }

    private function enabled(): bool
    {
        return $this->settings->get('auth.tablet_login', false) === true;
    }

    private static function error(string $error, int $status): JsonResponse
    {
        return self::noStore(response()->json(['success' => false, 'error' => $error], $status));
    }

    /**
     * @template T of Response
     *
     * @param  T  $response
     * @return T
     */
    private static function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private static function private(RedirectResponse $response): RedirectResponse
    {
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return self::noStore($response);
    }
}
