<?php

namespace App\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'cron:tick',
    description: 'Fuehrt faellige Cron-Jobs aus',
)]
final class CronTickCommand
{
    public function run(): void
    {
        $this->ensure('mci.create', redirectTo: 'index');
        if (!Permissions::check(['logbook.view', 'logbook.edit'])) {
            return;
        }
    }
}
