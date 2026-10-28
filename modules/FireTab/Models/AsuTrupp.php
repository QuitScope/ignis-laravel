<?php

namespace Modules\FireTab\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\FireTab\Enums\AsuRole;

/**
 * @property int $id
 * @property int $fire_incident_id
 * @property string $name
 * @property AsuRole $role
 * @property ?string $task
 * @property int $expected_minutes
 * @property ?int $vehicle_id
 */
class AsuTrupp extends Model
{
    protected $table = 'fire_asu_trupps';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['role' => AsuRole::class];
    }

    /** @return HasMany<AsuMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(AsuMember::class, 'trupp_id');
    }

    /** @return HasMany<AsuEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(AsuEvent::class, 'trupp_id');
    }
}
