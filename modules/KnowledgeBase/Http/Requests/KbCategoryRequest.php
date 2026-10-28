<?php

namespace Modules\KnowledgeBase\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Modules\KnowledgeBase\Models\KbCategory;
use Modules\KnowledgeBase\Models\KbEntry;

/** Category form. On update only the sent fields change; a parent that would make the category its own descendant is refused. */
class KbCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('edit', KbEntry::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'icon' => ['nullable', 'string', 'max:50', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/i'],
            'parent_id' => ['nullable', 'integer', 'exists:kb_categories,id'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * The validated fields; an explicit null sort_order counts as not sent (the column is NOT NULL).
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $values = $this->validated();
        if (($values['sort_order'] ?? null) === null) {
            unset($values['sort_order']);
        }

        return $values;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $v) {
            $category = $this->route('category');
            $parent = $this->input('parent_id');
            if ($category instanceof KbCategory && is_numeric($parent) && ! $v->errors()->has('parent_id') && KbCategory::wouldCycle($category->id, (int) $parent)) {
                $v->errors()->add('parent_id', __('knowledgebase.error.cycle'));
            }
        }];
    }
}
