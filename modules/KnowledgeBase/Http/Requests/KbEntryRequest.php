<?php

namespace Modules\KnowledgeBase\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Modules\KnowledgeBase\Content\KbContentValidator;
use Modules\KnowledgeBase\Models\KbEntry;

/**
 * Create/update of an entry (kb.edit). `content` and every `sections.<key>` arrive as JSON STRINGS (TrimStrings and
 * ConvertEmptyStringsToNull must not touch text nodes) and go through KbContentValidator::validate(); its reasons become
 * the 422 message of that field. Only fields that are sent are returned by values(): a missing editor field keeps the
 * stored value, an explicit null clears it. The type is fixed at creation. `is_pinned` and `hide_editor` are never read.
 */
class KbEntryRequest extends FormRequest
{
    /** @var array<string, mixed> */
    private array $clean = [];

    private ?KbEntry $entry = null;

    public function authorize(): bool
    {
        if (! $this->user()?->can('edit', KbEntry::class)) {
            return false;
        }
        // An entry the account may not read (archived) answers 404, like a missing one: no existence oracle.
        abort_if($this->isMethod('PUT') && ($this->entry() === null || ! Gate::allows('read', $this->entry())), 404);

        return true;
    }

    public function entry(): ?KbEntry
    {
        return $this->entry ??= KbEntry::find((int) $this->route('entry'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $update = $this->isMethod('PUT');
        $entryId = $update ? (int) $this->route('entry') : 0;
        $rules = [
            'title' => [...($update ? ['sometimes'] : []), 'required', 'string', 'max:255'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'competency_level' => ['sometimes', 'nullable', 'in:'.implode(',', KbEntry::COMPETENCY_LEVELS)],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:kb_categories,id'],
            'active_ingredient' => ['sometimes', 'nullable', 'string', 'max:255'],
            'drug_class' => ['sometimes', 'nullable', 'string', 'max:255'],
            'content' => ['sometimes', 'nullable', 'string', $this->doc('content')],
            'sections' => ['sometimes', 'nullable', 'array'],
            'tags' => ['sometimes', 'array', 'max:100'],
            'tags.*' => ['integer', 'exists:kb_tags,id'],
            'related' => ['sometimes', 'array', 'max:100'],
            'related.*' => ['integer', Rule::exists('kb_entries', 'id')->when(! $this->user()?->can('archive', KbEntry::class), fn ($r) => $r->where('is_archived', false)), 'not_in:'.$entryId],
        ];
        if (! $update) {
            $rules['type'] = ['required', 'in:'.implode(',', KbEntry::TYPES)];
        }
        foreach ($this->sectionKeys() as $key) {
            $rules["sections.$key"] = ['nullable', 'string', $this->doc("sections.$key")];
        }

        return $rules;
    }

    /** @return list<string> */
    private function sectionKeys(): array
    {
        $type = $this->isMethod('PUT') ? $this->entry()?->type : $this->input('type');

        return is_string($type) ? (KbEntry::SECTIONS_BY_TYPE[$type] ?? []) : [];
    }

    private function doc(string $field): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($field) {
            if (! is_string($value)) {
                return;
            }
            try {
                $this->clean[$field] = KbContentValidator::validate($value);
            } catch (InvalidArgumentException $e) {
                $fail($e->getMessage());
            }
        };
    }

    /** @return array<string, mixed> columns to write (only what was sent); `sections` holds only the sent keys */
    public function values(): array
    {
        $out = $this->safe()->only(['title', 'subtitle', 'competency_level', 'category_id', 'active_ingredient', 'drug_class']);
        if ($this->has('content')) {
            $out['content'] = $this->clean['content'] ?? null;
        }
        $sections = [];
        foreach ($this->sectionKeys() as $key) {
            if (is_array($this->input('sections')) && array_key_exists($key, $this->input('sections'))) {
                $sections[$key] = $this->clean["sections.$key"] ?? null;
            }
        }
        if ($sections !== []) {
            $out['sections'] = $sections;
        }

        return $out;
    }

    /** @return list<int>|null null = not sent, keep */
    public function ids(string $field): ?array
    {
        return $this->has($field) ? array_map('intval', array_values((array) $this->validated($field))) : null;
    }
}
