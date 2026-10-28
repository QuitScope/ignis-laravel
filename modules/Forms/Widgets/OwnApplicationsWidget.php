<?php

namespace Modules\Forms\Widgets;

use App\Dashboard\Widget;
use App\Models\User;
use App\Modules\BelongsToModule;
use Modules\Forms\Models\FormSubmission;

/** "Meine Anträge" (legacy OwnRecords::applications): the newest own submissions, continued by /me/applications. */
final class OwnApplicationsWidget implements BelongsToModule, Widget
{
    public const ROWS = 5;

    public function module(): string
    {
        return 'forms';
    }

    public function key(): string
    {
        return 'own_applications';
    }

    public function area(): string
    {
        return 'main';
    }

    public function order(): int
    {
        return 35;
    }

    public function allowed(User $user): bool
    {
        return true;
    }

    public function data(User $user): array
    {
        if ($user->personnel_id === null) {
            return ['items' => [], 'total' => 0, 'reason' => 'no_profile', 'href' => '/me/applications'];
        }
        $own = FormSubmission::listing(['status' => '', 'q' => ''])->where('personnel_id', $user->personnel_id);

        return [
            'total' => (clone $own)->count(),
            'items' => $own->limit(self::ROWS)->get()->map(fn (FormSubmission $s) => $s->row())->all(),
            'href' => '/me/applications',
        ];
    }
}
