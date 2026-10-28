<?php

namespace App\Dashboard\Widgets;

use App\Dashboard\SetupChecklist;
use App\Dashboard\Widget;
use App\Models\User;

/** Setup checklist, admin only. All required steps done: no widget. Dismissing is up to the browser (localStorage). */
final class ChecklistWidget implements Widget
{
    public function __construct(private readonly SetupChecklist $checklist = new SetupChecklist) {}

    public function key(): string
    {
        return 'checklist';
    }

    public function area(): string
    {
        return 'top';
    }

    public function order(): int
    {
        return 10;
    }

    public function allowed(User $user): bool
    {
        return $user->hasPermission('admin');
    }

    public function data(User $user): ?array
    {
        $steps = $this->checklist->steps();
        $open = array_filter($steps, fn (array $s) => $s['required'] && ! $s['done']);
        if ($open === []) {
            return null;
        }
        $next = array_key_first($open);
        foreach ($steps as $i => $step) {
            $steps[$i]['next'] = $i === $next;
        }

        return ['steps' => $steps];
    }
}
