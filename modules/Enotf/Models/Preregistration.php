<?php

namespace Modules\Enotf\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pre-registration of a patient at a hospital: open -> seen -> arrived by the clinic device, cancelled by
 * the crew; on the board while open/seen and not past expires_at. Nothing is mass-assignable.
 *
 * @property int $id
 * @property int $enotf_protocol_id
 * @property int $poi_id
 * @property string $status
 * @property int $priority
 * @property ?Carbon $eta
 * @property ?string $note
 * @property ?int $created_by_user_id
 * @property Carbon $created_at
 * @property ?Carbon $updated_at
 * @property Carbon $expires_at
 */
class Preregistration extends Model
{
    public const ACTIVE = ['open', 'seen'];

    protected $table = 'enotf_preregistrations';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['priority' => 'integer', 'eta' => 'datetime', 'expires_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    /** @return BelongsTo<EnotfProtocol, $this> */
    public function protocol(): BelongsTo
    {
        return $this->belongsTo(EnotfProtocol::class, 'enotf_protocol_id');
    }

    /**
     * Still shown: open or seen and not expired.
     *
     * @param  Builder<Preregistration>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', self::ACTIVE)->where('expires_at', '>', now());
    }
}
