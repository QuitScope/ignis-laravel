<?php

namespace Modules\KnowledgeBase\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Modules\KnowledgeBase\Content\KbContentRenderer;

/**
 * One article. `content` and every value of `sections` are Tiptap documents; nothing is mass-assignable (the entry
 * pages use forceFill after validating).
 *
 * Legacy column -> new place (the importer needs this table; the legacy HTML is converted to Tiptap JSON):
 *
 *   content                 -> content
 *   med_wirkstoff           -> active_ingredient (plain column)
 *   med_wirkstoffgruppe     -> drug_class (plain column)
 *   med_wirkmechanismus     -> sections.med_mechanism_of_action
 *   med_indikationen        -> sections.med_indications
 *   med_kontraindikationen  -> sections.med_contraindications
 *   med_uaw                 -> sections.med_side_effects
 *   med_dosierung           -> sections.med_dosage
 *   med_besonderheiten      -> sections.med_special_notes
 *   mass_wirkprinzip        -> sections.mass_principle
 *   mass_indikationen       -> sections.mass_indications
 *   mass_kontraindikationen -> sections.mass_contraindications
 *   mass_risiken            -> sections.mass_risks
 *   mass_alternativen       -> sections.mass_alternatives
 *   mass_durchfuehrung      -> sections.mass_procedure
 *
 * @property int $id
 * @property string $type
 * @property ?int $category_id
 * @property string $title
 * @property ?string $subtitle
 * @property ?string $competency_level
 * @property ?array<string, mixed> $content
 * @property ?array<string, array<string, mixed>> $sections
 * @property ?string $active_ingredient
 * @property ?string $drug_class
 * @property string $search_text
 * @property bool $is_pinned
 * @property bool $is_archived
 * @property bool $hide_editor
 * @property ?int $created_by
 * @property ?int $updated_by
 * @property-read ?Carbon $created_at
 * @property-read ?Carbon $updated_at
 */
class KbEntry extends Model
{
    public const TYPES = ['general', 'medication', 'measure'];

    public const COMPETENCY_LEVELS = ['basis', 'rettsan', 'notsan_2c', 'notsan_2a', 'notarzt'];

    public const SECTIONS_BY_TYPE = [
        'medication' => ['med_mechanism_of_action', 'med_indications', 'med_contraindications', 'med_side_effects', 'med_dosage', 'med_special_notes'],
        'measure' => ['mass_principle', 'mass_indications', 'mass_contraindications', 'mass_risks', 'mass_alternatives', 'mass_procedure'],
    ];

    public const SECTIONS = [
        'med_mechanism_of_action', 'med_indications', 'med_contraindications', 'med_side_effects', 'med_dosage', 'med_special_notes',
        'mass_principle', 'mass_indications', 'mass_contraindications', 'mass_risks', 'mass_alternatives', 'mass_procedure',
    ];

    /**
     * Every section key, whatever the entry's type. The importer must not drop non-empty legacy med_* / mass_*
     * data on an entry of another type: it stores every non-empty section in `sections` under these keys (least lossy;
     * the entry pages show the sections of the entry's type plus any other non-empty one).
     *
     * @return list<string>
     */
    public static function allSectionKeys(): array
    {
        return self::SECTIONS;
    }

    protected $table = 'kb_entries';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'content' => 'array', 'sections' => 'array',
            'is_pinned' => 'boolean', 'is_archived' => 'boolean', 'hide_editor' => 'boolean',
        ];
    }

    public function buildSearchText(): string
    {
        $parts = [$this->title, $this->subtitle, $this->active_ingredient, $this->drug_class, self::plain($this->content)];
        foreach ($this->sections ?? [] as $doc) {
            $parts[] = self::plain($doc);
        }

        return implode("\n", array_filter($parts, fn ($p) => $p !== null && $p !== ''));
    }

    private static function plain(mixed $doc): string
    {
        try {
            return is_array($doc) && $doc !== [] ? KbContentRenderer::plainText($doc) : '';
        } catch (InvalidArgumentException) {
            return '';
        }
    }

    /** @return BelongsTo<KbCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(KbCategory::class, 'category_id');
    }

    /** @return BelongsToMany<KbTag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(KbTag::class, 'kb_entry_tag', 'entry_id', 'tag_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
