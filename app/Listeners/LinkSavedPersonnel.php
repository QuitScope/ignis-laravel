<?php

namespace App\Listeners;

use App\Events\PersonnelSaved;
use App\Models\Personnel;
use App\Personnel\AccountLink;

/** After a record is saved: link it over its Discord ID when exactly one free account matches. */
class LinkSavedPersonnel
{
    public function handle(PersonnelSaved $event): void
    {
        $discordId = Personnel::query()->whereKey($event->personnelId)->value('discord_id');
        if (is_string($discordId) && $discordId !== '') {
            AccountLink::autoLinkByDiscord($discordId);
        }
    }
}
