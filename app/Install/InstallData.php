<?php

namespace App\Install;

final readonly class InstallData
{
    /** @param list<string> $modules names of the modules to enable */
    public function __construct(
        public string $orgName,
        public string $discordClientId,
        public string $discordClientSecret,
        public string $adminDiscordId,
        public array $modules,
        public string $locale = 'de',
    ) {}

    /** @param array<string, mixed> $v output of InstallRequest::rulesFor() validation */
    public static function fromValidated(array $v): self
    {
        return new self(
            $v['orgName'],
            $v['discordClientId'],
            $v['discordClientSecret'],
            $v['adminDiscordId'],
            array_values($v['modules'] ?? []),
            $v['locale'],
        );
    }
}
