<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Reorder result: category ids, or (links route) the target category and its link ids; at most 500. */
class ReorderDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('system.manageDashboard');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category_id' => [$this->forLinks() ? 'required' : 'prohibited', 'integer', 'exists:dashboard_categories,id'],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'min:1', 'max:999999999999'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return DashboardLinkRequest::messagesFor(['category_id' => 'dashboard.error.category', 'ids' => 'dashboard.error.ids', 'ids.*' => 'dashboard.error.ids']);
    }

    public function forLinks(): bool
    {
        return $this->is('settings/dashboard/links/*');
    }

    /** @return list<int> the ids without duplicates, in submitted order */
    public function ids(): array
    {
        return array_values(array_unique(array_map('intval', $this->validated('ids'))));
    }
}
