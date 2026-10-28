<?php

namespace Modules\FireTab\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $fire_incident_id
 * @property string $action
 * @property ?Carbon $created_at
 */
class FireIncidentLog extends Model
{
    protected $table = 'fire_incident_log';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
