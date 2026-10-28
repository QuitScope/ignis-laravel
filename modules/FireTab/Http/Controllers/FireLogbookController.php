<?php

namespace Modules\FireTab\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Personnel;
use App\Models\Vehicle;
use App\Modules\ModuleRegistry;
use App\Vehicles\CrewSession;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Modules\FireTab\Support\OrganizationTime;
use Modules\Logbook\LogbookService;
use Modules\Logbook\Models\LogbookEntry;

/**
 * Trips of the crew vehicle. Vehicle and driver always come from the crew session, never from the form.
 * record() is not idempotent, so every form open gets a single-use key (10 min); a second submit finds it gone.
 */
class FireLogbookController extends Controller implements HasMiddleware
{
    /** @return list<Closure> module `logbook` off => 404 */
    public static function middleware(): array
    {
        return [function (Request $request, Closure $next) {
            abort_unless(ModuleRegistry::active('logbook'), 404);

            return $next($request);
        }];
    }

    /** GET /firetab/logbook */
    public function index(Request $request): Response
    {
        $vehicle = $this->vehicle();
        $key = (string) Str::uuid();
        Cache::put($this->cacheKey($request, $key), 1, now()->addMinutes(10));
        $driver = $this->driver();

        return Inertia::render('FireTab/Logbook', [
            'vehicle' => ['identifier' => $vehicle->identifier, 'name' => $vehicle->name],
            'formKey' => $key,
            'today' => OrganizationTime::local(now())?->format('Y-m-d'),
            'types' => LogbookEntry::TYPES,
            'entries' => LogbookEntry::query()->where('vehicle_id', $vehicle->id)->orderByDesc('trip_date')->orderByDesc('departed_at')->orderByDesc('id')->limit(50)->get()
                ->map(fn (LogbookEntry $e) => [
                    'id' => $e->id, 'trip_date' => $e->trip_date, 'departed_at' => substr($e->departed_at, 0, 5),
                    'arrived_at' => $e->arrived_at === null ? null : substr($e->arrived_at, 0, 5), 'distance_km' => $e->distance_km,
                    'reason' => $e->reason, 'trip_type' => $e->trip_type, 'driver_name' => $e->driver_name,
                    'editable' => $this->editable($e, $request, $driver),
                ])->all(),
        ]);
    }

    /** POST /firetab/logbook */
    public function store(Request $request): RedirectResponse
    {
        $data = Validator::make($request->all(), LogbookService::rules() + ['key' => ['required', 'uuid']])->validate();

        if (Cache::forget($this->cacheKey($request, $data['key']))) {
            app(LogbookService::class)->record($data, $this->vehicle(), $this->driver(), 'firetab', $request->user());
        }

        return redirect('/firetab/logbook');
    }

    /** PUT /firetab/logbook/{entry}: own entries of this source only; the vehicle snapshot stays. */
    public function update(Request $request, LogbookEntry $entry): RedirectResponse
    {
        abort_unless($this->editable($entry, $request, $this->driver()), 403);
        $entry->forceFill(LogbookService::trip(Validator::make($request->all(), LogbookService::rules())->validate()))->save();
        LogbookService::audit($request->user(), 'Fahrtenbuch-Eintrag bearbeitet', $entry);

        return redirect('/firetab/logbook');
    }

    private function editable(LogbookEntry $e, Request $request, string $driver): bool
    {
        return $e->source === 'firetab' && $e->created_by === $request->user()->id && $e->driver_name === $driver;
    }

    private function vehicle(): Vehicle
    {
        return CrewSession::for('firetab')->vehicle() ?? abort(403);
    }

    private function driver(): string
    {
        $id = CrewSession::for('firetab')->current()['personnel_id'] ?? null;

        return trim(Personnel::query()->with('title')->findOrFail($id)->displayName());
    }

    private function cacheKey(Request $request, string $key): string
    {
        return 'firetab.logbook.'.$request->user()->id.'.'.$key;
    }
}
