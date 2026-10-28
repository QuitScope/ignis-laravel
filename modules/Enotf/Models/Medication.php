<?php

namespace Modules\Enotf\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Medication catalog entry (legacy intra_edivi_medikamente): substance (unique), brand, dose suggestions ("100 mg").
 *
 * @property int $id
 * @property string $substance
 * @property ?string $brand
 * @property ?list<string> $doses
 * @property int $sort
 * @property bool $is_active
 */
class Medication extends Model
{
    protected $table = 'enotf_medications';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['doses' => 'array', 'sort' => 'integer', 'is_active' => 'boolean'];
    }
}
