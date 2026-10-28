<?php

namespace Modules\Forms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An application type (Antragstyp). Written by the server only (forceFill).
 *
 * @property int $id
 * @property string $name
 * @property ?string $description
 * @property ?string $icon Lucide name
 * @property bool $is_active
 * @property bool $is_absence
 * @property int $sort_order
 * @property ?int $created_by
 */
class FormType extends Model
{
    protected $table = 'form_types';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['is_active' => 'bool', 'is_absence' => 'bool'];
    }

    /** @return HasMany<FormField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<FormSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    public function hasAbsenceFields(?int $exceptFieldId = null): bool
    {
        return $this->fields()->whereIn('key', ['von_datum', 'bis_datum'])->where('type', 'date')
            ->when($exceptFieldId !== null, fn ($q) => $q->whereKeyNot($exceptFieldId))->count() === 2;
    }
}
