<?php

namespace Modules\FireTab\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\MapCoordinates;
use App\Vehicles\CrewSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\FireTab\Events\MapChanged;
use Modules\FireTab\Http\Requests\MarkerRequest;
use Modules\FireTab\Http\Requests\ZoneRequest;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireMapMarker;
use Modules\FireTab\Models\FireMapZone;
use Modules\FireTab\Policies\FireIncidentPolicy;
use Modules\FireTab\Support\FireLog;

/**
 * Lagekarte. Reads: `view` (crew route or crew-free QM route); create/move/location: `update`;
 * delete: `deleteMapItem`. Account, personnel record and vehicle come from the session, never from input.
 */
class MapController extends Controller
{
    private const SYMBOL_FIELDS = ['grundzeichen', 'organisation', 'fachaufgabe', 'einheit', 'symbol', 'typ', 'text', 'name'];

    public const LOCATION_SYMBOL = 'vollbrand';

    private const RELATIONS = ['creator:id,name', 'personnel:id,full_name', 'vehicle:id,name'];

    public function index(Request $request, FireIncident $incident): Response
    {
        $this->authorize('view', $incident);
        $qm = $request->is('firetab/qm/*');
        $crew = CrewSession::for('firetab')->current();
        $user = $request->user();
        $anyItem = ! $qm && $user?->can('deleteMapItem', [$incident, new FireMapMarker]) === true;
        $assigned = ! $qm && $user?->can('update', $incident) === true;
        $canDelete = fn (FireMapMarker|FireMapZone $item): bool => $anyItem || ($assigned && FireIncidentPolicy::owns($user, $item));

        return Inertia::render('FireTab/Map', [
            'incident' => ['id' => $incident->id, 'number' => $incident->number, 'location' => $incident->location, 'has_location' => $incident->location_x !== null && $incident->location_y !== null],
            'base' => "/firetab/incidents/{$incident->id}/map",
            'back' => $qm ? "/firetab/qm/incidents/{$incident->id}" : "/firetab/incidents/{$incident->id}?tab=lagekarte",
            'qm' => $qm,
            'can' => ['write' => ! $qm && $request->user()?->can('update', $incident) === true],
            'me' => ['user_id' => $request->user()?->id, 'personnel_id' => $qm ? null : ($crew['personnel_id'] ?? null)],
            'colors' => FireMapZone::COLORS,
            'markers' => fn () => $incident->mapMarkers()->with(self::RELATIONS)->orderBy('id')->get()->map(fn (FireMapMarker $m) => [
                'id' => $m->id, 'marker_type' => $m->marker_type, 'pos_x' => $m->pos_x, 'pos_y' => $m->pos_y, 'description' => $m->description, 'can_delete' => $canDelete($m),
                ...$m->only(self::SYMBOL_FIELDS), ...self::attribution($m),
            ])->all(),
            'zones' => fn () => $incident->mapZones()->where('is_valid', true)->with(self::RELATIONS)->orderBy('id')->get()->map(fn (FireMapZone $z) => [
                'id' => $z->id, 'name' => $z->name, 'description' => $z->description, 'points' => $z->points, 'color' => $z->color, 'can_delete' => $canDelete($z), ...self::attribution($z),
            ])->all(),
        ]);
    }

    public function storeMarker(MarkerRequest $request, FireIncident $incident): JsonResponse
    {
        $d = $request->validated();
        $marker = $this->stamp(new FireMapMarker, $incident)->forceFill([
            'marker_type' => $request->markerType(),
            'pos_x' => round((float) $d['pos_x'], 2),
            'pos_y' => round((float) $d['pos_y'], 2),
            'description' => $d['description'] ?? null,
            ...array_map(fn (string $f) => $d[$f] ?? null, array_combine(self::SYMBOL_FIELDS, self::SYMBOL_FIELDS)),
        ]);
        $this->write($incident, fn () => $marker->save(), 'marker_created', $marker->marker_type);

        return response()->json(['id' => $marker->id], 201);
    }

