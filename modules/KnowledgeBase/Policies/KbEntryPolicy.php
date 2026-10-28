<?php

namespace Modules\KnowledgeBase\Policies;

use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Support\Settings;
use Modules\KnowledgeBase\Models\KbEntry;

/**
 * There is no kb.view, every account reads. Guests read only with the public-access switch, and never archived
 * entries. Policies stay active with the module off, so each ability checks it. The entry argument is optional so
 * `can('edit', KbEntry::class)` works for the taxonomy pages.
 */
class KbEntryPolicy
{
    public function read(?User $user, ?KbEntry $entry = null): bool
    {
        if (! $this->on()) {
            return false;
        }
        if ($entry?->is_archived) {
            return $user?->hasPermission(['admin', 'kb.archive']) ?? false;
        }

        return $user !== null || app(Settings::class)->get('knowledgebase.public_access') === true;
    }

    public function edit(User $user, ?KbEntry $entry = null): bool
    {
        return $this->on() && $user->hasPermission(['admin', 'kb.edit']);
    }

    public function archive(User $user, ?KbEntry $entry = null): bool
    {
        return $this->on() && $user->hasPermission(['admin', 'kb.archive']);
    }

    public function hideEditor(User $user, ?KbEntry $entry = null): bool
    {
        return $this->on() && $user->hasPermission('admin');
    }

    private function on(): bool
    {
        return ModuleRegistry::active('knowledgebase');
    }
}
