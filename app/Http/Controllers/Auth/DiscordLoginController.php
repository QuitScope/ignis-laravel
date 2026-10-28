<?php

namespace App\Http\Controllers\Auth;

use App\Auth\LoginDecision;
use App\Http\Controllers\Controller;
use App\Models\RegistrationCode;
use App\Models\User;
use App\Personnel\AccountLink;
use App\Support\AuditLogger;
use App\Support\SafeRedirect;
use App\Support\Settings;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

class DiscordLoginController extends Controller
{
    public const ERROR = 'auth.error';

    public function __construct(
        private readonly Settings $settings,
        private readonly LoginDecision $decision,
    ) {}

    public function login(Request $request): Response
    {
        $error = $request->session()->get(self::ERROR);

        return Inertia::render('Auth/Login', [
            'error' => is_string($error) ? __($error) : null,
            'registrationMode' => $this->decision->registrationMode(),
            'city' => trim((string) $this->settings->get('organisation.city')) ?: null,
        ]);
    }

    public function redirect(): SymfonyRedirect
    {
        return $this->discord()?->setScopes(['identify'])->redirect() ?? self::fail('secret_unavailable');
    }

    public function callback(Request $request): RedirectResponse
    {
        $code = $request->session()->pull('registration_code');
        $discord = $this->discord();
        if ($discord === null) {
            return self::fail('secret_unavailable');
        }

        try {
            $account = $discord->user();
        } catch (InvalidStateException|GuzzleException|\TypeError|\ErrorException $e) {
            // Class name only: exception messages may carry the OAuth code or response bodies.
            Log::warning('Discord login failed: '.$e::class);

            return self::fail('discord_failed');
        }

        $discordId = (string) $account->getId();
        if (preg_match('/^\d{17,20}$/', $discordId) !== 1) {
            Log::warning('Discord login failed: malformed user id');

            return self::fail('discord_failed');
        }

        $result = $this->decision->decide($discordId, is_string($code) ? $code : null);

        if ($result['action'] === 'deny') {
            if (Cache::add("login-denied:{$discordId}:{$result['reason']}", 1, 600)) {
                AuditLogger::log(null, 'Anmeldung abgelehnt', 'Grund: '.$result['reason'], 'Anmeldung', true, ['discord_id' => $discordId, 'reason' => (string) $result['reason']]);
            }

            return self::fail((string) $result['reason']);
        }

        $user = null;
        if ($result['action'] === 'register') {
            $name = (string) ($account->getName() ?? $account->getNickname() ?? $discordId);

            try {
                $user = $this->register($discordId, $name, is_string($code) ? $code : null);
            } catch (UniqueConstraintViolationException) {
                $result = $this->decision->decide($discordId, null);
                if ($result['action'] !== 'login') {
                    return self::fail((string) ($result['reason'] ?? 'discord_failed'));
                }
            }

            if ($user === null && $result['action'] === 'register') {
                return self::fail('code_invalid');
            }
        }

        $registered = $user !== null;
        $user ??= User::where('discord_id', $discordId)->sole();
        AuditLogger::log($user, $registered ? 'Benutzer registriert' : 'Benutzer angemeldet', null, 'Anmeldung', $registered, ['user_id' => $user->id]);
        $redeemed = $registered ? RegistrationCode::where('used_by', $user->id)->first() : null;
        if ($redeemed !== null) {
            AuditLogger::log($user, 'Einladung eingelöst', null, 'Einladungen', true, ['registration_code_id' => $redeemed->id, 'user_id' => $user->id]);
        }

        AccountLink::autoLinkByDiscord($discordId);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->to(SafeRedirect::intended($this->ownPath($request->session()->pull('url.intended'))));
    }

    public function logout(Request $request): RedirectResponse
    {
        AuditLogger::log($request->user(), 'Benutzer abgemeldet', null, 'Anmeldung', false, ['user_id' => $request->user()?->id]);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    public static function fail(string $reason): RedirectResponse
    {
        return redirect('/login')->with(self::ERROR, 'auth.error.'.$reason);
    }

    /**
     * One transaction: claim the invitation code (atomic, one winner), create the account, link
     * both. Null if a required code was taken meanwhile. Explicit attributes only: User has
     * $guarded = [] and provider data is untrusted. In `open` mode a carried code is redeemed
     * when valid and ignored otherwise.
     */
    private function register(string $discordId, string $username, ?string $code): ?User
    {
        return DB::transaction(function () use ($discordId, $username, $code) {
            $claimed = $code !== null && $code !== '' ? RegistrationCode::reserve($code) : null;
            if ($claimed === null && $this->decision->registrationMode() === 'code' && ! $this->decision->isInstallAdmin($discordId)) {
                return null;
            }

            $user = new User;
            $user->discord_id = $discordId;
            $user->username = mb_substr($username, 0, 100);
            $user->name = null;
            $user->is_active = true;
            $user->is_super_admin = $this->decision->isInstallAdmin($discordId);
            $user->save();
            $user->setRole($this->decision->roleFor($discordId) ?? throw new \LogicException('No role for new account.'));
            $claimed?->redeemFor($user);

            return $user;
        });
    }

    private function discord(): ?AbstractProvider
    {
        $id = $this->settings->get('discord.client_id');
        $secret = $this->settings->get('discord.client_secret');

        if (! is_string($id) || $id === '' || ! is_string($secret) || $secret === '') {
            return null;
        }

        $path = $this->settings->get('discord.redirect_path', '/auth/discord/callback');

        config(['services.discord' => [
            'client_id' => $id,
            'client_secret' => $secret,
            'redirect' => rtrim((string) config('app.url'), '/').'/'.ltrim(is_string($path) ? $path : '', '/'),
        ]]);

        /** @var AbstractProvider */
        return Socialite::driver('discord');
    }

    /** Laravel remembers the intended URL absolute; reduce our own origin to a path. */
    private function ownPath(mixed $intended): ?string
    {
        if (! is_string($intended)) {
            return null;
        }

        $root = rtrim(url('/'), '/');

        if ($intended === $root) {
            return '/';
        }

        return str_starts_with($intended, $root.'/') ? substr($intended, strlen($root)) : $intended;
    }
}
