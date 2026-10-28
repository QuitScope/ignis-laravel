<?php

namespace App\Models;

use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An editor document of a personnel record. draft -> issued happens once; issued is read-only.
 *
 * @property int $id
 * @property string $public_id 7 digits
 * @property ?int $template_id
 * @property int $personnel_id
 * @property string $title
 * @property array<string, mixed> $content ProseMirror JSON
 * @property string $status draft|issued
 * @property ?array<string, string> $frozen_values variables as they were when issued
 * @property ?string $pdf_path relative to the 'documents' disk
 * @property int $lock_version optimistic lock for saving a draft
 * @property ?int $created_by
 * @property ?int $issued_by
 * @property ?Carbon $issued_at
 * @property-read Personnel $personnel
 * @property-read ?DocumentTemplate $template
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    public const DRAFT = 'draft';

    public const ISSUED = 'issued';

    protected $fillable = [
        'public_id', 'template_id', 'personnel_id', 'title', 'content', 'status', 'frozen_values', 'pdf_path',
        'lock_version', 'created_by', 'issued_by', 'issued_at',
    ];

    protected function casts(): array
    {
        return ['content' => 'array', 'frozen_values' => 'array', 'lock_version' => 'integer', 'issued_at' => 'datetime'];
    }

    /** @return BelongsTo<Personnel, $this> */
    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }

    /** @return BelongsTo<DocumentTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'template_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
