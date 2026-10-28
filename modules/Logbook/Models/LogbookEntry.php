<?php

namespace Modules\Logbook\Models;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One trip. Date and times are wall-clock values as written (no time zone); `arrived_at` earlier than `departed_at`
 * means the trip ended after midnight. `source`, `created_by` and the vehicle texts are set by the server only
 * (nothing is mass-assignable; the service uses forceFill).
 *
 * @property int $id
 * @property ?int $vehicle_id
 * @property string $vehicle_identifier
 * @property string $trip_date Y-m-d
 * @property string $departed_at H:i:s
 * @property ?string $arrived_at H:i:s
 * @property ?string $station_name
 * @property float $distance_km
 * @property ?string $reason
 * @property string $trip_type
 * @property string $driver_name
 * @property string $source
 * @property ?int $created_by
 * @property-read ?Carbon $created_at
 * @property-read ?Carbon $updated_at
 */
class LogbookEntry extends Model
{
    public const TYPES = ['einsatz', 'bewegung', 'werkstatt', 'uebung', 'dienst', 'sonstige'];

    public const SOURCES = ['enotf', 'firetab', 'admin'];

    protected $table = 'logbook_entries';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['distance_km' => 'float'];
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
