<?php

namespace App\Http\Requests;

use App\Models\Personnel;
use App\Support\ServiceNumber;
use Illuminate\Validation\Rule;

/**
 * Profile form: the create rules, but the own record may keep its service number and an archive rank
 * is allowed (that is how someone is dismissed). The picture only changes through its own upload.
 */
class UpdatePersonnelRequest extends StorePersonnelRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('personnel'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Personnel $personnel */
        $personnel = $this->route('personnel');

        return [
            ...parent::rules(),
            'service_number' => ['required', 'string', 'max:255', 'regex:'.ServiceNumber::PATTERN, Rule::unique('personnel', 'service_number')->ignore($personnel->id)],
            'rank_id' => ['required', 'integer', Rule::exists('ranks', 'id')],
            'fire_qualification_id' => ['required', 'integer', Rule::exists('fire_qualifications', 'id')],
            'ems_qualification_id' => ['required', 'integer', Rule::exists('ems_qualifications', 'id')],
            'extra_qualification' => ['nullable', 'string', 'max:255'],
        ];
    }
}
