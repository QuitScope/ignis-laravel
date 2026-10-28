<?php

namespace App\Discord;

use Closure;

/** The validation rule for a webhook URL (registry and settings form); the message never repeats the input. */
final class WebhookUrlRule
{
    public static function make(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || WebhookUrl::parse($value) === null) {
                $fail(__('settings.discord.webhooks.invalid'));
            }
        };
    }
}
