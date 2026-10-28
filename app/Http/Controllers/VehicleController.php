<?php

namespace App\Http\Controllers;

use App\Events\VehicleOutOfService;
use App\Http\Requests\SaveVehicleRequest;
use App\Http\Requests\VehicleBulkRequest;
use App\Models\LoadoutCategory;
use App\Models\Poi;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDefect;
use App\Support\AuditLogger;
use App\Support\SqlLike;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * Vehicle list, detail, form and bulk actions (legacy Settings\FahrzeugeController). `is_active` is the admin
 * switch, operability is derived from open blocking defects; both are shown separately.
 */
class VehicleController extends Controller
{
    private const PER_PAGE = 25;

    private const SORTS = [
        'priority' => 'sort_order',
        'name' => 'name',
        'license_plate' => 'license_plate',
        'service' => 'service',
        'defects' => 'open_defects_count',
        'active' => 'is_active',
    ];

    private const MODULE = 'Fahrzeuge';

    public function index(Request $request): Response
    {
        $this->authorize('vehicle.view');

        $text = fn (string $key) => is_string($request->query($key)) ? $request->query($key) : '';
        $search = trim($text('search'));
        $active = in_array($text('active'), ['0', '1'], true) ? $text('active') : '';
        $operable = in_array($text('operable'), ['0', '1'], true) ? $text('operable') : '';
        $sort = array_key_exists($text('sort'), self::SORTS) ? $text('sort') : 'priority';
        $dir = $text('dir') === 'desc' ? 'desc' : 'asc';

        $vehicles = Vehicle::query()
            ->withBlockingDefectsCount()
            ->withCount(['defects as open_defects_count' => fn (Builder $q) => $q->where('status', '!=', VehicleDefect::RESOLVED)])
            ->with(['defects' => fn (HasMany $q) => $q->where('status', '!=', VehicleDefect::RESOLVED)
                ->latest()->latest('id')->limit(3)->select(['id', 'vehicle_id', 'title', 'status', 'is_operable', 'created_at'])])
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereRaw('name '.SqlLike::CLAUSE, [SqlLike::contains($search)])
                ->orWhereRaw('license_plate '.SqlLike::CLAUSE, [SqlLike::contains($search)])
                ->orWhereRaw('identifier '.SqlLike::CLAUSE, [SqlLike::contains($search)])
                ->orWhereRaw('type '.SqlLike::CLAUSE, [SqlLike::contains($search)])))
            ->when($active !== '', fn (Builder $q) => $q->where('is_active', $active === '1'))
            ->when($operable === '1', fn (Builder $q) => $q->operable())
            ->when($operable === '0', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('is_active', false)->orWhereHas('blockingDefects')))
            ->orderBy(self::SORTS[$sort], $dir)->orderBy('id')
            ->paginate(self::PER_PAGE)->withQueryString()
            ->through(fn (Vehicle $v) => $this->row($v) + [
                'open_defects_count' => (int) $v->getAttribute('open_defects_count'),
                'preview_defects' => $v->defects->map(fn (VehicleDefect $d) => [
                    'id' => $d->id, 'title' => $d->title, 'status' => $d->status, 'is_blocking' => ! $d->is_operable,
                    'created_at' => $d->created_at?->toIso8601String(),
                ])->all(),
            ]);

        return Inertia::render('Vehicles/Index', [
            'vehicles' => $vehicles,
            'filters' => ['search' => $search, 'active' => $active, 'operable' => $operable, 'sort' => $sort, 'dir' => $dir],
            'fmsLabels' => (object) Vehicle::FMS_LABELS,
            'can' => ['manage' => $request->user()->can('vehicle.manage')],
        ]);
    }

    public function show(Request $request, Vehicle $vehicle): Response
    {
        $this->authorize('vehicle.view');

        $vehicle->load('station')->loadCount([
            'blockingDefects', 'defects as open_defects_count' => fn (Builder $q) => $q->where('status', '!=', VehicleDefect::RESOLVED),
        ]);
        $activity = Activity::query()->with('causer')
            ->where('log_name', self::MODULE)->where('properties->context->id', $vehicle->id)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(20)->get()
            ->map(fn (Activity $a) => [
                'id' => $a->id,
                'action' => $a->description,
                'details' => $a->getAttribute('details'),
                'user' => $a->causer instanceof User ? $a->causer->username : null,
                'time' => $a->created_at?->toIso8601String(),
            ]);

        $dialog = VehicleDefectController::dialogProps($request->user());

        return Inertia::render('Vehicles/Show', [
            'vehicle' => $this->row($vehicle) + [
                'open_defects_count' => (int) $vehicle->getAttribute('open_defects_count'),
                'station' => $vehicle->station === null ? null : self::stationLabel($vehicle->station),
                'allowed_jobs' => $vehicle->allowed_jobs,
                'fms_status_at' => $vehicle->fms_status_at?->toIso8601String(),
                'fms_status_source' => $vehicle->fms_status_source,
                'tactical_symbol' => $vehicle->tactical_symbol,
                'tactical_symbol_name' => $vehicle->tactical_symbol_name,
            ],
            'activity' => $activity,
            'defects' => VehicleDefectController::rowsFor($vehicle, 50),
            'loadout' => $vehicle->loadoutCategories()->get()->map(fn (LoadoutCategory $c) => LoadoutController::row($c))->all(),
            'users' => $dialog['users'],
            'can' => ['manage' => $dialog['can']['manage'], 'manageLoadout' => $request->user()->can('vehicle.manage'), 'createDefect' => $dialog['can']['create'], 'deleteDefect' => $dialog['can']['delete']],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('vehicle.manage');

        return Inertia::render('Vehicles/Form', ['vehicle' => null, 'stations' => self::stations(), 'templates' => TacticalSymbolTemplateController::all(), 'canView' => $request->user()->can('vehicle.view')]);
    }

    public function edit(Request $request, Vehicle $vehicle): Response
    {
        $this->authorize('vehicle.manage');

        return Inertia::render('Vehicles/Form', [
            'vehicle' => [
                ...$vehicle->only(['id', 'name', 'type', 'identifier', 'license_plate', 'service', 'sort_order', 'is_active', 'station_poi_id', 'tactical_symbol', 'tactical_symbol_name']),
                'allowed_jobs' => implode(', ', $vehicle->allowed_jobs ?? []),
            ],
            'stations' => self::stations($vehicle->station_poi_id),
            'templates' => TacticalSymbolTemplateController::all(),
            'canView' => $request->user()->can('vehicle.view'),
        ]);
    }

    public function store(SaveVehicleRequest $request): RedirectResponse
    {
        $vehicle = Vehicle::create($request->vehicleData());
        $this->audit($request, 'Fahrzeug erstellt', $vehicle->id, 'Name: '.$vehicle->name.' | Typ: '.$vehicle->type);

        return $this->afterSave($request, $vehicle);
    }

    public function update(SaveVehicleRequest $request, Vehicle $vehicle): RedirectResponse
    {
        $vehicle->update($request->vehicleData());
        $this->audit($request, 'Fahrzeug aktualisiert', $vehicle->id);
        if ($vehicle->wasChanged('is_active') && ! $vehicle->is_active) {
            event(new VehicleOutOfService([$vehicle->id]));
        }

        return $this->afterSave($request, $vehicle);
    }

    /** vehicles.manage alone cannot read the detail page (legacy rule), so it stays on the form. */
    private function afterSave(Request $request, Vehicle $vehicle): RedirectResponse
    {
        return $request->user()->can('vehicle.view')
            ? redirect("/vehicles/{$vehicle->id}")
            : redirect("/vehicles/{$vehicle->id}/edit")->with('status', 'vehicles.saved');
    }

    public function destroy(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->authorize('vehicle.manage');

        $vehicle->delete();
        $this->audit($request, 'Fahrzeug gelöscht', $vehicle->id);

        return $request->user()->can('vehicle.view') ? redirect('/vehicles') : redirect('/')->with('status', 'vehicles.deleted');
    }

    public function bulk(VehicleBulkRequest $request): RedirectResponse
    {
        $ids = Vehicle::whereIn('id', $request->ids())->orderBy('id')->pluck('id')->all();
        if ($ids === []) {
            throw ValidationException::withMessages(['ids' => trans('vehicles.bulk.none')]);
        }

        $query = Vehicle::whereIn('id', $ids);
        $verb = 'Fahrzeug aktualisiert';
        $details = null;
        switch ($request->validated('action')) {
            case 'delete':
                $query->delete();
                $verb = 'Fahrzeug gelöscht';
                break;
            case 'fms_status':
                $status = (string) $request->validated('fms_status');
                $query->update(['fms_status' => $status, 'fms_status_at' => now(), 'fms_status_source' => 'manual']);
                $details = 'EMD-Status: '.$status.' ('.Vehicle::fmsLabel($status).') (Sammelaktion)';
                break;
            default:
                $active = $request->validated('action') === 'activate';
                $query->update(['is_active' => $active]);
                if (! $active) {
                    event(new VehicleOutOfService($ids));
                }
                $details = 'Status: '.($active ? 'aktiv' : 'inaktiv').' (Sammelaktion)';
        }
        foreach ($ids as $id) {
            $this->audit($request, $verb, $id, $details);
        }

        return back();
    }

    /**
     * The fields list, card and detail share.
     *
     * @return array<string, mixed>
     */
    private function row(Vehicle $v): array
    {
        return [
            'id' => $v->id,
            'name' => $v->name,
            'identifier' => $v->identifier,
            'type' => $v->type,
            'sort_order' => $v->sort_order,
            'service' => $v->service,
            'license_plate' => $v->license_plate,
            'is_active' => $v->is_active,
            'state' => $v->operabilityState(),
            'blocking_defects_count' => $v->blocking_defects_count,
            'fms_status' => $v->fms_status,
            'fms_label' => Vehicle::fmsLabel($v->fms_status),
        ];
    }

    /**
     * Active stations, "Name (City)", plus the vehicle's current one when it is no longer an active station.
     *
     * @return list<array{id: int, label: string}>
     */
    private static function stations(?int $current = null): array
    {
        $stations = Poi::query()->stations()->orderBy('name')->orderBy('id')->get(['id', 'name', 'city'])
            ->map(fn (Poi $p) => ['id' => $p->id, 'label' => self::stationLabel($p)])->all();
        $poi = $current === null || in_array($current, array_column($stations, 'id'), true) ? null : Poi::find($current, ['id', 'name', 'city']);
        if ($poi !== null) {
            $stations[] = ['id' => $poi->id, 'label' => self::stationLabel($poi).' ('.trans('vehicles.station_inactive').')'];
        }

        return $stations;
    }

    private static function stationLabel(Poi $poi): string
    {
        $city = trim($poi->city);

        return $city === '' ? $poi->name : $poi->name.' ('.$city.')';
    }

    private function audit(Request $request, string $verb, int $id, ?string $details = null): void
    {
        AuditLogger::log($request->user(), $verb.' [ID: '.$id.']', $details, self::MODULE, true, ['id' => $id]);
    }
}
