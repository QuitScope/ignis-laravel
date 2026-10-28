<?php

namespace Modules\Forms\Models;

use App\Models\User;
use App\Support\SqlLike;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Forms\Events\FormDeleted;
use Modules\Forms\Support\AnswerValidator;

/**
 * One submitted application. `answers` is the snapshot [{key, label, type, width, value}] taken from the type's fields
 * at submission: never joined with the current fields, never edited. Written by the server only.
 *
 * @property int $id
 * @property string $public_id 6 digits, shown and used in URLs; not a permission
 * @property int $form_type_id
 * @property ?int $personnel_id
 * @property string $applicant_name
 * @property ?string $applicant_rank
 * @property ?string $legacy_discord_id
 * @property string $status new|in_progress|deferred|accepted|rejected
 * @property ?int $decided_by
 * @property ?string $decided_by_name
 * @property ?string $decision_note
 * @property Carbon $submitted_at
 * @property ?Carbon $decided_at
 * @property mixed $answers
 * @property-read FormType $type
 */
class FormSubmission extends Model
{
    public const STATUSES = ['new', 'in_progress', 'deferred', 'accepted', 'rejected'];

    public const OPEN = ['new', 'in_progress'];

    public const PER_PAGE = 25;

    protected $table = 'form_submissions';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['answers' => 'array', 'submitted_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleted(fn (FormSubmission $s) => FormDeleted::dispatch($s->id, $s->form_type_id));
    }

    /** @return BelongsTo<FormType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(FormType::class, 'form_type_id');
    }

    public function isOwnedBy(User $user): bool
    {
        return $user->personnel_id !== null && $this->personnel_id !== null && (int) $this->personnel_id === (int) $user->personnel_id;
    }

    /**
     * Status and search of a list, from the query string (anything but a known string counts as absent).
     *
     * @return array{status: string, q: string}
     */
    public static function filters(Request $request): array
    {
        $status = $request->query('status');
        $q = $request->query('q');

        return [
            'status' => is_string($status) && ($status === 'open' || in_array($status, self::STATUSES, true)) ? $status : '',
            'q' => is_string($q) ? mb_substr(trim($q), 0, 100) : '',
        ];
    }

    /**
     * A list, newest first, with its type (one query for the page, one for the types).
     *
     * @param  array{status: string, q: string}  $filters
     * @return Builder<self>
     */
    public static function listing(array $filters): Builder
    {
        return self::query()->with('type:id,name,icon')
            ->when($filters['status'] === 'open', fn (Builder $q) => $q->whereIn('status', self::OPEN))
            ->when(in_array($filters['status'], self::STATUSES, true), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when($filters['q'] !== '', fn (Builder $q) => SqlLike::anyOf($q, ['public_id', 'applicant_name',
                '(select form_types.name from form_types where form_types.id = form_submissions.form_type_id)'], $filters['q']))
            ->orderByDesc('submitted_at')->orderByDesc('id');
    }

    /** @return array{public_id: string, type: string, icon: ?string, applicant_name: string, status: string, submitted_at: string, decided_by_name: ?string, href: string} */
    public function row(): array
    {
        return [
            'public_id' => $this->public_id,
            'type' => $this->type->name,
            'icon' => $this->type->icon,
            'applicant_name' => $this->applicant_name,
            'status' => $this->status,
            'submitted_at' => $this->submitted_at->toIso8601ZuluString(),
            'decided_by_name' => $this->decided_by_name,
            'href' => "/forms/{$this->public_id}",
        ];
    }

    /** @return array<string, mixed> the row plus decision and answers, for the detail pages */
    public function present(): array
    {
        return $this->row() + [
            'applicant_rank' => $this->applicant_rank,
            'decided_at' => $this->decided_at?->toIso8601ZuluString(),
            'decision_note' => $this->decision_note,
            'answers' => $this->presentAnswers(),
        ];
    }

    /**
     * The snapshot as shown, read defensively (imported rows): `display` is the text to print (checkbox Ja/Nein from
     * the stored bool, dates as d.m.Y), every value plain text.
     *
     * @return list<array{key: string, label: string, type: string, width: string, value: string|bool, display: string}>
     */
    public function presentAnswers(): array
    {
        $out = [];
        foreach (is_array($this->answers) ? $this->answers : [] as $answer) {
            if (! is_array($answer)) {
                continue;
            }
            $type = in_array($answer['type'] ?? null, FormField::TYPES, true) ? $answer['type'] : 'text';
            $raw = $answer['value'] ?? '';
            if ($type === 'checkbox') {
                $value = is_bool($raw) ? $raw : is_scalar($raw) && AnswerValidator::checked((string) $raw);
                $display = __($value ? 'forms.yes' : 'forms.no');
            } else {
                $value = is_scalar($raw) ? (string) $raw : '';
                $display = $type === 'date' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) === 1 ? "{$m[3]}.{$m[2]}.{$m[1]}" : $value;
            }
            $out[] = [
                'key' => is_string($answer['key'] ?? null) ? $answer['key'] : '',
                'label' => is_string($answer['label'] ?? null) ? $answer['label'] : '',
                'type' => $type,
                'width' => ($answer['width'] ?? null) === 'half' ? 'half' : 'full',
                'value' => $value,
                'display' => $display,
            ];
        }

        return $out;
    }
}
