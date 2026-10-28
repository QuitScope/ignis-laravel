<?php

namespace App\Http\Requests;

use App\Notifications\NotificationKinds;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The Discord bot form: admin only (403 before 422). `token` blank keeps the stored one; its rule never repeats the
 * input (the field is also kept out of the flashed old input, bootstrap/app.php). DM kinds only from NotificationKinds.
 * Name and avatar are not accepted (set in the Developer Portal).
 */
class DiscordSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('system.admin');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'token' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9._-]+$/D'],
            'enabled' => ['required', 'boolean'],
            'dm_kinds' => ['present', 'array', 'max:50'],
            'dm_kinds.*' => ['string', 'distinct', Rule::in(array_keys(app(NotificationKinds::class)->labels()))],
        ];
    }
}
