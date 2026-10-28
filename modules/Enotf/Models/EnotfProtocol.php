<?php

namespace Modules\Enotf\Models;

use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Enotf\Enums\PatientSync;
use Modules\Enotf\Enums\ProtocolOrigin;
use Modules\Enotf\Enums\ProtocolStatus;
use Modules\Enotf\Enums\QmStatus;

/**
 * One eNOTF patient protocol: header columns, the `data` JSON under the legacy keys (FieldSchema), crew
 * rows, `legacy` for what the importer could not map. Nothing is mass-assignable: the server sets every field
 * (forceFill). Routes bind it by `enr`.
 *
 * @property int $id
 * @property string $enr
 * @property ProtocolStatus $status
 * @property ProtocolOrigin $origin
 * @property QmStatus $qm_status
 * @property ?Carbon $hidden_at
 * @property ?Carbon $released_at
 * @property ?Carbon $withdrawn_at
 * @property ?Carbon $billed_at
 * @property int $revision
 * @property ?int $destination_poi_id
 * @property ?array<string, int> $field_revisions
 * @property ?int $vehicle_transport_id
 * @property ?int $vehicle_na_id
 * @property ?string $vehicle_transport_label
 * @property ?string $vehicle_na_label
 * @property ?Carbon $mission_date
 * @property ?string $mission_time
 * @property ?string $patient_first_name
 * @property ?string $patient_last_name
 * @property ?Carbon $patient_birthdate
 * @property ?int $created_by_user_id
 * @property ?array<string, mixed> $data
 * @property ?array<string, mixed> $legacy
 * @property PatientSync $patient_sync
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class EnotfProtocol extends Model
{
    protected $table = 'enotf_protocols';

    protected $guarded = ['*'];

    protected $attributes = ['status' => 'open', 'origin' => 'manual', 'qm_status' => 0, 'revision' => 1, 'patient_sync' => 'none'];

    protected function casts(): array
    {
        return [
            'status' => ProtocolStatus::class,
            'origin' => ProtocolOrigin::class,
            'qm_status' => QmStatus::class,
            'patient_sync' => PatientSync::class,
            'hidden_at' => 'datetime',
            'released_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'billed_at' => 'datetime',
            'alarmed_at' => 'datetime',
            'ended_at' => 'datetime',
            'mission_date' => 'date',
            'patient_birthdate' => 'date',
            'revision' => 'integer',
            'data' => 'array',
            'field_revisions' => 'array',
            'legacy' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'enr';
    }

    /** @return HasMany<ProtocolCrew, $this> */
    public function crew(): HasMany
    {
        return $this->hasMany(ProtocolCrew::class, 'enotf_protocol_id');
    }

    /**
     * Vital sign rows, deleted ones included (scopeBindings of /vitals/{vital}).
     *
     * @return HasMany<VitalSign, $this>
     */
    public function vitals(): HasMany
    {
        return $this->hasMany(VitalSign::class, 'enotf_protocol_id');
    }

    /**
     * Given medication rows, deleted ones included (scopeBindings of /medications/{medication}).
     *
     * @return HasMany<MedicationGiven, $this>
     */
    public function medications(): HasMany
    {
        return $this->hasMany(MedicationGiven::class, 'enotf_protocol_id');
    }

    /** @return HasMany<ProtocolReviewLog, $this> */
    public function reviewLog(): HasMany
    {
        return $this->hasMany(ProtocolReviewLog::class, 'enotf_protocol_id');
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function transportVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_transport_id');
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function naVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_na_id');
    }

    /**
     * Open, not hidden, the vehicle in either slot (overview list).
     *
     * @param  Builder<EnotfProtocol>  $query
     */
    public function scopeOpenForVehicle(Builder $query, int $vehicleId): void
    {
        $query->where('status', ProtocolStatus::Open)->whereNull('hidden_at')
            ->where(fn (Builder $q) => $q->where('vehicle_transport_id', $vehicleId)->orWhere('vehicle_na_id', $vehicleId));
    }

    public function isOpen(): bool
    {
        return $this->status === ProtocolStatus::Open && $this->hidden_at === null;
    }

    public function hasVehicle(?int $vehicleId): bool
    {
        return $vehicleId !== null && in_array($vehicleId, [$this->vehicle_transport_id, $this->vehicle_na_id], true);
    }
}
