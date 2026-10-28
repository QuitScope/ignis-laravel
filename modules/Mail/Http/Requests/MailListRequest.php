<?php

namespace Modules\Mail\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Mail\Http\Controllers\MailListController;

/**
 * The fields of a distribution list (legacy MailListController::save): text where text belongs, kind and senders from
 * their lists, IDs as integers. Whether the address is free and which IDs exist is the controller's check (inside the
 * write), unknown IDs simply fall away.
 */
class MailListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:150'],
            'local' => ['required', 'string', 'max:64'],
            'domain' => ['required', 'string', 'max:100'],
            'kind' => ['required', 'string', Rule::in(['static', 'dynamic'])],
            'senders' => ['required', 'string', Rule::in(['all', 'managers'])],
            'members' => ['nullable', 'array', 'max:2000'],
            'members.*' => ['integer'],
            'rule' => ['nullable', 'array'],
        ];
        foreach (MailListController::RULE_KEYS as $key) {
            $rules["rule.$key"] = ['nullable', 'array', 'max:500'];
            $rules["rule.$key.*"] = ['integer'];
        }

        return $rules;
    }

    /** @return list<int> */
    public function ids(string $key): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->input($key, []))));
    }
}
