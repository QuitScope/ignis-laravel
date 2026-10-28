<?php

namespace App\Http\Requests;

use App\Models\Personnel;
use App\Support\ServiceNumber;
use App\Support\Settings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create form. The ability is checked here so 403 comes before 422. */
class StorePersonnelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Personnel::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'title_id' => ['nullable', 'integer', Rule::exists('personnel_titles', 'id')],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before:today'],
            'gender' => ['required', 'integer', Rule::in([0, 1, 2])],
            'discord_id' => ['nullable', 'regex:/^\d{17,20}$/D'],
            'phone' => ['nullable', 'string', 'max:255'],
            'service_number' => ['required', 'string', 'max:255', 'regex:'.ServiceNumber::PATTERN, Rule::unique('personnel', 'service_number')],
            'hired_at' => ['required', 'date_format:Y-m-d'],
            'rank_id' => ['required', 'integer', Rule::exists('ranks', 'id')->where('is_archive', false)],
            'character_id' => [app(Settings::class)->get('personnel.require_character_id', false) ? 'required' : 'nullable', 'string', 'max:255'],
        ];
    }
}
