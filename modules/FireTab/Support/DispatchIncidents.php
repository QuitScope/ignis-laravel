<?php

namespace Modules\FireTab\Support;

use App\Models\Vehicle;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\FireTab\Http\Controllers\FireIncidentController;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireIncidentVehicle;

/**
 * Fire incidents from the dispatch (EMD sync; no route here). Port of the legacy EmdSyncController::upsertFireIncident.
 * Only fire vehicles (service = 3) count: a mixed RTW + NEF + LHF dispatch creates the incident with the LHF only.
 */
final class DispatchIncidents
{
    public const PLACEHOLDER = 'BITTE ÄNDERN!';

    /**
     * Creates the incident for an unknown dispatch number, or appends new fire vehicles to a known one (never removes).
     *
     * @param  array{number: string|int, vehicles: list<string>, postal?: ?string, location_x?: float|string|null, location_y?: float|string|null, dispatch_code?: ?string, dispatch_issue?: ?string, caller_name?: ?string, caller_phonenumber?: ?string}  $dispatch  vehicles = vehicle names as the dispatch sends them
     * @return ?FireIncident null when the dispatch has no fire vehicle or the incident is finalized/archived
     */
    public static function ensure(array $dispatch): ?FireIncident
    {
        $number = (string) $dispatch['number'];
        $vehicles = Vehicle::query()->whereIn('name', $dispatch['vehicles'])->where('service', Vehicle::SERVICE_FIRE)
            ->orderBy('id')->get()->unique('name')->values();
        if ($number === '' || $vehicles->isEmpty()) {
            return null;
        }

        if (! FireIncident::query()->where('number', $number)->exists()) {
            try {
                return DB::transaction(function () use ($dispatch, $number, $vehicles) {
                    $incident = (new FireIncident)->forceFill(self::columns($dispatch, $number));
                    $incident->save();
                    foreach ($vehicles as $v) {
                        FireIncidentController::attach($incident, $v);
                    }
                    FireLog::record($incident, 'created', 'Einsatz automatisch durch Sync erstellt', system: true);

                    return $incident;
                });
            } catch (UniqueConstraintViolationException) {
            }
        }

        return DB::transaction(function () use ($number, $vehicles) {
            $incident = FireIncident::query()->where('number', $number)->lockForUpdate()->firstOrFail();
            if ($incident->finalized_at !== null || $incident->archived_at !== null) {
                return null;
            }
            $known = $incident->vehicles()->pluck('vehicle_id')->all();
            foreach ($vehicles->whereNotIn('id', $known) as $v) {
                FireIncidentController::attach($incident, $v);
                FireLog::record($incident, 'vehicle_added', "Fahrzeug {$v->name} durch Sync hinzugefügt", $v->id, system: true);
            }

            return $incident;
        });
    }

    /**
     * A dispatch status on the vehicle's row of the incident with this number (legacy: unreachable).
     * A finalized or archived incident stays untouched.
     *
     * @return ?bool null = vehicle not on an incident with this number yet (retried), false = only on a finalized/archived one, true = written
     */
    public static function applyStatus(Vehicle $vehicle, string $number, string $code, CarbonInterface $at): ?bool
    {
        $onIncident = fn () => FireIncidentVehicle::query()->where('vehicle_id', $vehicle->id)
            ->whereIn('fire_incident_id', FireIncident::query()->select('id')->where('number', $number));
        $rows = $onIncident()->whereIn('fire_incident_id', FireIncident::query()->select('id')->whereNull('finalized_at')->whereNull('archived_at'));

        if ($rows->update(['fms_status' => $code, 'fms_status_at' => $at->copy()->utc()]) > 0 || $rows->exists()) {
            return true;
        }

        return $onIncident()->exists() ? false : null;
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private static function columns(array $d, string $number): array
    {
        $filled = fn (string $k, int $max = 255) => is_scalar($d[$k] ?? null) && trim((string) $d[$k]) !== '' ? mb_substr(trim((string) $d[$k]), 0, $max) : null;
        $issue = $filled('dispatch_issue', 10000);

        return [
            'number' => $number,
            'location' => $filled('postal') ?? self::PLACEHOLDER,
            'keyword' => $filled('dispatch_code') ?? self::PLACEHOLDER,
            'location_x' => is_numeric($d['location_x'] ?? null) ? (float) $d['location_x'] : null,
            'location_y' => is_numeric($d['location_y'] ?? null) ? (float) $d['location_y'] : null,
            'caller_name' => $filled('caller_name'),
            'caller_contact' => $filled('caller_phonenumber'),
            'started_at' => now()->utc(),
            'notes' => ($issue === null ? '' : "EINSATZMELDUNG: $issue\n\n").'Automatisch erstellt durch Synchronisation',
        ];
    }
}
