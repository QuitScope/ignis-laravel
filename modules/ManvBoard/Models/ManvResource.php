<?php

namespace Modules\ManvBoard\Models;

use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\ManvBoard\Enums\ResourceStatus;
use Modules\ManvBoard\Enums\ResourceType;

/**
 * @property int $id
 * @property int $manv_incident_id
 * @property ResourceType $type
 * @property string $label
 * @property ?string $callsign
 * @property ?string $vehicle_type
 * @property ?int $vehicle_id
 * @property ?string $location
 * @property ?string $crew
 * @property ?string $notes
 * @property ResourceStatus $status
 * @property ?Carbon $departed_at
 */
class ManvResource extends Model
{
    protected $table = 'manv_resources';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['type' => ResourceType::class, 'status' => ResourceStatus::class, 'departed_at' => 'datetime'];
    }

    /** @return BelongsTo<ManvIncident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(ManvIncident::class, 'manv_incident_id');
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return HasMany<ManvPatient, $this> */
    public function patients(): HasMany
    {
        return $this->hasMany(ManvPatient::class, 'manv_resource_id');
    }
}
