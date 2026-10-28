<?php

namespace Modules\Enotf\Models;

use App\Models\Personnel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A member of a vehicle crew: always a personnel record, with an account when they joined with one.
 *
 * @property int $id
 * @property int $enotf_crew_id
 * @property int $position
 * @property int $personnel_id
 * @property ?int $user_id
 * @property Carbon $joined_at
 * @property ?Carbon $left_at
 */
class CrewMember extends Model
{
    protected $table = 'enotf_crew_members';

    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'left_at' => 'datetime'];
    }

    /** @return BelongsTo<EnotfCrew, $this> */
    public function crew(): BelongsTo
    {
        return $this->belongsTo(EnotfCrew::class, 'enotf_crew_id');
    }

    /** @return BelongsTo<Personnel, $this> */
    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }
}
