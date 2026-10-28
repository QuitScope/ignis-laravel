<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/** Policy check here so 403 comes before 422. Subclasses only list their rules. */
abstract class LookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('personnel-settings.manage');
    }

    /** @return array<string, mixed> */
    protected function priority(): array
    {
        return ['priority' => ['required', 'integer', 'between:0,9999']];
    }

    protected function ignoredId(): ?int
    {
        $id = $this->route('id');

        return $id === null ? null : (int) $id;
    }
}
