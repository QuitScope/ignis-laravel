<?php

namespace App\Console\Commands;

use App\Http\Requests\InstallRequest;
use App\Install\AlreadyInstalled;
use App\Install\InstallData;
use App\Install\Installer;
use App\Install\InstallState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class InstallCommand extends Command
{
    protected $signature = 'ignis:install
        {--org= : Organisation name}
        {--discord-client-id= : Discord application client ID}
        {--discord-client-secret= : Discord application client secret}
        {--admin-discord-id= : Discord user ID of the first administrator}
        {--modules=* : Modules to enable}
        {--locale=de : Default language}
        {--install-token= : Required when IGNIS_INSTALL_TOKEN is set}';

    protected $description = 'Install Ignis without the web installer';

    private const OPTIONS = [
        'orgName' => 'org',
        'discordClientId' => 'discord-client-id',
        'discordClientSecret' => 'discord-client-secret',
        'adminDiscordId' => 'admin-discord-id',
        'modules' => 'modules',
        'locale' => 'locale',
        'installToken' => 'install-token',
    ];

    public function handle(InstallState $state, Installer $installer): int
    {
        if ($state->isInstalled()) {
            $this->error(__('install.already_installed'));

            return self::FAILURE;
        }

        $validator = Validator::make(
            array_map(fn (string $option) => $this->option($option), self::OPTIONS),
            InstallRequest::rulesFor(),
            InstallRequest::messagesFor(),
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $field => $messages) {
                $this->error('--'.self::OPTIONS[Str::before($field, '.')].': '.$messages[0]);
            }

            return self::FAILURE;
        }

        try {
            $installer->run(InstallData::fromValidated($validator->validated()));
        } catch (AlreadyInstalled $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(__('install.done'));

        return self::SUCCESS;
    }
}
