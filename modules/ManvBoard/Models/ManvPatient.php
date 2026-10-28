<?php

namespace Modules\ManvBoard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\ManvBoard\Enums\Sex;
use Modules\ManvBoard\Enums\Triage;

/**
 * A patient of a Lage. `vehicle_type`, `callsign`, `vehicle_location` are a snapshot of the assigned resource.
 *
 * @property int $id
 * @property int $manv_incident_id
 * @property string $number
 * @property ?string $last_name
 * @property ?string $first_name
 * @property ?Carbon $birth_date
 * @property Sex $sex
 * @property ?Triage $triage
 * @property ?Carbon $triaged_at
 * @property ?int $triaged_by
 * @property ?int $manv_resource_id
 * @property ?string $vehicle_type
 * @property ?string $callsign
 * @property ?string $vehicle_location
 * @property ?string $destination
 * @property ?int $destination_poi_id
 * @property ?Carbon $departed_at
 * @property ?string $injuries
 * @property ?string $notes
 * @property ?int $created_by
 * @property ?int $updated_by
 */
class ManvPatient extends Model
{
    protected $table = 'manv_patients';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['birth_date' => 'date', 'sex' => Sex::class, 'triage' => Triage::class, 'triaged_at' => 'datetime', 'departed_at' => 'datetime'];
    }

    /** @return BelongsTo<ManvIncident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(ManvIncident::class, 'manv_incident_id');
    }

    /** @return BelongsTo<ManvResource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(ManvResource::class, 'manv_resource_id');
    }
}
