<?php

namespace Modules\FireTab\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $fire_incident_id
 * @property ?int $vehicle_id
 * @property ?string $name
 * @property ?string $identifier
 * @property ?string $radio_name
 * @property bool $is_external
 * @property ?Carbon $fms_status_at
 */
class FireIncidentVehicle extends Model
{
    protected $table = 'fire_incident_vehicles';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['is_external' => 'boolean', 'fms_status_at' => 'datetime'];
    }
}
