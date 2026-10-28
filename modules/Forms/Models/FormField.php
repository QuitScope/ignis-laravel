<?php

namespace Modules\Forms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A field of an application type; created, deleted and sorted, never edited (answers are snapshots).
 *
 * @property int $id
 * @property int $form_type_id
 * @property string $key
 * @property string $label
 * @property string $type
 * @property ?list<string> $options
 * @property bool $required
 * @property ?string $placeholder
 * @property ?string $hint
 * @property string $width
 * @property ?string $default_value
 * @property bool $readonly
 * @property ?string $auto_fill
 * @property int $sort_order
 */
class FormField extends Model
{
    public const TYPES = ['text', 'textarea', 'number', 'date', 'select', 'checkbox', 'email', 'time', 'tel'];

    public const WIDTHS = ['full', 'half'];

    public const AUTO_FILL = ['fullname_dienstnr', 'fullname', 'dienstnr', 'dienstgrad', 'discordtag'];

    protected $table = 'form_fields';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['options' => 'array', 'required' => 'bool', 'readonly' => 'bool'];
    }

    /** @return BelongsTo<FormType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(FormType::class, 'form_type_id');
    }
}
