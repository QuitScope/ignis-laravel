<?php

namespace Modules\Logbook\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Logbook\Http\Requests\LogbookEntryRequest;
use Modules\Logbook\LogbookService;
use Modules\Logbook\Models\LogbookEntry;

/** Admin page of the trip log (view or manage reads, manage writes). */
class LogbookController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', LogbookEntry::class);

        $text = fn (string $key) => is_string($request->query($key)) ? $request->query($key) : '';
        $date = fn (string $key) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $text($key)) === 1 && strtotime($text($key)) !== false ? $text($key) : '';
        $filters = [
            'vehicle' => ctype_digit($text('vehicle')) && strlen($text('vehicle')) < 10 ? $text('vehicle') : '',
            'type' => in_array($text('type'), LogbookEntry::TYPES, true) ? $text('type') : '',
            'from' => $date('from'),
            'to' => $date('to'),
        ];

        $query = LogbookEntry::query()
            ->when($filters['vehicle'] !== '', fn (Builder $q) => $q->where('vehicle_id', (int) $filters['vehicle']))
            ->when($filters['type'] !== '', fn (Builder $q) => $q->where('trip_type', $filters['type']))
            ->when($filters['from'] !== '', fn (Builder $q) => $q->where('trip_date', '>=', $filters['from']))
            ->when($filters['to'] !== '', fn (Builder $q) => $q->where('trip_date', '<=', $filters['to']));

        $stats = ['count' => (clone $query)->count(), 'km' => round((float) (clone $query)->sum('distance_km'), 1)];

        $entries = $query->orderByDesc('trip_date')->orderByDesc('departed_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE)->withQueryString()
            ->through(fn (LogbookEntry $e) => [
                'id' => $e->id,
                'vehicle_id' => $e->vehicle_id,
                'vehicle_identifier' => $e->vehicle_identifier,
                'trip_date' => $e->trip_date,
                'departed_at' => substr($e->departed_at, 0, 5),
                'arrived_at' => $e->arrived_at === null ? null : substr($e->arrived_at, 0, 5),
                'past_midnight' => $e->arrived_at !== null && substr($e->arrived_at, 0, 5) < substr($e->departed_at, 0, 5),
                'station_name' => $e->station_name,
                'distance_km' => $e->distance_km,
                'reason' => $e->reason,
                'trip_type' => $e->trip_type,
                'driver_name' => $e->driver_name,
                'source' => $e->source,
            ]);

        return Inertia::render('Logbook/Index', [
            'entries' => $entries,
            'filters' => $filters,
            'stats' => $stats,
            'types' => LogbookEntry::TYPES,
            'vehicles' => Vehicle::query()->orderBy('sort_order')->orderBy('name')->orderBy('id')->get(['id', 'name', 'identifier'])
                ->map(fn (Vehicle $v) => ['id' => $v->id, 'label' => $v->name.' ('.$v->identifier.')'])->all(),
            'can' => ['manage' => $request->user()->can('create', LogbookEntry::class)],
        ]);
    }

    public function store(LogbookEntryRequest $request): RedirectResponse
    {
        $vehicle = Vehicle::findOrFail($request->integer('vehicle_id'));
        app(LogbookService::class)->record($request->safe()->only(array_keys(LogbookService::rules())), $vehicle, $request->string('driver_name')->toString(), 'admin', $request->user());

        return back();
    }

    public function update(LogbookEntryRequest $request, LogbookEntry $entry): RedirectResponse
    {
        $vehicle = $request->filled('vehicle_id') ? Vehicle::findOrFail($request->integer('vehicle_id')) : null;
        $entry->forceFill(LogbookService::trip($request->validated()) + ['driver_name' => $request->string('driver_name')->toString()]);
        if ($vehicle !== null && $entry->vehicle_id !== $vehicle->id) {
            $entry->forceFill(LogbookService::vehicleTexts($vehicle));
        }
        $entry->save();
        LogbookService::audit($request->user(), 'Fahrtenbuch-Eintrag bearbeitet', $entry);

        return back();
    }

    public function destroy(Request $request, LogbookEntry $entry): RedirectResponse
    {
        $this->authorize('delete', $entry);
        $entry->delete();
        LogbookService::audit($request->user(), 'Fahrtenbuch-Eintrag gelöscht', $entry);

        return back();
    }
}
