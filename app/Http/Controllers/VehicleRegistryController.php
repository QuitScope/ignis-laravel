<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegistryDecisionRequest;
use App\Models\Vehicle;
use App\Models\VehicleRegistryEntry;
use App\Support\AuditLogger;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The registry queue (legacy Api\VehicleImportController): what ef_bridge sent (VehicleRegistryIntake), matched
 * against the vehicles by name or identifier, and the admin's decision per entry. Every decision claims the entry
 * with a conditional update first, so it runs once; a second one answers 409.
 *
 * Matching compares lowercased in PHP (not the database collation), the same on SQLite and MariaDB; accents and
 * trailing spaces count, unlike the legacy unicode_ci comparison.
 */
class VehicleRegistryController extends Controller
{
    private const PER_PAGE = 50;

    private const MODULE = 'Fahrzeuge';

    private const REQUESTED = 'vehicles.registry_requested_at';

    public function __construct(private readonly Settings $settings) {}

    public function index(Request $request): Response
    {
        $this->authorize('vehicle.manageImport');

        $match = self::matcher();
        $all = VehicleRegistryEntry::where('status', VehicleRegistryEntry::PENDING)->get(['id', 'name', 'identifier']);
        $matched = $all->filter(fn (VehicleRegistryEntry $e) => $match($e->name, $e->identifier)[1] !== null)->count();

        $entries = VehicleRegistryEntry::where('status', VehicleRegistryEntry::PENDING)->orderBy('id')
            ->paginate(self::PER_PAGE)->withQueryString()
            ->through(function (VehicleRegistryEntry $e) use ($match) {
                [$type, $vehicle] = $match($e->name, $e->identifier);

                return [
                    ...$e->only(['id', 'emd_vehicle_id', 'name', 'identifier', 'type', 'service', 'department', 'value_long', 'job', 'image', 'radio_channel']),
                    'created_at' => $e->created_at?->toIso8601String(),
                    'match_type' => $type,
                    'existing' => $vehicle?->only(['id', 'name', 'identifier', 'type', 'service', 'allowed_jobs']),
                ];
            });

        return Inertia::render('Vehicles/Registry', [
            'entries' => $entries,
            'counts' => ['pending' => $all->count(), 'matched' => $matched, 'new' => $all->count() - $matched, 'max_id' => $all->max('id')],
            'status' => $this->state(),
            'can' => ['view' => $request->user()->can('vehicle.view')],
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $this->authorize('vehicle.manageImport');

        return response()->json($this->state());
    }

    public function requestRegistry(Request $request): RedirectResponse
    {
        $this->authorize('vehicle.manageImport');

        $this->settings->set(self::REQUESTED, now()->toIso8601String());
        AuditLogger::log($request->user(), 'EMD Fahrzeug-Import angefordert', 'Flag gesetzt - wird beim nächsten Sync übermittelt', self::MODULE, true);

        return back()->with('status', 'vehicles.registry.requested');
    }

    public function cancelRequest(Request $request): RedirectResponse
    {
        $this->authorize('vehicle.manageImport');

        $this->settings->forget(self::REQUESTED);

        return back()->with('status', 'vehicles.registry.cancelled');
    }

    public function ignoreAll(Request $request): RedirectResponse
    {
        $this->authorize('vehicle.manageImport');
        $maxId = $request->validate(['max_id' => ['required', 'integer', 'min:1']], [
            'max_id.required' => trans('vehicles.registry.error.max_id'), 'max_id.integer' => trans('vehicles.registry.error.max_id'), 'max_id.min' => trans('vehicles.registry.error.max_id'),
        ])['max_id'];

        $count = VehicleRegistryEntry::where('status', VehicleRegistryEntry::PENDING)->where('id', '<=', (int) $maxId)
            ->update(self::processed($request, VehicleRegistryEntry::REJECTED));

        return back()->with('status', 'vehicles.registry.ignored_all')->with('status_params', ['count' => $count]);
    }

    public function decide(RegistryDecisionRequest $request, VehicleRegistryEntry $entry, string $action): RedirectResponse
    {
        DB::transaction(function () use ($request, $entry, $action) {
            $claimed = VehicleRegistryEntry::whereKey($entry->id)->where('status', VehicleRegistryEntry::PENDING)
                ->update(self::processed($request, $action === 'ignore' ? VehicleRegistryEntry::REJECTED : VehicleRegistryEntry::ACCEPTED));
            if ($claimed === 0) {
                VehicleDefectController::conflict($request, 'entry', 'vehicles.registry.error.processed');
            }
            if ($action === 'ignore') {
                return;
            }

            $data = $request->proposal($entry);
            if ($action === 'import') {
                if (self::matcher()($data['name'], $data['identifier'])[1] !== null) {
                    throw ValidationException::withMessages(['entry' => trans('vehicles.registry.error.duplicate')]);
                }
                $vehicle = Vehicle::create($data + ['sort_order' => 0, 'is_active' => true, 'license_plate' => null]);
                $this->audit($request, 'Fahrzeug per EMD-Import erstellt', $vehicle->id, 'Name: '.$vehicle->name.' | Typ: '.$vehicle->type);

                return;
            }

            $vehicle = Vehicle::lockForUpdate()->findOrFail((int) $request->validated('vehicle_id'));
            if ($action === 'overwrite') {
                $vehicle->update($data);
                $this->audit($request, 'Fahrzeug per EMD-Import überschrieben', $vehicle->id, 'Name: '.$data['name'].' | ID: '.$vehicle->id);

                return;
            }
            $vehicle->update([
                'identifier' => $vehicle->identifier !== '' ? $vehicle->identifier : $data['identifier'],
                'type' => $vehicle->type !== '' ? $vehicle->type : $data['type'],
                'service' => $vehicle->service > 0 ? $vehicle->service : $data['service'],
                'allowed_jobs' => $vehicle->allowed_jobs ?: $data['allowed_jobs'],
            ]);
            $this->audit($request, 'Fahrzeug per EMD-Import zusammengeführt', $vehicle->id, 'Name: '.$entry->name.' | ID: '.$vehicle->id);
        });

        return back()->with('status', 'vehicles.registry.done.'.$action)->with('status_params', ['name' => $entry->name]);
    }

    /** @return array{pending: bool, count: int, requested: ?string, last_sync_at: ?string} */
    private function state(): array
    {
        $requested = $this->settings->get(self::REQUESTED);
        $synced = $this->settings->get('emd.last_sync_at');

        return [
            'pending' => is_string($requested),
            'count' => VehicleRegistryEntry::where('status', VehicleRegistryEntry::PENDING)->count(),
            'requested' => is_string($requested) ? $requested : null,
            'last_sync_at' => is_string($synced) ? $synced : null,
        ];
    }

    /**
     * (name, identifier) => [match_type, vehicle]: a name match wins over an identifier match, the lowest id over
     * a higher one; an empty identifier matches nothing.
     *
     * @return callable(string, ?string): array{0: 'name'|'identifier'|'none', 1: ?Vehicle}
     */
    private static function matcher(): callable
    {
        /** @var Collection<int, Vehicle> $vehicles */
        $vehicles = Vehicle::query()->orderBy('id')->get(['id', 'name', 'identifier', 'type', 'service', 'allowed_jobs']);
        $byName = [];
        $byIdentifier = [];
        foreach ($vehicles as $v) {
            $byName[mb_strtolower($v->name)] ??= $v;
            if ($v->identifier !== '') {
                $byIdentifier[mb_strtolower($v->identifier)] ??= $v;
            }
        }

        return function (string $name, ?string $identifier) use ($byName, $byIdentifier): array {
            if (isset($byName[mb_strtolower($name)])) {
                return ['name', $byName[mb_strtolower($name)]];
            }
            $key = mb_strtolower($identifier ?? '');

            return $key !== '' && isset($byIdentifier[$key]) ? ['identifier', $byIdentifier[$key]] : ['none', null];
        };
    }

    /** @return array<string, mixed> */
    private static function processed(Request $request, string $status): array
    {
        return ['status' => $status, 'processed_by' => $request->user()->id, 'processed_at' => now()];
    }

    private function audit(Request $request, string $action, int $id, string $details): void
    {
        AuditLogger::log($request->user(), $action, $details, self::MODULE, true, ['id' => $id]);
    }
}
