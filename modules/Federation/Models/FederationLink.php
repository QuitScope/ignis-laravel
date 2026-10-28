<?php

namespace Modules\Federation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One peer instance. Both keys are encrypted at rest (APP_KEY) and hidden from serialisation; nothing is
 * mass-assignable (forceFill).
 *
 * @property int $id
 * @property string $instance_id
 * @property string $name
 * @property string $url
 * @property string $secret_outgoing
 * @property string $secret_incoming
 * @property bool $consume_personnel
 * @property bool $consume_enotf
 * @property bool $consume_fire
 * @property bool $provide_personnel
 * @property bool $provide_enotf
 * @property bool $provide_fire
 * @property int $sync_interval_minutes
 * @property bool $is_active
 * @property ?Carbon $confirmed_at
 * @property ?array<string, string> $cursors
 * @property ?Carbon $last_sync_at
 * @property string $last_sync_status
 * @property ?string $last_sync_error
 */
class FederationLink extends Model
{
    protected $table = 'federation_links';

    protected $guarded = ['*'];

    protected $hidden = ['secret_outgoing', 'secret_incoming'];

    protected function casts(): array
    {
        return [
            'secret_outgoing' => 'encrypted',
            'secret_incoming' => 'encrypted',
            'consume_personnel' => 'bool', 'consume_enotf' => 'bool', 'consume_fire' => 'bool',
            'provide_personnel' => 'bool', 'provide_enotf' => 'bool', 'provide_fire' => 'bool',
            'is_active' => 'bool',
            'confirmed_at' => 'datetime',
            'cursors' => 'array',
            'last_sync_at' => 'datetime',
        ];
    }
}
