<?php

namespace App\Models;

use Database\Factories\LegacyDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Lang;

/**
 * A document from before the editor, kept as it was (read-only apart from archiving and deleting).
 *
 * @property int $id
 * @property ?int $legacy_id id in the old table, for re-running the import
 * @property string $public_id 7 digits or XXXX-XXXX-XXXX
 * @property int $type legacy PersonnelDocument type
 * @property ?int $personnel_id
 * @property ?string $recipient_name
 * @property ?string $recipient_discord_id
 * @property int $salutation 0 male, 1 female, 2 diverse
 * @property ?Carbon $recipient_birthdate
 * @property ?Carbon $issued_on
 * @property ?int $issuer_user_id
 * @property ?string $issuer_discord_id
 * @property ?string $issuer_name
 * @property bool $is_archived
 * @property ?string $pdf_path file name on the 'documents' disk under legacy/
 * @property ?Carbon $pdf_generated_at
 * @property ?array<string, mixed> $legacy_payload
 * @property-read ?Carbon $created_at
 * @property-read ?User $issuerUser
 * @property-read ?Personnel $personnel
 */
class LegacyDocument extends Model
{
    /** @use HasFactory<LegacyDocumentFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** Names of imported legacy PDFs that may be fetched without login; 7-digit names never. */
    public const PUBLIC_PDF_PATTERN = '/^[A-Z2-9]{4}(-[A-Z2-9]{4}){2}\.pdf$/D';

    protected $fillable = [
        'legacy_id', 'public_id', 'type', 'personnel_id', 'recipient_name', 'recipient_discord_id', 'salutation',
        'recipient_birthdate', 'issued_on', 'issuer_user_id', 'issuer_discord_id', 'issuer_name', 'is_archived',
        'pdf_path', 'pdf_generated_at', 'legacy_payload', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => 'integer', 'salutation' => 'integer', 'recipient_birthdate' => 'date', 'issued_on' => 'date',
            'is_archived' => 'boolean', 'pdf_generated_at' => 'datetime', 'legacy_payload' => 'array',
        ];
    }

    public function issuerLabel(): string
    {
        return $this->issuer_name ?? $this->issuerUser->personnel->full_name ?? $this->issuerUser->name ?? 'Unbekannt';
    }

    public function typeLabel(): string
    {
        return self::labelFor($this->type);
    }

    public static function labelFor(?int $type): string
    {
        return __(Lang::has("documents.legacy_type.{$type}") ? "documents.legacy_type.{$type}" : 'documents.legacy_type.unknown');
    }

    /** @return BelongsTo<Personnel, $this> */
    public function personnel(): BelongsTo
    {
        return $this->belongsTo(Personnel::class);
    }

    /** @return BelongsTo<User, $this> */
    public function issuerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issuer_user_id');
    }
}
