<?php

use Symfony\Component\Yaml\Yaml;

/** @return array<string, mixed> */
function dcCompose(): array
{
    return Yaml::parseFile(dirname(__DIR__, 2).'/docker-compose.yml');
}

/** @return list<string> the volume entries of a service, short syntax */
function dcVolumes(string $service): array
{
    return array_values(array_map('strval', dcCompose()['services'][$service]['volumes'] ?? []));
}

it('mounts the docker socket only in the updater service', function () {
    $withSocket = [];
    foreach (dcCompose()['services'] as $name => $service) {
        foreach ($service['volumes'] ?? [] as $volume) {
            if (str_contains((string) $volume, 'docker.sock')) {
                $withSocket[] = $name;
            }
        }
    }
    expect($withSocket)->toBe(['updater']);
});

it('publishes no port of the updater, keeps it unprivileged and on the internal update network', function () {
    $c = dcCompose();
    $updater = $c['services']['updater'];
    expect($updater)->not->toHaveKey('ports')->not->toHaveKey('network_mode')->not->toHaveKey('privileged')
        ->and($updater['networks'])->toContain('update')
        ->and($updater['init'] ?? false)->toBeTrue()
        ->and($c['networks']['update']['internal'] ?? false)->toBeTrue();
});

it('requires UPDATER_SECRET in app and updater, the app has no socket and talks to the updater by name', function () {
    $s = dcCompose()['services'];
    foreach (['app', 'updater'] as $service) {
        expect((string) $s[$service]['environment']['UPDATER_SECRET'])->toStartWith('${UPDATER_SECRET:?');
    }
    expect($s['app']['environment']['IGNIS_UPDATE_DRIVER'])->toBe('docker')
        ->and($s['app']['environment']['UPDATER_URL'])->toBe('http://updater:8099')
        ->and($s['app']['networks'])->toContain('update')
        ->and($s['worker']['environment'])->not->toHaveKey('UPDATER_SECRET')
        ->and($s['scheduler']['environment'])->not->toHaveKey('UPDATER_SECRET');
});

it('lets the app read state and backups only, and keeps the override files in an updater-only volume', function () {
    expect(dcVolumes('app'))->toContain('update-state:/var/lib/ignis-update/state:ro')->toContain('update-backups:/var/lib/ignis-update/backups:ro');
    foreach (dcCompose()['services'] as $name => $service) {
        foreach ($service['volumes'] ?? [] as $volume) {
            if (str_starts_with((string) $volume, 'updater-private:')) {
                expect($name)->toBe('updater');
            }
            if ($name !== 'updater' && preg_match('/^update-(state|backups):/', (string) $volume)) {
                expect((string) $volume)->toEndWith(':ro');
            }
        }
    }
    $s = dcCompose()['services'];
    expect(dcVolumes('updater'))->toContain('updater-private:/var/lib/ignis-updater')->toContain('.:/project:ro')
        ->and($s['updater']['environment']['IGNIS_UPDATER_PRIVATE_DIR'])->toBe('/var/lib/ignis-updater');
    foreach (['app', 'updater'] as $service) {
        expect($s[$service]['environment']['IGNIS_UPDATE_STATE_PATH'])->toBe('/var/lib/ignis-update/state/state.json')
            ->and($s[$service]['environment']['IGNIS_UPDATE_BACKUP_PATH'])->toBe('/var/lib/ignis-update/backups');
    }
});

it('asks for UPDATER_SECRET with its minimum length in docker/.env.example', function () {
    expect((string) file_get_contents(dirname(__DIR__, 2).'/docker/.env.example'))->toMatch('/^UPDATER_SECRET=$/m')
        ->toContain('openssl rand -hex 32')->toContain('at least 32 characters');
});
