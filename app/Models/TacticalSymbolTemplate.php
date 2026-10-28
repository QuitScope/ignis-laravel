<?php

namespace App\Models;

use Database\Factories\TacticalSymbolTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A named preset of tactical symbol parameters (keys of the taktische-zeichen-core library).
 *
 * @property int $id
 * @property string $name unique
 * @property array<string, mixed> $symbol
 * @property ?int $created_by
 * @property-read ?Carbon $created_at
 * @property-read ?Carbon $updated_at
 */
class TacticalSymbolTemplate extends Model
{
    /** @use HasFactory<TacticalSymbolTemplateFactory> */
    use HasFactory;

    protected $fillable = ['name', 'symbol', 'created_by'];

    protected function casts(): array
    {
        return ['symbol' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
