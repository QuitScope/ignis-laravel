<?php

namespace App\Notifications;

use App\Models\User;
use App\Modules\ModuleRegistry;
use Closure;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Registry of notification kinds (legacy NotificationManager types): key => label translation key, Lucide icon and
 * an optional ability. Core kinds are registered in AppServiceProvider; modules register theirs (`protokoll`,
 * `antrag`, `calendar`, `fire_protocol`, `mail`) themselves. Rows of an unknown kind are shown raw; rows of a
 * kind whose ability says no, or whose module is switched off (`module`), are left out of the page, the popover and
 * every counter (legacy 284-323); they come back when the module is switched on again.
 */
final class NotificationKinds
{
    /** @var array<string, array{label: string, icon: string, ability: ?Closure(User): bool, module: ?string, dm_message: bool}> */
    private array $kinds = [];

    /**
     * `dm_message: false` keeps the text out of the Discord DM (mail: no subject in Discord); the inbox keeps it.
     *
     * @param  array{label: string, icon: string, ability?: ?Closure(User): bool, module?: ?string, dm_message?: bool}  $def
     */
    public function register(string $key, array $def): void
    {
        $this->kinds[$key] = ['label' => $def['label'], 'icon' => $def['icon'], 'ability' => $def['ability'] ?? null, 'module' => $def['module'] ?? null,
            'dm_message' => $def['dm_message'] ?? true];
    }

    public function dmMessage(string $key): bool
    {
        return $this->kinds[$key]['dm_message'] ?? true;
    }

    public function has(string $key): bool
    {
        return isset($this->kinds[$key]);
    }

    /** @return array<string, string> key => translated label of every registered kind whose module is on */
    public function labels(): array
    {
        return array_map(fn (array $k) => trans($k['label']), array_filter($this->kinds, fn (array $k) => ModuleRegistry::active($k['module'])));
    }

    /** @return array{label: string, icon: string, known: bool} */
    public function describe(string $key): array
    {
        $kind = $this->kinds[$key] ?? null;

        return $kind === null
            ? ['label' => $key, 'icon' => 'bell', 'known' => false]
            : ['label' => trans($kind['label']), 'icon' => $kind['icon'], 'known' => true];
    }

    /**
     * Registered kinds $user may see (the type filter): module switched on and ability passed. Unknown kinds are not
     * listed but still shown.
     *
     * @return list<string>
     */
    public function allowed(User $user): array
    {
        return array_keys(array_filter($this->kinds, fn (array $k) => ModuleRegistry::active($k['module']) && ($k['ability'] === null || ($k['ability'])($user))));
    }

    /** @return list<string> registered kinds $user may not see */
    public function hidden(User $user): array
    {
        return array_values(array_diff(array_keys($this->kinds), $this->allowed($user)));
    }

    /**
     * $user's own notifications without the hidden kinds, newest first. Every read goes through here, so a foreign
     * id is simply not found (404).
     *
     * @return MorphMany<DatabaseNotification, User>
     */
    public function visibleTo(User $user): MorphMany
    {
        $query = $user->notifications()->reorder()->orderByDesc('created_at')->orderByDesc('id');
        $hidden = $this->hidden($user);

        return $hidden === [] ? $query : $query->whereNotIn('type', $hidden);
    }

    /**
     * One row as the clients see it (legacy decorate()). `href` opens it over /inbox/{id}/open, only when the stored
     * link is internal.
     *
     * @return array{id: string, type: string, title: string, message: ?string, link: ?string, created_at: ?string, known: bool, label: string, icon: string, href: ?string, is_read: bool}
     */
    public function present(DatabaseNotification $n): array
    {
        $data = $n->data;
        $link = InboxNotification::safeLink(is_string($data['link'] ?? null) ? $data['link'] : null);

        return [
            'id' => $n->id,
            'type' => $n->type,
            'title' => is_string($data['title'] ?? null) ? $data['title'] : '',
            'message' => is_string($data['message'] ?? null) ? $data['message'] : null,
            'link' => $link,
            'created_at' => $n->created_at?->toIso8601ZuluString(),
            ...$this->describe($n->type),
            'href' => $link === null ? null : "/inbox/{$n->id}/open",
            'is_read' => $n->read_at !== null,
        ];
    }
}
