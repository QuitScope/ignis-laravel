<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** New draft from an active template; an empty title takes the template's name. */
class CreateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('document.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'template_id' => ['required', 'integer', Rule::exists('document_templates', 'id')->where('is_active', true)],
            'title' => ['nullable', 'string', 'max:200'],
        ];
    }
}
