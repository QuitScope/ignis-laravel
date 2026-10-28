<?php

namespace Modules\FireTab\Models;

use App\Models\Personnel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\FireTab\Enums\FireIncidentStatus;

/**
 * One fire-service operation protocol. Nothing is mass-assignable: the server sets every field (forceFill).
 *
 * @property int $id
 * @property ?string $number
 * @property ?string $location
 * @property ?string $location_x GTA world coordinate (decimal)
 * @property ?string $location_y
 * @property ?string $keyword
 * @property ?string $caller_name
 * @property ?string $caller_contact
 * @property ?string $owner_name
 * @property ?string $owner_contact
 * @property ?string $notes
 * @property ?int $leader_personnel_id
 * @property ?string $leader_external_name
 * @property FireIncidentStatus $status
 * @property ?Carbon $started_at
 * @property ?Carbon $finalized_at
 * @property ?Carbon $archived_at
 * @property Carbon $created_at
 * @property ?Carbon $updated_at
 */
class FireIncident extends Model
{
    protected $table = 'fire_incidents';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => FireIncidentStatus::class,
            'started_at' => 'datetime',
            'finalized_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** @return HasMany<FireIncidentVehicle, $this> */
    public function vehicles(): HasMany
    {
        return $this->hasMany(FireIncidentVehicle::class, 'fire_incident_id');
    }

    /** @return HasMany<FireSitrep, $this> */
    public function sitreps(): HasMany
    {
        return $this->hasMany(FireSitrep::class, 'fire_incident_id');
    }

    /** @return HasMany<FireIncidentLog, $this> */
    public function log(): HasMany
    {
        return $this->hasMany(FireIncidentLog::class, 'fire_incident_id');
    }

    /** @return HasMany<AsuProtocol, $this> */
    public function asuProtocols(): HasMany
    {
        return $this->hasMany(AsuProtocol::class, 'fire_incident_id');
    }

    /** @return HasMany<AsuTrupp, $this> scoped binding {asuTrupp} */
    public function asuTrupps(): HasMany
    {
        return $this->hasMany(AsuTrupp::class, 'fire_incident_id');
    }

    /** @return HasMany<AsuEvent, $this> scoped binding {asuEvent} */
    public function asuEvents(): HasMany
    {
        return $this->hasMany(AsuEvent::class, 'fire_incident_id');
    }

    /** @return HasMany<FireMapMarker, $this> scoped binding {mapMarker} */
    public function mapMarkers(): HasMany
    {
        return $this->hasMany(FireMapMarker::class, 'fire_incident_id');
    }

    /** @return HasMany<FireMapZone, $this> scoped binding {mapZone} */
    public function mapZones(): HasMany
    {
        return $this->hasMany(FireMapZone::class, 'fire_incident_id');
    }

    /** @return BelongsTo<Personnel, $this> */
    public function leader(): BelongsTo
    {
        return $this->belongsTo(Personnel::class, 'leader_personnel_id')->with('title');
    }

    public function leaderName(): ?string
    {
        return $this->leader?->displayName() ?? $this->leader_external_name;
    }
}
