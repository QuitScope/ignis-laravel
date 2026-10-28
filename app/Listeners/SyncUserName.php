<?php

namespace App\Listeners;

use App\Events\PersonnelSaved;
use App\Models\Personnel;
use App\Models\User;

/** users.name follows personnel.full_name. One conditional UPDATE, so the frequent event costs nothing when unchanged. */
class SyncUserName
{
    public function handle(PersonnelSaved $event): void
    {
        $name = Personnel::query()->whereKey($event->personnelId)->value('full_name');
        if (is_string($name)) {
            User::query()->where('personnel_id', $event->personnelId)
                ->where(fn ($q) => $q->whereNull('name')->orWhere('name', '!=', $name))
                ->update(['name' => $name]);
        }
    }
}
