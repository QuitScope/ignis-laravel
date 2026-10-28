<?php

namespace App\Http\Controllers;

use App\Events\VehicleDefectResolved;
use App\Http\Requests\CreateDefectRequest;
use App\Http\Requests\ResolveDefectRequest;
use App\Http\Requests\UpdateDefectRequest;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDefect;
use App\Models\VehicleDefectEvent;
use App\Support\AuditLogger;
use App\Vehicles\DefectReporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vehicle defects (legacy Api\VehicleDefectsController and the defect pages of FahrzeugeController): list, report,
 * detail with history, status/assignment, resolve, delete. Reporting and resolving never touch `is_active`.
 *
 * Decision: unlike resolving, a PATCH back to an open status reopens a resolved defect (legacy allowed any status
 * change) and clears its resolved_* columns, so a reopened defect does not look resolved anywhere. Status changes
 * carry the status the dialog showed; a mismatch is refused, so only a dialog that showed `resolved` can reopen.
 */
class VehicleDefectController extends Controller
{
    private const PER_PAGE = 25;

    private const WITH = ['vehicle:id,name', 'reporter.personnel:id,full_name', 'assignee.personnel:id,full_name', 'lastEvent.user.personnel:id,full_name'];

    public function index(Request $request): Response
    {
        $this->authorize('vehicle.view');

        $vehicleId = self::id($request->query('vehicle'));
        $status = in_array($request->query('status'), VehicleDefect::STATUSES, true) ? (string) $request->query('status') : '';
        $base = fn () => VehicleDefect::query()->when($vehicleId !== null, fn (Builder $q) => $q->where('vehicle_id', $vehicleId));

        $counts = $base()->toBase()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $stats = [];
        foreach (VehicleDefect::STATUSES as $s) {
            $stats[$s] = (int) ($counts[$s] ?? 0);
        }
        $stats['blocking'] = $base()->blocking()->count();
        $stats['total'] = array_sum($counts->map(fn ($n) => (int) $n)->all());

        $defects = $base()->with(self::WITH)
            ->when($status !== '', fn (Builder $q) => $q->where('status', $status))
            ->inListOrder()
            ->paginate(self::PER_PAGE)->withQueryString()
            ->through(fn (VehicleDefect $d) => self::row($d));

        return Inertia::render('Vehicles/Defects/Index', [
            'defects' => $defects,
            'stats' => $stats,
            'filters' => ['vehicle' => $vehicleId, 'status' => $status],
            'vehicles' => Vehicle::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'type'])->map->only(['id', 'name', 'type'])->all(),
            ...self::dialogProps($request->user()),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('vehicle.createDefect');

        $selected = self::id($request->query('vehicle'));

        return Inertia::render('Vehicles/Defects/Create', [
            'vehicles' => Vehicle::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'type'])->map->only(['id', 'name', 'type'])->all(),
            'selected' => $selected !== null && Vehicle::whereKey($selected)->exists() ? $selected : null,
            'categories' => VehicleDefect::CATEGORIES,
            'canView' => $request->user()->can('vehicle.view'),
        ]);
    }

    public function store(CreateDefectRequest $request, Vehicle $vehicle, DefectReporter $reporter): RedirectResponse
    {
        $defect = $reporter->report($vehicle, $request->defectData(), $request->user());
        AuditLogger::log($request->user(), 'Defekt gemeldet [ID: '.$defect->id.']', 'Fahrzeug-ID: '.$vehicle->id.' | '.$defect->title,
            'Fahrzeuge', true, ['id' => $vehicle->id, 'defect_id' => $defect->id]);

        $to = $request->user()->can('vehicle.view') ? "/vehicles/defects?vehicle={$vehicle->id}" : "/vehicles/defects/create?vehicle={$vehicle->id}";

        return redirect($to)->with('status', $defect->is_operable ? 'vehicles.defects.reported' : 'vehicles.defects.reported_blocking');
    }

    public function show(VehicleDefect $defect): JsonResponse
    {
        $this->authorize('vehicle.view');

        $defect->load([...self::WITH, 'resolver.personnel:id,full_name',
            'events' => fn (HasMany $q) => $q->with('user.personnel:id,full_name')->orderBy('id')]);

        return response()->json(['defect' => self::row($defect) + [
            'description' => $defect->description,
            'resolution_note' => $defect->resolution_note,
            'resolved_at' => $defect->resolved_at?->toIso8601String(),
            'resolver' => $defect->resolver?->displayName(),
            'events' => $defect->events->map(fn (VehicleDefectEvent $e) => self::event($e))->all(),
        ]]);
    }

    public function update(UpdateDefectRequest $request, VehicleDefect $defect): RedirectResponse
    {
        $data = $request->validated();
        $user = $request->user();

        DB::transaction(function () use ($request, $defect, $data, $user) {
            $d = VehicleDefect::lockForUpdate()->findOrFail($defect->id);
            if (isset($data['status']) && $d->status !== $data['from_status']) {
                self::conflict($request, 'status', 'vehicles.defects.error.changed');
            }
            $changes = [];
            $log = [];

            if (isset($data['status']) && $data['status'] !== $d->status) {
                $changes['status'] = $data['status'];
                if ($d->status === VehicleDefect::RESOLVED) {
                    $changes += ['resolved_by' => null, 'resolved_at' => null, 'resolution_note' => null];
                }
                $note = $data['status_note'] ?? null;
                $log[] = 'Status geändert: '.self::label($d->status).' → '.self::label($data['status']).($note === null ? '' : ' | '.$note);
            }

            if (array_key_exists('assigned_to', $data)) {
                $to = $data['assigned_to'] === null ? null : (int) $data['assigned_to'];
                if ($to !== ($d->assigned_to === null ? null : (int) $d->assigned_to)) {
                    $changes['assigned_to'] = $to;
                    $log[] = $to === null ? 'Zuweisung entfernt' : 'Zugewiesen an: '.User::with('personnel:id,full_name')->findOrFail($to)->displayName();
                }
            }

            if ($changes !== []) {
                $d->update($changes);
                foreach ($log as $details) {
                    DefectReporter::log($d, $user, 'updated', $details);
                }
            }
        });

        return back();
    }

    /** Only one resolution wins: the conditional update changes the row once, a second one answers 409. */
    public function resolve(ResolveDefectRequest $request, VehicleDefect $defect): RedirectResponse
    {
        $note = $request->validated('resolution_note');
        $user = $request->user();

        DB::transaction(function () use ($request, $defect, $note, $user) {
            $won = VehicleDefect::whereKey($defect->id)->where('status', '!=', VehicleDefect::RESOLVED)->update([
                'status' => VehicleDefect::RESOLVED, 'resolved_by' => $user->id, 'resolved_at' => now(), 'resolution_note' => $note,
            ]);
            if ($won === 0) {
                self::conflict($request, 'resolution_note', 'vehicles.defects.error.already_resolved');
            }

            DefectReporter::log($defect, $user, 'resolved', 'Als gelöst markiert'.($note === null ? '' : ': '.$note));
            event(new VehicleDefectResolved($defect->refresh()));
        });

        return back();
    }

    public function destroy(VehicleDefect $defect): RedirectResponse
    {
        $this->authorize('vehicle.deleteDefect');

        $defect->delete();

        return back();
    }

    /**
     * Assignable accounts (active only) for managers, plus the rights the defect dialog needs.
     *
     * @return array{users: list<array{id: int, name: string}>, can: array{manage: bool, delete: bool, create: bool}}
     */
    public static function dialogProps(User $user): array
    {
        $manage = $user->can('vehicle.manage');

        return [
            'users' => $manage ? User::active()->with('personnel:id,full_name')->get(['id', 'name', 'username', 'personnel_id'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->displayName()])
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all() : [],
            'can' => ['manage' => $manage, 'delete' => $user->can('vehicle.deleteDefect'), 'create' => $user->can('vehicle.createDefect')],
        ];
    }

    /**
     * The newest defects of one vehicle in list order, for its defects tab.
     *
     * @return list<array<string, mixed>>
     */
    public static function rowsFor(Vehicle $vehicle, int $limit): array
    {
        return VehicleDefect::query()->where('vehicle_id', $vehicle->id)->with(self::WITH)->inListOrder()->limit($limit)->get()
            ->map(fn (VehicleDefect $d) => self::row($d))->values()->all();
    }

    /** @return array<string, mixed> */
    private static function row(VehicleDefect $d): array
    {
        return [
            'id' => $d->id,
            'title' => $d->title,
            'category' => $d->category,
            'status' => $d->status,
            'is_blocking' => ! $d->is_operable,
            'vehicle' => ['id' => $d->vehicle->id, 'name' => $d->vehicle->name],
            'reporter' => $d->reporter?->displayName() ?? $d->reporter_name,
            'assigned_to' => $d->assigned_to === null ? null : (int) $d->assigned_to,
            'assignee' => $d->assignee?->displayName(),
            'created_at' => $d->created_at?->toIso8601String(),
            'last_event' => $d->lastEvent === null ? null : self::event($d->lastEvent),
        ];
    }

    /** @return array{action: string, details: ?string, user: ?string, time: ?string} */
    private static function event(VehicleDefectEvent $e): array
    {
        return ['action' => $e->action, 'details' => $e->details, 'user' => $e->user?->displayName(), 'time' => $e->created_at?->toIso8601String()];
    }

    public static function conflict(Request $request, string $field, string $message): never
    {
        abort_if($request->expectsJson(), 409, trans($message));

        throw ValidationException::withMessages([$field => trans($message)]);
    }

    private static function label(string $status): string
    {
        return VehicleDefect::STATUS_LABELS[$status] ?? $status;
    }

    private static function id(mixed $value): ?int
    {
        return is_string($value) && preg_match('/^[1-9]\d{0,17}$/', $value) === 1 ? (int) $value : null;
    }
}
