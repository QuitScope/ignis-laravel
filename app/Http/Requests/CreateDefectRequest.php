<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Defect report (legacy CreateDefectRequest): title 1-200, description up to 5000, `is_operable` the "vehicle
 * still operable" checkbox (default yes). The category is not validated: anything off the whitelist is stored as
 * `other` (DefectReporter), as before. The ability is checked here so 403 comes before 422.
 */
class CreateDefectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('vehicle.createDefect');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(self::cleanTexts($this, ['title', 'description']));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200', self::utf8()],
            'description' => ['nullable', 'string', 'max:5000', self::utf8()],
            'is_operable' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The input DefectReporter::report() takes, explicitly.
     *
     * @return array{title: string, description: ?string, category: ?string, is_operable: bool}
     */
    public function defectData(): array
    {
        $data = $this->validated();

        return [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'category' => is_string($this->input('category')) ? $this->input('category') : null,
            'is_operable' => $this->has('is_operable') ? $this->boolean('is_operable') : true,
        ];
    }

    /**
     * Trimmed strings without NUL bytes; a text that ends up empty counts as absent (null). Non-strings stay as they
     * are for the rules to refuse.
     *
     * @param  list<string>  $keys
     * @return array<string, ?string>
     */
    public static function cleanTexts(FormRequest $request, array $keys): array
    {
        $clean = [];
        foreach ($keys as $key) {
            if (is_string($request->input($key))) {
                $value = trim(str_replace("\0", '', $request->input($key)));
                $clean[$key] = $value === '' ? null : $value;
            }
        }

        return $clean;
    }

    public static function utf8(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                $fail(trans('vehicles.defects.error.encoding'));
            }
        };
    }
}
