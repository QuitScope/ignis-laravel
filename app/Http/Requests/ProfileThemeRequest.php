<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The account's own appearance. `string` first: an array or number never reaches `in`. */
class ProfileThemeRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['theme' => ['required', 'string', Rule::in(User::THEMES)]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['theme.*' => trans('profile.error.theme')];
    }
}
