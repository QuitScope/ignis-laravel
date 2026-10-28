<?php

namespace Modules\FireTab\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\FireTab\Enums\SitrepSource;

/**
 * @property int $id
 * @property int $fire_incident_id
 * @property Carbon $reported_at
 * @property string $text
 * @property ?string $reporter_name
 * @property SitrepSource $source
 * @property bool $is_important
 * @property ?Carbon $synced_at
 */
class FireSitrep extends Model
{
    protected $table = 'fire_sitreps';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['reported_at' => 'datetime', 'source' => SitrepSource::class, 'is_important' => 'boolean', 'synced_at' => 'datetime'];
    }
}
