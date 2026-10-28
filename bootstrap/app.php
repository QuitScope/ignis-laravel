<?php

use App\Http\Middleware\ApiKeyMiddleware;
use App\Http\Middleware\EmdBodyLimit;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureCrewSession;
use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\FiveMWebview;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\NormalizeLegacyPath;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // /broadcasting/auth. `fivem`: otherwise this response rewrites the CEF session cookie without
    // SameSite=None and breaks the tablet session. web brings the session and EnsureActiveUser, but no CSRF check:
    // BroadcastManager::routes() removes it for this route (which also accepts GET). Harmless: a foreign page cannot read the signature.
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web', 'auth', 'fivem', 'throttle:broadcast-auth']])
    ->withMiddleware(function (Middleware $middleware): void {
        // Before the body-decoding globals (TrimStrings, ...), after the path rewrite below: the EMD sync size guard.
        $middleware->prepend(EmdBodyLimit::class);
        // Before everything else: rewrites legacy paths before route matching.
        $middleware->prepend(NormalizeLegacyPath::class);
        // Outermost, so early redirects get X-Frame-Options too. It comes from here, not nginx, so CEF can be exempted.
        $middleware->prepend(SecurityHeaders::class);
        // Without a module: only answers OPTIONS on a switched-off module's path with 404 (the router answers it itself).
        $middleware->append(EnsureModuleEnabled::class);
        $middleware->web(prepend: [
            EnsureInstalled::class,
        ], append: [
            EnsureActiveUser::class,
            SetLocale::class,
            HandleInertiaRequests::class,
        ]);
        // The system page, the update status and the rollback keep answering (behind auth + system.admin; rollback with
        // confirmation and throttle) while the app is down for an update, so an admin sees the progress and, after a
        // failed rollback (maintenance stays on), can retry the rollback. Every other update POST stays blocked.
        // The health probes (/healthz, /api/health: status and check names only) answer too: maintenance is planned, the
        // Docker healthcheck must not turn the app unhealthy (worker/scheduler depend on it, compose up waits for it).
        $middleware->preventRequestsDuringMaintenance(except: ['settings/system', 'settings/system/update/status', 'settings/system/update/rollback', 'healthz', 'api/health']);
        $middleware->redirectGuestsTo('/login');
        // Signed-in users on guest pages go to the home page (the framework default is /dashboard, now the quick links).
        $middleware->redirectUsersTo('/');
        $middleware->api(append: [
            SetLocale::class,
        ]);
        $middleware->alias(['api.key' => ApiKeyMiddleware::class, 'fivem' => FiveMWebview::class, 'module' => EnsureModuleEnabled::class, 'firetab.crew' => EnsureCrewSession::class, 'crew' => EnsureCrewSession::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Secrets must not land in the session as "old input" after a validation error.
        $exceptions->dontFlash(['installToken', 'discordClientSecret', 'api_key', 'intraRP_API_Key', 'discord.client_secret', 'token', 'webhook_url']);

        // A wrong method on a switched-off module's path: 404 like a missing route, not a 405 that tells it exists.
        $exceptions->map(MethodNotAllowedHttpException::class, fn (MethodNotAllowedHttpException $e) => EnsureModuleEnabled::hides(request()) ? EnsureModuleEnabled::notFound(request()) : $e);

        // A QueryException's message and trace carry the bindings (patient data from the eNOTF, search terms, ...):
        // logged with the SQL and its placeholders only.
        $exceptions->report(function (QueryException $e) {
            Log::error('Database error', ['connection' => $e->getConnectionName(), 'code' => $e->getCode(), 'sql' => $e->getSql()]);

            return false;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
