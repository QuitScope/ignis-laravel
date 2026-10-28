<?php

namespace App\Install;

use App\Models\DocumentTemplate;
use App\Models\Rank;
use App\Models\Role;
use App\Modules\ModuleRegistry;
use App\Support\Settings;
use Database\Seeders\DocumentTemplatesSeeder;
use Database\Seeders\PersonnelDefaultsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class Installer
{
    public static function requiredToken(): ?string
    {
        $token = trim((string) config('ignis.install_token'));

        return $token === '' ? null : $token;
    }

    public function __construct(
        private readonly Settings $settings,
        private readonly InstallState $state,
        private readonly ModuleRegistry $modules,
    ) {}

    public function run(InstallData $d): void
    {
        try {
            DB::transaction(function () use ($d) {
                if ($this->state->isInstalled()) {
                    throw new AlreadyInstalled;
                }

                $this->settings->set('system.name', $d->orgName);
                $this->settings->set('discord.client_id', $d->discordClientId);
                $this->settings->set('discord.client_secret', $d->discordClientSecret, encrypted: true);
                $this->settings->set('install.admin_discord_id', $d->adminDiscordId);
                foreach ($this->modules->switchable() as $name) {
                    $this->settings->set('modules.'.strtolower($name).'.enabled', in_array($name, $d->modules, true));
                }
                $this->settings->set('app.locale', $d->locale);

                if (Role::count() === 0) {
                    (new RoleSeeder)->run();
                }

                if (Rank::count() === 0) {
                    (new PersonnelDefaultsSeeder)->run();
                }

                if (DocumentTemplate::count() === 0) {
                    (new DocumentTemplatesSeeder)->run();
                }

                $this->state->markInstalled();
            }, attempts: 3);
        } catch (QueryException $e) {
            $this->settings->refresh();
            throw $this->state->isInstalled() ? new AlreadyInstalled : $e;
        } finally {
            $this->settings->refresh();
        }
    }
}
