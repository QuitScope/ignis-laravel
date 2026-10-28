<?php

namespace Modules\ManvBoard\Models;

use App\Models\Personnel;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\ManvBoard\Enums\ManvIncidentStatus;

/**
 * One mass-casualty incident (Lage). Nothing is mass-assignable: the server sets names, creators and status (forceFill).
 *
 * @property int $id
 * @property string $incident_number
 * @property string $location
 * @property ?string $reason
 * @property ?int $lna_personnel_id
 * @property ?string $lna_name
 * @property ?int $orgl_personnel_id
 * @property ?string $orgl_name
 * @property ManvIncidentStatus $status
 * @property Carbon $started_at
 * @property ?Carbon $ended_at
 * @property ?string $notes
 * @property ?int $created_by
 * @property ?int $updated_by
 */
class ManvIncident extends Model
{
    protected $table = 'manv_incidents';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['status' => ManvIncidentStatus::class, 'started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    /** @return HasMany<ManvPatient, $this> */
    public function patients(): HasMany
    {
        return $this->hasMany(ManvPatient::class, 'manv_incident_id');
    }

    /** @return HasMany<ManvResource, $this> */
    public function resources(): HasMany
    {
        return $this->hasMany(ManvResource::class, 'manv_incident_id');
    }

    /** @return HasMany<ManvLogEntry, $this> */
    public function log(): HasMany
    {
        return $this->hasMany(ManvLogEntry::class, 'manv_incident_id');
    }

    /** @return BelongsTo<Personnel, $this> */
    public function lna(): BelongsTo
    {
        return $this->belongsTo(Personnel::class, 'lna_personnel_id');
    }

    /** @return BelongsTo<Personnel, $this> */
    public function orgl(): BelongsTo
    {
        return $this->belongsTo(Personnel::class, 'orgl_personnel_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
