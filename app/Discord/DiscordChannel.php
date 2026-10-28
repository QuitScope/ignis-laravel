<?php

namespace App\Discord;

use App\Jobs\SendDiscordDm;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Bus;

/**
 * Notification channel `discord`: queues one SendDiscordDm per recipient and notification, nothing else. It runs after
 * the database channel, so the inbox row is there first; a failure here is reported and never reaches the caller.
 */
class DiscordChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if ($notifiable instanceof User && is_string($notification->id)) {
            rescue(fn () => Bus::dispatch(new SendDiscordDm($notifiable->id, $notification->id)));
        }
    }
}
