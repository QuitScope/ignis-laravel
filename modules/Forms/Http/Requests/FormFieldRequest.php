<?php

namespace Modules\Forms\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Forms\Models\FormField;
use Modules\Forms\Models\FormType;

/** New field of a type (system.admin). Fields are never edited afterwards. */
class FormFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('system.admin');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $type = $this->route('type');

        return [
            'key' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('form_fields', 'key')->where('form_type_id', $type instanceof FormType ? $type->id : 0)],
            'label' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(FormField::TYPES)],
            'options' => [$this->input('type') === 'select' ? 'required' : 'nullable', 'array', 'min:1', 'max:100'],
            'options.*' => ['required', 'string', 'max:255', 'distinct'],
            'required' => ['sometimes', 'boolean'],
            'placeholder' => ['nullable', 'string', 'max:255'],
            'hint' => ['nullable', 'string', 'max:2000'],
            'width' => ['sometimes', 'string', Rule::in(FormField::WIDTHS)],
            'default_value' => ['nullable', 'string', 'max:2000'],
            'readonly' => ['sometimes', 'boolean'],
            'auto_fill' => ['nullable', 'string', Rule::in(FormField::AUTO_FILL)],
        ];
    }

    /** @return array<string, mixed> column values */
    public function values(): array
    {
        $v = $this->validated();

        return [
            'key' => $v['key'],
            'label' => $v['label'],
            'type' => $v['type'],
            'options' => $v['type'] === 'select' ? array_values($v['options']) : null,
            'required' => (bool) ($v['required'] ?? false),
            'placeholder' => $v['placeholder'] ?? null,
            'hint' => $v['hint'] ?? null,
            'width' => $v['width'] ?? 'full',
            'default_value' => $v['default_value'] ?? null,
            'readonly' => (bool) ($v['readonly'] ?? false),
            'auto_fill' => $v['auto_fill'] ?? null,
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['key', 'label'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => trim($this->input($key))]);
            }
        }
        if (is_array($this->input('options'))) {
            $this->merge(['options' => array_map(fn ($o) => is_string($o) ? trim($o) : $o, $this->input('options'))]);
        }
    }
}
