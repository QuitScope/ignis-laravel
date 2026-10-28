<?php

namespace App\Dashboard;

use App\Models\EmsQualification;
use App\Models\Personnel;
use App\Models\Rank;
use App\Models\Vehicle;

/**
 * Setup steps of the admin checklist (legacy setup-checklist.php). The installer already settles modules and system
 * data (organisation name, APP_URL), what is left is lists and people. The archive rank and the "none" qualification
 * do not count: they come with the seeders and say nothing about the operator's own setup.
 */
final class SetupChecklist
{
    /** @return list<array{label: string, key: string, done: bool, href: string, required: bool}> */
    public function steps(): array
    {
        $steps = [
            ['key' => 'ranks', 'done' => Rank::where('is_archive', false)->exists(), 'href' => '/settings/personnel/ranks', 'required' => true],
            ['key' => 'ems', 'done' => EmsQualification::where('is_none', false)->exists(), 'href' => '/settings/personnel/ems-qualifications', 'required' => true],
            ['key' => 'personnel', 'done' => Personnel::query()->exists(), 'href' => '/personnel/create', 'required' => true],
            ['key' => 'vehicle', 'done' => Vehicle::query()->exists(), 'href' => '/vehicles/create', 'required' => false],
        ];

        return array_map(fn (array $s) => ['label' => __('dashboard.setup.step.'.$s['key'])] + $s, $steps);
    }
}
