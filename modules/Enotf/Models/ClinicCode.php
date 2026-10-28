<?php

namespace Modules\Enotf\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Clinic code: read access to exactly one protocol until expires_at (1 h). Only the hash of
 * the secret part is stored; the code `<id>-<secret>` is shown once. Nothing is mass-assignable.
 *
 * @property int $id
 * @property int $enotf_protocol_id
 * @property string $code_hash
 * @property Carbon $expires_at
 * @property ?int $created_by_user_id
 * @property ?Carbon $last_used_at
 * @property Carbon $created_at
 */
class ClinicCode extends Model
{
    public const SECRET_LENGTH = 10;

    protected $table = 'enotf_clinic_codes';

    protected $guarded = ['*'];

    public const UPDATED_AT = null;

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'last_used_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
