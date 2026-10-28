<?php

namespace App\Models;

use Database\Factories\PersonnelFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property ?int $title_id
 * @property string $full_name
 * @property Carbon $birth_date
 * @property ?string $character_id
 * @property int $gender 0 male, 1 female, 2 diverse
 * @property ?int $forum_profile
 * @property ?string $discord_id
 * @property ?string $phone
 * @property string $service_number
 * @property Carbon $hired_at
 * @property int $rank_id
 * @property int $fire_qualification_id
 * @property int $ems_qualification_id
 * @property ?string $extra_qualification
 * @property ?string $profile_picture
 * @property-read Rank $rank
 * @property-read ?PersonnelTitle $title
 * @property-read ?User $user
 */
class Personnel extends Model
{
    /** @use HasFactory<PersonnelFactory> */
    use HasFactory;

    /** The only `profile_picture` values that are ever served or deleted: a generated name, no path. */
    public const PICTURE_PATTERN = '/^[a-f0-9]{32}\.(png|jpg|webp)$/D';

    public const PICTURE_MAX_BYTES = 2 * 1024 * 1024;

    public const PICTURE_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    protected $table = 'personnel';

    protected $fillable = [
        'title_id', 'full_name', 'birth_date', 'character_id', 'gender', 'forum_profile', 'discord_id', 'phone',
        'service_number', 'hired_at', 'rank_id', 'fire_qualification_id', 'ems_qualification_id',
        'extra_qualification', 'profile_picture',
    ];

    protected function casts(): array
    {
        return ['birth_date' => 'date', 'hired_at' => 'date', 'gender' => 'integer', 'forum_profile' => 'integer'];
    }

    public function pictureUrl(): ?string
    {
        $name = $this->profile_picture;

        return $name !== null && preg_match(self::PICTURE_PATTERN, $name) === 1 ? '/storage/profile-pictures/'.$name : null;
    }

    /** Deletes a picture file; anything but a generated name (legacy paths, junk) is left alone. */
    public static function deletePictureFile(?string $name): void
    {
        if ($name !== null && preg_match(self::PICTURE_PATTERN, $name) === 1) {
            Storage::disk('profile-pictures')->delete($name);
        }
    }

    public function displayName(): string
    {
        return trim(($this->title->name ?? '').' '.$this->full_name);
    }

    /** @param Builder<Personnel> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereHas('rank', fn (Builder $q) => $q->where('is_archive', false));
    }

    /** @param Builder<Personnel> $query */
    public function scopeArchived(Builder $query): void
    {
        $query->whereHas('rank', fn (Builder $q) => $q->where('is_archive', true));
    }

    /** @return BelongsTo<Rank, $this> */
    public function rank(): BelongsTo
    {
        return $this->belongsTo(Rank::class);
    }

    /** @return BelongsTo<FireQualification, $this> */
    public function fireQualification(): BelongsTo
    {
        return $this->belongsTo(FireQualification::class);
    }

    /** @return BelongsTo<EmsQualification, $this> */
    public function emsQualification(): BelongsTo
    {
        return $this->belongsTo(EmsQualification::class);
    }

    /** @return BelongsTo<PersonnelTitle, $this> */
    public function title(): BelongsTo
    {
        return $this->belongsTo(PersonnelTitle::class, 'title_id');
    }

    /** @return BelongsToMany<Specialty, $this> */
    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(Specialty::class, 'personnel_specialty');
    }

    /** @return HasMany<PersonnelLogEntry, $this> */
    public function logEntries(): HasMany
    {
        return $this->hasMany(PersonnelLogEntry::class);
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /** @return HasMany<LegacyDocument, $this> */
    public function legacyDocuments(): HasMany
    {
        return $this->hasMany(LegacyDocument::class);
    }

    /** @return HasOne<User, $this> */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }
}
