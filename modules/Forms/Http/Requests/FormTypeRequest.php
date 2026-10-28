<?php

namespace Modules\Forms\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Forms\Models\FormType;

/** Application type form (system.admin). An absence type needs the date fields von_datum and bis_datum, so it cannot be created as one. */
class FormTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('system.admin');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'icon' => ['nullable', 'string', 'regex:/^[A-Za-z0-9-]{1,50}$/'],
            'is_active' => ['required', 'boolean'],
            'is_absence' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        $values = $this->validated();
        $values['icon'] = $values['icon'] ?? null;
        $values['description'] = $values['description'] ?? null;
        if (($values['sort_order'] ?? null) === null) {
            unset($values['sort_order']);
        }

        return $values;
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'description', 'icon'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => trim($this->input($key))]);
            }
        }
        foreach (['description', 'icon'] as $key) {
            if ($this->input($key) === '') {
                $this->merge([$key => null]);
            }
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->has('is_absence') || ! $this->boolean('is_absence')) {
                return;
            }
            $type = $this->route('type');
            if (! $type instanceof FormType || ! $type->hasAbsenceFields()) {
                $v->errors()->add('is_absence', __('forms.error.absence_fields'));
            }
        }];
    }
}
