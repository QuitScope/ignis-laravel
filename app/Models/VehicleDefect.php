<?php

namespace App\Models;

use Database\Factories\VehicleDefectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A reported defect of a vehicle. Blocking = `is_operable` false and not yet resolved.
 *
 * @property int $id
 * @property int $vehicle_id
 * @property string $title
 * @property ?string $description
 * @property string $category one of CATEGORIES (callers store unknown input as 'other')
 * @property bool $is_operable false when the defect blocks the vehicle
 * @property string $status one of STATUSES
 * @property ?int $reported_by null for crew without an account
 * @property ?string $reporter_name
 * @property ?int $assigned_to
 * @property ?int $resolved_by
 * @property ?Carbon $resolved_at
 * @property ?string $resolution_note
 * @property-read ?Carbon $created_at
 * @property-read ?Carbon $updated_at
 * @property-read Vehicle $vehicle
 */
class VehicleDefect extends Model
{
    /** @use HasFactory<VehicleDefectFactory> */
    use HasFactory;

    public const OPEN = 'open';

    public const IN_PROGRESS = 'in_progress';

    public const DEFERRED = 'deferred';

    public const RESOLVED = 'resolved';

    public const STATUSES = [self::OPEN, self::IN_PROGRESS, self::DEFERRED, self::RESOLVED];

    public const STATUS_LABELS = [
        self::OPEN => 'Offen',
        self::IN_PROGRESS => 'In Bearbeitung',
        self::DEFERRED => 'Aufgeschoben',
        self::RESOLVED => 'Gelöst',
    ];

    public const CATEGORIES = [
        'body', 'fit_out', 'battery', 'lighting', 'brakes', 'electrics', 'chassis', 'gearbox', 'engine', 'tyres',
        'service', 'warning_system', 'other', 'windscreen',
    ];

    protected $fillable = [
        'vehicle_id', 'title', 'description', 'category', 'is_operable', 'status', 'reported_by', 'reporter_name',
        'assigned_to', 'resolved_by', 'resolved_at', 'resolution_note',
    ];

    protected function casts(): array
    {
        return ['is_operable' => 'boolean', 'resolved_at' => 'datetime'];
    }

    /** @param Builder<VehicleDefect> $query */
    public function scopeBlocking(Builder $query): void
    {
        $query->where('is_operable', false)->where('status', '!=', self::RESOLVED);
    }

    /**
     * List order: by status in life-cycle order (CASE, portable unlike FIELD()), unresolved blocking ones first
     * within a status, then newest.
     *
     * @param  Builder<VehicleDefect>  $query
     */
    public function scopeInListOrder(Builder $query): void
    {
        $case = 'CASE status';
        foreach (self::STATUSES as $i => $status) {
            $case .= " WHEN '{$status}' THEN {$i}";
        }
        $query->orderByRaw($case.' ELSE '.count(self::STATUSES).' END')
            ->orderByRaw("CASE WHEN status != '".self::RESOLVED."' AND is_operable = ? THEN 0 ELSE 1 END", [false])
            ->orderByDesc('created_at')->orderByDesc('id');
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** @return HasMany<VehicleDefectEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(VehicleDefectEvent::class);
    }

    /** @return HasOne<VehicleDefectEvent, $this> */
    public function lastEvent(): HasOne
    {
        return $this->hasOne(VehicleDefectEvent::class)->latestOfMany();
    }
}
