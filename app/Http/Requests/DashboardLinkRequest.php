<?php

namespace App\Http\Requests;

use App\Dashboard\LinkTarget;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Quick link (legacy SaveDashboardTileRequest): category required, title 1-255, target by LinkTarget, icon up to 100
 * characters of [a-z0-9 -] (empty = none), sort order 0-9999. The ability is checked here so 403 comes before 422.
 */
class DashboardLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('system.manageDashboard');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(CreateDefectRequest::cleanTexts($this, ['title', 'url', 'icon']));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'dashboard_category_id' => ['required', 'integer', 'exists:dashboard_categories,id'],
            'title' => ['required', 'string', 'max:255', CreateDefectRequest::utf8()],
            'url' => ['required', 'string', 'max:'.LinkTarget::MAX, function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || ! LinkTarget::validate($value)) {
                    $fail(trans('dashboard.error.url'));
                }
            }],
            'icon' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9 -]*$/'],
            'sort_order' => ['nullable', 'integer', 'between:0,9999'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return self::messagesFor([
            'dashboard_category_id' => 'dashboard.error.category', 'title' => 'dashboard.error.title', 'url' => 'dashboard.error.url',
            'icon' => 'dashboard.error.icon', 'sort_order' => 'dashboard.error.sort_order',
        ]);
    }

    /**
     * One translated message per field for every rule used here.
     *
     * @param  array<string, string>  $fields  field => translation key
     * @return array<string, string>
     */
    public static function messagesFor(array $fields): array
    {
        $messages = [];
        foreach ($fields as $field => $key) {
            foreach (['required', 'string', 'max', 'integer', 'between', 'exists', 'regex', 'array', 'min', 'prohibited'] as $rule) {
                $messages["$field.$rule"] = trans($key);
            }
        }

        return $messages;
    }

    /** @return array{dashboard_category_id: int, title: string, url: string, icon: ?string, sort_order?: int} */
    public function linkData(): array
    {
        $data = $this->validated();
        $out = [
            'dashboard_category_id' => (int) $data['dashboard_category_id'],
            'title' => $data['title'],
            'url' => $data['url'],
            'icon' => ($data['icon'] ?? null) ?: null,
        ];
        if (($data['sort_order'] ?? null) !== null) {
            $out['sort_order'] = (int) $data['sort_order'];
        }

        return $out;
    }
}
