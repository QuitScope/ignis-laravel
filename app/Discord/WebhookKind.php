<?php

namespace App\Discord;

/** The three webhook channels (legacy DiscordWebhook::KEYS); each has one stored, encrypted URL. */
enum WebhookKind: string
{
    case EnotfProtocol = 'enotf_protocol';
    case EnotfPrereg = 'enotf_prereg';
    case FireProtocol = 'fire_protocol';

    public function settingKey(): string
    {
        return 'discord.webhooks.'.$this->value;
    }

    public function label(): string
    {
        return __('settings.discord.webhooks.kind.'.$this->value);
    }
}
