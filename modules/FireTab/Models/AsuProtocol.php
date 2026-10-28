<?php

namespace Modules\FireTab\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Legacy per-supervisor ASU JSON (/api/asu/sync), read-only in the report.
 *
 * @property int $id
 * @property int $fire_incident_id
 * @property string $supervisor
 * @property ?string $mission_location
 * @property ?Carbon $mission_date
 * @property ?Carbon $recorded_at
 * @property array<mixed> $payload
 */
class AsuProtocol extends Model
{
    protected $table = 'fire_asu_protocols';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['mission_date' => 'date', 'recorded_at' => 'datetime', 'payload' => 'array'];
    }
}
