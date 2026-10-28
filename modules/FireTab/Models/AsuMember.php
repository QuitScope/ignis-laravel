<?php

namespace Modules\FireTab\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\FireTab\Enums\AsuPosition;

/**
 * @property int $id
 * @property int $trupp_id
 * @property AsuPosition $position
 * @property string $name
 * @property ?int $personnel_id
 */
class AsuMember extends Model
{
    protected $table = 'fire_asu_members';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['position' => AsuPosition::class];
    }
}
