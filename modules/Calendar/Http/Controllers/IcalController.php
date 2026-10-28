<?php

namespace Modules\Calendar\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Calendar\Models\CalendarEvent;
use Modules\Calendar\Support\IcalExporter;
use Modules\Calendar\Support\IcalToken;

/**
 * iCal subscription: the account manages its link (calendar.view), external calendars read the
 * feed by token without a session. The link is shown once, right after it was created; afterwards only "exists".
 */
class IcalController extends Controller
{
    private const MODULE = 'Kalender';

    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CalendarEvent::class);

        return response()->json(['has_subscription' => $request->user()->calendar_token_hash !== null]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CalendarEvent::class);
        $user = $request->user();
        $token = IcalToken::issue($user);
        AuditLogger::log($user, 'Kalender-Abo erneuert', null, self::MODULE, true, ['user_id' => $user->id]);

        // Only from app.url, never from the Host header.
        return response()->json(['url' => rtrim((string) config('app.url'), '/').'/api/calendar/ical/'.$token])
            ->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request): Response
    {
        $this->authorize('viewAny', CalendarEvent::class);
        IcalToken::revoke($request->user());
        AuditLogger::log($request->user(), 'Kalender-Abo widerrufen', null, self::MODULE, true, ['user_id' => $request->user()->id]);

        return response()->noContent();
    }

    /** Public feed. Every failure (unknown token, inactive account, no right, module off) is the router's own 404. */
    public function feed(Request $request, string $token): Response
    {
        $user = IcalToken::resolve($token);
        if ($user === null) {
            throw EnsureModuleEnabled::notFound($request);
        }

        return response(IcalExporter::export($user), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="calendar.ics"',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
