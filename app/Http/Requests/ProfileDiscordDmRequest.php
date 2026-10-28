<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Discord DMs on or off for the own account (accepts true/false/1/0/"1"/"0"). */
class ProfileDiscordDmRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['discord_dm' => ['required', 'boolean']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['discord_dm.*' => trans('profile.error.discord_dm')];
    }
}
