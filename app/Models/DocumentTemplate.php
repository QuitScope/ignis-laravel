<?php

namespace App\Models;

use Database\Factories\DocumentTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property ?string $category
 * @property array<string, mixed> $content ProseMirror JSON
 * @property bool $is_active
 * @property ?int $created_by
 * @property-read ?Carbon $created_at
 */
class DocumentTemplate extends Model
{
    /** @use HasFactory<DocumentTemplateFactory> */
    use HasFactory;

    protected $fillable = ['name', 'category', 'content', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['content' => 'array', 'is_active' => 'boolean'];
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'template_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
