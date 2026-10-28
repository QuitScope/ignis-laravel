<?php

namespace Modules\Enotf\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The access code of a hospital's clinic devices: only the hash is stored, the plain code is shown once.
 *
 * @property int $id
 * @property int $poi_id
 * @property string $code_hash
 * @property Carbon $rotated_at
 * @property ?int $created_by_user_id
 * @property ?Carbon $last_used_at
 */
class HospitalAccessCode extends Model
{
    protected $table = 'enotf_hospital_access_codes';

    protected $guarded = ['*'];

    public $timestamps = false;

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['rotated_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->code_hash);
    }
}
