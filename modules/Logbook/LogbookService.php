<?php

namespace Modules\Logbook;

use App\Models\Poi;
use App\Models\User;
use App\Models\Vehicle;
use App\Modules\ModuleRegistry;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Logbook\Models\LogbookEntry;

/**
 * Writes trips. The admin page and the hooks for eNOTF/FireTab go through here: vehicle, driver and source
 * come from the caller (the session there), never from the form. Mapping: `vehicle_identifier` = Vehicle::identifier,
 * `station_name` = name (and city) of the vehicle's station (Poi) at the time of booking.
 */
class LogbookService
{
    public const MODULE = 'Fahrtenbuch';

    public const DRIVER_RULES = ['required', 'string', 'max:255'];

    /** @return array<string, mixed> rules of the trip data (shared with the admin request) */
    public static function rules(): array
    {
        return [
            'trip_date' => ['required', 'date_format:Y-m-d'],
            'departed_at' => ['required', 'date_format:H:i'],
            'arrived_at' => ['nullable', 'date_format:H:i'],
            'distance_km' => ['required', 'numeric', 'min:0', 'max:99999.9', 'decimal:0,1'],
            'reason' => ['nullable', 'string', 'max:5000'],
            'trip_type' => ['required', Rule::in(LogbookEntry::TYPES)],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  trip_date, departed_at, arrived_at, distance_km, reason, trip_type
     *
     * @throws ValidationException
     * @throws LogicException when the module `logbook` is switched off
     */
    public function record(array $data, Vehicle $vehicle, string $driver, string $source, ?User $by): LogbookEntry
    {
        throw_unless(ModuleRegistry::active('logbook'), LogicException::class, 'Module logbook is switched off.');

        $valid = Validator::make(['driver_name' => trim($driver), 'source' => $source] + $data, self::rules() + [
            'driver_name' => self::DRIVER_RULES,
            'source' => ['required', Rule::in(LogbookEntry::SOURCES)],
        ])->validate();

        $entry = (new LogbookEntry)->forceFill(self::trip($valid) + self::vehicleTexts($vehicle) + [
            'driver_name' => $valid['driver_name'], 'source' => $valid['source'], 'created_by' => $by?->id,
        ]);
        $entry->save();

        if ($by !== null) {
            self::audit($by, 'Fahrtenbuch-Eintrag erstellt', $entry);
        }

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $valid
     * @return array<string, mixed>
     */
    public static function trip(array $valid): array
    {
        return [
            'trip_date' => $valid['trip_date'], 'departed_at' => $valid['departed_at'], 'arrived_at' => $valid['arrived_at'] ?? null,
            'distance_km' => $valid['distance_km'], 'reason' => $valid['reason'] ?? null, 'trip_type' => $valid['trip_type'],
        ];
    }

    /** @return array{vehicle_id: int, vehicle_identifier: string, station_name: ?string} */
    public static function vehicleTexts(Vehicle $vehicle): array
    {
        $poi = $vehicle->station_poi_id === null ? null : Poi::find($vehicle->station_poi_id, ['name', 'city']);
        $city = $poi === null ? '' : trim($poi->city);
        $station = $poi === null ? '' : trim($poi->name.($city === '' ? '' : ' ('.$city.')'));

        return [
            'vehicle_id' => $vehicle->id,
            'vehicle_identifier' => mb_substr($vehicle->identifier, 0, 64),
            'station_name' => $station === '' ? null : mb_substr($station, 0, 255),
        ];
    }

    public static function audit(User $by, string $action, LogbookEntry $entry): void
    {
        AuditLogger::log($by, $action.' [ID: '.$entry->id.']', $entry->vehicle_identifier.', '.$entry->trip_date, self::MODULE, true, ['id' => $entry->id]);
    }
}