    public function moveMarker(Request $request, FireIncident $incident, FireMapMarker $mapMarker): JsonResponse
    {
        $this->authorize('update', $incident);
        $d = $request->validate(MarkerRequest::position());
        $mapMarker->forceFill(['pos_x' => round((float) $d['pos_x'], 2), 'pos_y' => round((float) $d['pos_y'], 2)]);
        $this->write($incident, fn () => $mapMarker->save(), 'marker_moved', $mapMarker->marker_type);

        return response()->json(['ok' => true]);
    }

    public function destroyMarker(FireIncident $incident, FireMapMarker $mapMarker): JsonResponse
    {
        $this->authorize('deleteMapItem', [$incident, $mapMarker]);
        $this->write($incident, fn () => $mapMarker->delete(), 'marker_deleted', $mapMarker->marker_type);

        return response()->json(['ok' => true]);
    }

    public function storeZone(ZoneRequest $request, FireIncident $incident): JsonResponse
    {
        $d = $request->validated();
        $zone = $this->stamp(new FireMapZone, $incident)->forceFill([
            'name' => $d['name'], 'description' => $d['description'] ?? null, 'color' => $d['color'], 'points' => $request->points(),
        ]);
        $this->write($incident, fn () => $zone->save(), 'zone_created', $zone->name);

        return response()->json(['id' => $zone->id], 201);
    }

    public function destroyZone(FireIncident $incident, FireMapZone $mapZone): JsonResponse
    {
        $this->authorize('deleteMapItem', [$incident, $mapZone]);
        $this->write($incident, fn () => $mapZone->delete(), 'zone_deleted', $mapZone->name);

        return response()->json(['ok' => true]);
    }

    public function location(FireIncident $incident): JsonResponse
    {
        $this->authorize('update', $incident);
        if ($incident->location_x === null || $incident->location_y === null) {
            throw ValidationException::withMessages(['location' => __('firetab.map.no_location')]);
        }
        $pos = MapCoordinates::gtaToMap((float) $incident->location_x, (float) $incident->location_y);

        $id = DB::transaction(function () use ($incident, $pos) {
            FireIncident::query()->whereKey($incident->id)->lockForUpdate()->first();
            $marker = $incident->mapMarkers()->where('marker_type', FireMapMarker::LOCATION)->orderBy('id')->first()
                ?? $this->stamp(new FireMapMarker, $incident)->forceFill([
                    'marker_type' => FireMapMarker::LOCATION, 'description' => 'Automatisch aus GTA-Koordinaten',
                    'grundzeichen' => 'ohne', 'symbol' => self::LOCATION_SYMBOL, 'name' => FireMapMarker::LOCATION,
                ]);
            $action = $marker->exists ? 'marker_moved' : 'marker_created';
            $marker->forceFill(['pos_x' => $pos['x'], 'pos_y' => $pos['y']])->save();
            FireLog::record($incident, $action, FireMapMarker::LOCATION);
            DB::afterCommit(fn () => MapChanged::dispatch($incident->id));

            return $marker->id;
        });

        return response()->json(['id' => $id, 'pos_x' => $pos['x'], 'pos_y' => $pos['y']]);
    }

    /**
     * @template T of FireMapMarker|FireMapZone
     *
     * @param  T  $item
     * @return T
     */
    private function stamp(FireMapMarker|FireMapZone $item, FireIncident $incident): FireMapMarker|FireMapZone
    {
        $crew = CrewSession::for('firetab')->current();

        return $item->forceFill([
            'fire_incident_id' => $incident->id,
            'created_by_user_id' => auth()->id(),
            'personnel_id' => $crew['personnel_id'] ?? null,
            'vehicle_id' => $crew['vehicle_id'] ?? null,
        ]);
    }

    private function write(FireIncident $incident, \Closure $change, string $action, string $description): void
    {
        DB::transaction(function () use ($incident, $change, $action, $description) {
            $change();
            FireLog::record($incident, $action, $description);
            DB::afterCommit(fn () => MapChanged::dispatch($incident->id));
        });
    }

    /** @return array<string, mixed> */
    private static function attribution(FireMapMarker|FireMapZone $item): array
    {
        return [
            'created_by_user_id' => $item->created_by_user_id, 'personnel_id' => $item->personnel_id,
            'creator' => $item->creator?->name, 'personnel' => $item->personnel?->full_name, 'vehicle' => $item->vehicle?->name,
            'created_at' => $item->created_at?->toIso8601String(),
        ];
    }
}
