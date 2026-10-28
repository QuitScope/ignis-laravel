<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Quick-link category (legacy SaveDashboardCategoryRequest): title 1-255, sort order 0-9999. The ability is checked
 * here so 403 comes before 422.
 */
class DashboardCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('system.manageDashboard');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(CreateDefectRequest::cleanTexts($this, ['title']));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255', CreateDefectRequest::utf8()],
            'sort_order' => ['nullable', 'integer', 'between:0,9999'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return DashboardLinkRequest::messagesFor(['title' => 'dashboard.error.title', 'sort_order' => 'dashboard.error.sort_order']);
    }

    /** @return array{title: string, sort_order?: int} */
    public function categoryData(): array
    {
        $data = $this->validated();
        $out = ['title' => $data['title']];
        if (($data['sort_order'] ?? null) !== null) {
            $out['sort_order'] = (int) $data['sort_order'];
        }

        return $out;
    }
}
