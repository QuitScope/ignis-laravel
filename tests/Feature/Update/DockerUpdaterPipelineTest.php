<?php

use App\Models\Role;
use App\Models\User;
use App\Update\Backup\BackupSet;
use App\Update\DockerUpdateService;
use App\Update\UpdateException;
use App\Update\UpdateLock;
use App\Update\UpdateLog;
use App\Update\UpdateRunner;
use App\Update\UpdateState;
use Database\Seeders\RoleSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeDocker;
use Tests\Support\UpdateFixtures;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->base = sys_get_temp_dir().'/ignis-dock-'.bin2hex(random_bytes(4));
    mkdir($this->base.'/state', 0777, true);
    register_shutdown_function(fn () => (new Filesystem)->deleteDirectory($this->base));
    config([
        'ignis.update.state_path' => $this->base.'/state/state.json',
        'ignis.update.backup_path' => $this->base.'/backups',
        'ignis.update.log_dir' => $this->base.'/state',
        'ignis.update.compose_files' => '/project/docker-compose.yml',
        'ignis.update.private_dir' => $this->base.'/private',
    ]);
    UpdateFixtures::setUp('1.0.0');
    UpdateFixtures::fake(['image' => 'ghcr.io/ignis-test/ignis', 'image_digest' => 'sha256:'.str_repeat('b', 64)]);
    $this->state = new UpdateState;
    $this->updater = new DockerUpdateService($this->state, new UpdateLock, new UpdateLog($this->base.'/state/update-test.log'));
    $this->updater->healthAttempts = 2;
    $this->updater->pauseMs = 0;
    $this->docker = new FakeDocker($this->updater);
    $this->updater->run = $this->docker->__invoke(...);
});

/** @return list<string> the argv lines that ran */
function dkLines(FakeDocker $d): array
{
    return array_map(fn (array $a) => implode(' ', $a), $d->calls);
}

function dkOverride(DockerUpdateService $u): string
{
    return (string) @file_get_contents($u->overridePath());
}

it('success path: pulls the signed image by digest, writes the IMAGE_TAG override, ends done', function () {
    $this->updater->run('2.0.0');

    $s = $this->state->read();
    expect($s['status'])->toBe('done')->and($s['from'])->toBe('1.0.0')->and($s['to'])->toBe('2.0.0')
        ->and(array_column($s['steps'], 'name'))->toBe(['checking', 'downloading', 'verifying', 'backing_up', 'trial_migration', 'switching', 'migrating', 'health_check'])
        ->and($s['maintenance_left_on'])->toBeFalse();
    $override = dkOverride($this->updater);
    foreach (DockerUpdateService::SERVICES as $service) {
        expect($override)->toContain("  $service:\n    image: \"".FakeDocker::NEW.'"');
    }
    $lines = dkLines($this->docker);
    expect($lines)->toContain('docker pull '.FakeDocker::NEW)
        ->toContain('docker tag '.FakeDocker::OLD_ID.' ignis-update-previous:1.0.0')
        ->and($this->docker->running)->toBe(FakeDocker::NEW)
        ->and($this->docker->down)->toBeFalse();
    expect(collect($lines)->first(fn ($l) => str_contains($l, ' up -d ')))
        ->toBe('docker compose --project-directory /project -f /project/docker-compose.yml -f '.$this->updater->overridePath().' up -d --no-deps --no-build --pull never app worker scheduler');
    expect(collect($lines)->filter(fn ($l) => str_contains($l, 'run --rm'))->first())->toContain('-e DB_DATABASE=ignis_trial')
        ->and(collect($lines)->contains(fn ($l) => str_contains($l, 'DROP DATABASE IF EXISTS ignis_trial"')))->toBeTrue();
    $set = BackupSet::fromDir((string) $s['backup']);
    expect($set)->not->toBeNull()->and($set->fromVersion)->toBe('1.0.0')->and($set->toVersion)->toBe('2.0.0')
        ->and(BackupSet::readManifest($set->path)['image'])->toBe('ignis-update-previous:1.0.0')
        ->and(BackupSet::readManifest($set->path)['driver'])->toBe('docker');
    expect(implode("\n", $lines))->not->toContain('--password')->not->toContain('-p"');
});

it('uses only the image of the signed manifest and verifies the release itself', function (array $manifest, ?string $signature, string $installed, string $reason) {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    UpdateFixtures::fake($manifest + ['image' => 'ghcr.io/ignis-test/ignis', 'image_digest' => 'sha256:'.str_repeat('b', 64)], signature: $signature);
    $this->docker->installed = $installed;
    $this->updater->run('2.0.0');

    $s = $this->state->read();
    expect($s['status'])->toBe('failed')->and($s['reason'])->toBe($reason)->and($s['rolled_back'])->toBeFalse();
    $lines = implode("\n", dkLines($this->docker));
    expect($lines)->not->toContain('docker pull')->not->toContain(' up -d')->not->toContain('artisan down')
        ->and(is_file($this->updater->overridePath()))->toBeFalse();
})->with([
    'unsigned' => [[], 'AAAA', '1.0.0', 'signature'],
    'signed by someone else' => [[], base64_encode(str_repeat("\0", 64)), '1.0.0', 'signature'],
    'downgrade / same version' => [[], null, '2.0.0', 'not_newer'],
    'no image in the manifest' => [['image' => null, 'image_digest' => null], null, '1.0.0', 'image_missing'],
]);

it('failure at pull changes nothing: no maintenance, no backup, no override', function () {
    $this->docker->fail['pull'] = true;
    $this->updater->run('2.0.0');

    $s = $this->state->read();
    expect($s['status'])->toBe('failed')->and($s['step'])->toBe('downloading')->and($s['reason'])->toBe('docker_failed')
        ->and($this->docker->down)->toBeFalse()->and($this->docker->ups)->toBe(0)
        ->and(glob($this->base.'/backups/*') ?: [])->toBe([]);
});

it('failure at up or health restores the old image and the database and checks the old version', function (string $fail, string $step) {
    $this->docker->fail[$fail] = true;
    $this->updater->run('2.0.0');

    $s = $this->state->read();
    expect($s['status'])->toBe('rolled_back')->and($s['step'])->toBe($step)->and($s['rolled_back'])->toBeTrue()
        ->and(dkOverride($this->updater))->toContain('image: "ignis-update-previous:1.0.0"')
        ->not->toContain(FakeDocker::NEW)
        ->and($this->docker->running)->toBe('ignis-update-previous:1.0.0')
        ->and($this->docker->down)->toBeFalse();
    expect($this->docker->dbInput)->toHaveCount(2)
        ->and($this->docker->dbInput[0])->toContain('DROP TABLE IF EXISTS `users`;')->toContain('DROP TABLE IF EXISTS `new_table`;')->toContain('DROP VIEW IF EXISTS `some_view`;')
        ->and($this->docker->dbInput[1])->toContain('-- Dump completed');
    $lines = dkLines($this->docker);
    $stop = collect($lines)->search(fn ($l) => str_ends_with($l, ' stop app worker scheduler'));
    $lastUp = collect($lines)->keys()->filter(fn ($k) => str_contains($lines[$k], ' up -d '))->last();
    expect($stop)->toBeInt()->toBeLessThan($lastUp);
    expect(collect($lines)->last())->toContain('artisan up');
})->with([
    'up' => ['up_new', 'switching'],
    'health' => ['health_new', 'health_check'],
]);

it('takes reverb along (override, up, stop) only when its container exists', function (bool $reverb) {
    $this->docker->reverb = $reverb;
    $this->docker->fail['health_new'] = true;
    $this->updater->run('2.0.0');

    $lines = dkLines($this->docker);
    $list = $reverb ? 'app worker scheduler reverb' : 'app worker scheduler';
    expect(collect($lines)->filter(fn ($l) => str_contains($l, ' up -d '))->every(fn ($l) => str_ends_with($l, " never $list")))->toBeTrue()
        ->and(collect($lines)->contains(fn ($l) => str_ends_with($l, " stop $list")))->toBeTrue()
        ->and(str_contains(dkOverride($this->updater), '  reverb:'))->toBe($reverb)
        ->and($this->state->read()['status'])->toBe('rolled_back');
})->with(['with reverb' => [true], 'without reverb' => [false]]);

it('a failed rollback ends in failed_rollback with maintenance left on and the manual steps named', function () {
    $this->docker->fail['health_new'] = true;
    $this->docker->fail['restore'] = true;
    $this->updater->run('2.0.0');

    $s = $this->state->read();
    expect($s['status'])->toBe('failed_rollback')->and($this->docker->down)->toBeTrue()
        ->and($s['error'])->toContain((string) $s['backup'])->toContain('docs/dev/update-recovery.md');
});

it('trial migration without CREATE rights stops before any change with trial_unavailable', function () {
    $this->docker->fail['trial_create'] = true;
    $this->updater->run('2.0.0');

    $s = $this->state->read();
    expect($s['status'])->toBe('failed')->and($s['reason'])->toBe('trial_unavailable')
        ->and($this->docker->down)->toBeFalse()->and($this->docker->ups)->toBe(0)
        ->and(glob($this->base.'/backups/*') ?: [])->toBe([]);

    $this->docker->fail = [];
    $this->updater->run('2.0.0', skipTrial: true);
    expect($this->state->read()['status'])->toBe('done')
        ->and(collect(dkLines($this->docker))->filter(fn ($l) => str_contains($l, 'run --rm'))->all())->toBe([]);
});

it('a failing trial migration changes nothing', function () {
    $this->docker->fail['trial_migrate'] = true;
    $this->updater->run('2.0.0');
    expect($this->state->read()['reason'])->toBe('trial_failed')->and($this->docker->ups)->toBe(0)->and($this->docker->down)->toBeFalse();
});

it('maintenance mode the admin had on stays on', function () {
    $this->docker->down = true;
    $this->updater->run('2.0.0');
    expect($this->state->read()['status'])->toBe('done')->and($this->docker->down)->toBeTrue();
});

it('an app that does not answer stops the update before any change', function () {
    $this->docker->fail['probe'] = true;
    $this->updater->run('2.0.0');
    expect($this->state->read()['reason'])->toBe('app_unreachable')->and($this->docker->ups)->toBe(0);
});

it('rollbackToLatest puts the previous image and database back', function () {
    $this->updater->run('2.0.0');
    expect($this->state->read()['status'])->toBe('done');

    expect($this->updater->rollbackToLatest())->toBeTrue();
    $s = $this->state->read();
    expect($s['status'])->toBe('rolled_back')->and($s['from'])->toBe('2.0.0')->and($s['to'])->toBe('1.0.0')
        ->and($this->docker->running)->toBe('ignis-update-previous:1.0.0')->and($this->docker->down)->toBeFalse();
});

it('recover rolls back a run whose updater died after switching', function () {
    $this->updater->run('2.0.0');
    $backup = (string) $this->state->read()['backup'];
    $this->state->begin('1.0.0', '2.0.0');
    foreach (['checking', 'downloading', 'verifying', 'backing_up', 'trial_migration', 'switching', 'migrating'] as $step) {
        $this->state->step($step);
    }
    $this->state->annotate(['backup' => $backup, 'maintenance_was_on' => false]);
    expect($this->state->isStale(new UpdateLock))->toBeTrue();

    expect($this->updater->recover())->toBe('rolled back')
        ->and($this->state->read()['status'])->toBe('rolled_back')
        ->and($this->docker->running)->toBe('ignis-update-previous:1.0.0');
});

it('writes a state.json the app reads unchanged through the status endpoint', function () {
    $this->updater->run('2.0.0');
    $this->updater->run = null;

    $this->seed(RoleSeeder::class);
    $role = Role::create(['name' => 'U'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['admin']);
    config(['ignis.update.driver' => 'docker', 'ignis.update.updater_url' => 'http://updater:8099', 'ignis.update.updater_secret' => str_repeat('s', 64)]);
    Http::fake(['http://updater:8099/ping' => Http::response(['ok' => true, 'running' => false])]);
    UpdateFixtures::appDir('2.0.0');

    $res = $this->actingAs(User::factory()->withRole($role)->create())->getJson('/settings/system/update/status')->assertOk();
    $s = $this->state->read();
    expect($res->json('state.status'))->toBe('done')->and($res->json('state.from'))->toBe('1.0.0')->and($res->json('state.to'))->toBe('2.0.0')
        ->and($res->json('state.steps'))->toBe($s['steps'])->and($res->json('stale'))->toBeFalse()
        ->and($res->json('preflight'))->toBeNull()
        ->and($res->json('backup_available'))->toBeTrue()
        ->and($res->json('image_ref'))->toBe(FakeDocker::NEW)
        ->and(implode("\n", $res->json('log')))->toContain('update to 2.0.0 done');
});

it('the files updater refuses to run, recover or roll back in a docker installation', function () {
    config(['ignis.update.driver' => 'docker']);
    $updater = app(UpdateRunner::class);
    foreach ([fn () => $updater->run('2.0.0'), fn () => $updater->recover(), fn () => $updater->rollbackToLatest()] as $call) {
        try {
            $call();
            $reason = null;
        } catch (UpdateException $e) {
            $reason = $e->reason;
        }
        expect($reason)->toBe('docker_use_updater');
    }
    expect($this->state->read()['status'])->toBe('idle');
});

it('keeps the override files out of the shared state volume', function () {
    $this->updater->run('2.0.0');
    expect(dirname($this->updater->overridePath()))->toBe($this->base.'/private')
        ->and(glob($this->base.'/state/*.yml') ?: [])->toBe([])
        ->and($this->docker->trialFiles)->toBe(["# Written by the ignis updater (docker compose -f docker-compose.yml -f this file).\nservices:\n  app:\n    image: \"".FakeDocker::NEW."\"\n"])
        ->and(file_exists($this->base.'/private/compose.trial.yml'))->toBeFalse();
});

it('refuses an override file it did not write exactly, before any compose call uses it', function (Closure $tamper) {
    $this->updater->run('2.0.0');
    file_put_contents($this->updater->overridePath(), $tamper($this->updater->overridePath()));
    $ups = $this->docker->ups;
    $this->docker->calls = [];

    expect(fn () => $this->updater->rollbackToLatest())->toThrow(UpdateException::class, __('update.error.docker_failed', ['command' => 'override']));
    $lines = implode("\n", dkLines($this->docker));
    expect($this->docker->calls)->toBe([])->and($this->docker->ups)->toBe($ups)->and($lines)->not->toContain(' up -d')->not->toContain(' stop ')
        ->and($lines)->not->toContain('compose.image.yml');
})->with([
    'extra volume' => [fn (string $f) => file_get_contents($f)."    volumes:\n      - /var/run/docker.sock:/var/run/docker.sock\n"],
    'other service' => [fn (string $f) => file_get_contents($f)."  evil:\n    image: \"alpine\"\n"],
    'privileged' => [fn (string $f) => str_replace("  app:\n", "  app:\n    privileged: true\n", (string) file_get_contents($f))],
    'bad image ref' => [fn (string $f) => str_replace(FakeDocker::NEW, 'x" -v /:/host "y', (string) file_get_contents($f))],
]);

it('takes the previous image back by the recorded digest when tag and id were pruned', function () {
    $this->docker->oldDigests = ['ghcr.io/ignis-test/ignis@sha256:'.str_repeat('c', 64)];
    $this->updater->run('2.0.0');
    $this->docker->images = array_values(array_diff($this->docker->images, ['ignis-update-previous:1.0.0', FakeDocker::OLD_ID]));

    expect($this->updater->rollbackToLatest())->toBeTrue()
        ->and(dkLines($this->docker))->toContain('docker pull ghcr.io/ignis-test/ignis@sha256:'.str_repeat('c', 64))
        ->and($this->docker->running)->toBe('ignis-update-previous:1.0.0');
});

it('re-tags the previous image by its id when only the tag was removed', function () {
    $this->updater->run('2.0.0');
    $this->docker->images = array_values(array_diff($this->docker->images, ['ignis-update-previous:1.0.0']));

    expect($this->updater->rollbackToLatest())->toBeTrue()
        ->and(dkLines($this->docker))->toContain('docker tag '.FakeDocker::OLD_ID.' ignis-update-previous:1.0.0');
});

it('does not stop anything when the previous image or the dump is gone', function (Closure $break) {
    $this->updater->run('2.0.0');
    $break($this);
    $this->docker->calls = [];

    expect($this->updater->rollbackToLatest())->toBeFalse();
    $s = $this->state->read();
    $lines = implode("\n", dkLines($this->docker));
    expect($s['status'])->toBe('failed')->and($s['reason'])->toBe('rollback_unavailable')
        ->and($lines)->not->toContain(' stop ')->not->toContain(' up -d')->not->toContain('artisan down')
        ->and($this->docker->running)->toBe(FakeDocker::NEW)->and($this->docker->down)->toBeFalse();
})->with([
    'image pruned, built locally' => [function ($t) {
        $t->docker->images = [FakeDocker::NEW];
    }],
    'dump deleted' => [function ($t) {
        unlink($t->state->read()['backup'].'/db.sql');
    }],
    'dump changed' => [function ($t) {
        file_put_contents($t->state->read()['backup'].'/db.sql', "-- other\n-- Dump completed\n");
    }],
]);

it('a failed health check with the previous image gone ends in failed_rollback without stopping anything', function () {
    $this->docker->fail['health_new'] = true;
    $this->docker->fail['pull_previous'] = true;
    $this->updater->fault = function (string $step) {
        if ($step === 'health_check') {
            $this->docker->images = [FakeDocker::NEW];
        }
    };
    $this->updater->run('2.0.0');
    expect($this->state->read()['status'])->toBe('failed_rollback')->and($this->state->read()['error'])->toContain(__('update.error.rollback_no_image'))
        ->and(collect(dkLines($this->docker))->contains(fn ($l) => str_ends_with($l, ' stop app worker scheduler')))->toBeFalse()
        ->and($this->docker->down)->toBeTrue();
});

it('the rollback health check expects worker and scheduler again when they ran before', function () {
    $this->docker->fail['health_new'] = true;
    $this->docker->fail['worker_after_rollback'] = true;
    $this->updater->run('2.0.0');
    expect($this->state->read()['status'])->toBe('failed_rollback')->and($this->state->read()['error'])->toContain('worker not running');
});

it('recover finishes a run killed after its health check passed instead of rolling it back', function () {
    $this->updater->run('2.0.0');
    $done = $this->state->read();
    $this->state->begin('1.0.0', '2.0.0');
    foreach (['checking', 'downloading', 'verifying', 'backing_up', 'trial_migration', 'switching', 'migrating', 'health_check'] as $step) {
        $this->state->step($step);
    }
    $this->state->annotate(['backup' => (string) $done['backup'], 'image_ref' => FakeDocker::NEW, 'maintenance_was_on' => false]);
    $this->docker->down = true;
    $this->docker->calls = [];

    expect($this->updater->recover())->toBe('the update had completed; marked as done')
        ->and($this->state->read()['status'])->toBe('done')->and($this->docker->running)->toBe(FakeDocker::NEW)
        ->and($this->docker->down)->toBeFalse()
        ->and(implode("\n", dkLines($this->docker)))->not->toContain(' stop ')
        ->toContain('DROP DATABASE IF EXISTS ignis_trial');
});

it('recover switches off maintenance a finished run left on, but never one the admin had on', function () {
    $this->updater->run('2.0.0');
    $this->state->annotate(['maintenance_left_on' => true]);
    $this->docker->down = true;
    expect($this->updater->recover())->toBe('the update had finished; maintenance mode switched off')->and($this->docker->down)->toBeFalse()
        ->and($this->state->read()['maintenance_left_on'])->toBeFalse();

    $this->state->annotate(['maintenance_left_on' => true, 'maintenance_was_on' => true]);
    $this->docker->down = true;
    expect($this->updater->recover())->toBe('nothing to recover')->and($this->docker->down)->toBeTrue();
});

it('records the image it switched to for the IMAGE_TAG notice', function () {
    $this->updater->run('2.0.0');
    expect($this->state->read()['image_ref'])->toBe(FakeDocker::NEW);
    $this->updater->rollbackToLatest();
    expect($this->state->read()['image_ref'])->toBe('ignis-update-previous:1.0.0');
});

it('gives docker and compose a minimal environment without the updater secrets', function () {
    putenv('UPDATER_SECRET=must-not-leak-'.str_repeat('x', 32));
    try {
        $env = (new ReflectionMethod(DockerUpdateService::class, 'childEnv'))->invoke(null);
    } finally {
        putenv('UPDATER_SECRET');
    }
    expect($env)->toHaveKey('PATH')->not->toHaveKey('UPDATER_SECRET')
        ->and(array_diff(array_keys($env), ['PATH', 'HOME', 'DOCKER_HOST', 'DOCKER_CONFIG', 'DOCKER_CERT_PATH', 'DOCKER_TLS_VERIFY', 'COMPOSE_PROJECT_NAME', 'SYSTEMROOT', 'TEMP', 'TMP']))->toBe([]);
});

it('a rollback attempt after failed_rollback that cannot start keeps failed_rollback and its recovery data', function (bool $maintenance) {
    $this->docker->fail['health_new'] = true;
    $this->docker->fail['restore'] = true;
    $this->updater->run('2.0.0');
    $first = $this->state->read();
    expect($first['status'])->toBe('failed_rollback');

    $this->docker->fail = ['pull_previous' => true];
    $this->docker->images = [FakeDocker::NEW];
    $this->docker->down = $maintenance;
    $this->docker->calls = [];
    expect($this->updater->rollbackToLatest())->toBeFalse();

    $s = $this->state->read();
    expect($s['status'])->toBe('failed_rollback')->and($s['reason'])->toBe('rollback_unavailable')
        ->and($s['backup'])->toBe($first['backup'])
        ->and($s['error'])->toContain((string) $first['backup'])->toContain(__('update.error.rollback_no_image'))
        ->and(str_contains((string) $s['error'], __('update.error.maintenance_left')))->toBe($maintenance)
        ->and(implode("\n", dkLines($this->docker)))->not->toContain(' stop ')->not->toContain(' up -d');
})->with(['maintenance on' => [true], 'maintenance off' => [false]]);

it('refuses an override that names another image than the state records', function () {
    $this->updater->run('2.0.0');
    $other = str_replace(FakeDocker::NEW, 'ghcr.io/evil/image:latest', (string) file_get_contents($this->updater->overridePath()));
    file_put_contents($this->updater->overridePath(), $other);
    $this->docker->calls = [];
    expect(fn () => $this->updater->installedVersion())->toThrow(UpdateException::class)
        ->and($this->docker->calls)->toBe([]);
});

it('loads dumps with the mariadb client in sandbox mode (no system or file commands from the dump)', function () {
    $this->docker->fail['health_new'] = true;
    $this->updater->run('2.0.0');
    $loads = collect(dkLines($this->docker))->filter(fn ($l) => str_contains($l, 'exec -T db sh -c') && ! str_contains($l, 'mariadb-dump') && ! str_contains($l, ' -e "'));
    expect($loads)->not->toBeEmpty();
    $loads->each(fn ($l) => expect($l)->toContain('mariadb --sandbox '));
});

it('loads dumps without --sandbox when the db client does not know it, and says so in the log', function () {
    $this->docker->fail['health_new'] = true;
    $this->docker->fail['no_sandbox'] = true;
    $this->updater->run('2.0.0');

    expect($this->state->read()['status'])->toBe('rolled_back');
    $lines = collect(dkLines($this->docker));
    expect($lines->filter(fn ($l) => str_ends_with($l, ' exec -T db mariadb --help'))->count())->toBe(1)
        ->and($lines->contains(fn ($l) => str_contains($l, '--sandbox')))->toBeFalse()
        ->and($lines->contains(fn ($l) => str_contains($l, 'exec -T db sh -c') && str_contains($l, 'mariadb -u"$MARIADB_USER" --default-character-set=utf8mb4 "$MARIADB_DATABASE"')))->toBeTrue()
        ->and((string) file_get_contents($this->base.'/state/update-test.log'))->toContain('mariadb client without --sandbox; dump content is trusted via volume isolation');
});

it('names maintenance as staying on when the app cannot be asked', function () {
    $this->docker->fail['health_new'] = true;
    $this->docker->fail['restore'] = true;
    $this->updater->run('2.0.0');
    $this->docker->fail = ['pull_previous' => true, 'app_down' => true];
    $this->docker->images = [FakeDocker::NEW];
    $this->docker->down = false;

    expect($this->updater->rollbackToLatest())->toBeFalse()
        ->and($this->state->read()['error'])->toContain(__('update.error.maintenance_left'));
});

it('resolve marks a failed rollback as resolved by hand, and nothing else', function () {
    expect($this->updater->resolveFailedRollback())->toBe('nothing to resolve');
    $this->docker->fail['health_new'] = true;
    $this->docker->fail['restore'] = true;
    $this->updater->run('2.0.0');
    expect($this->state->read()['status'])->toBe('failed_rollback');

    expect($this->updater->resolveFailedRollback())->toContain('resolved');
    expect($this->state->read()['status'])->toBe('rolled_back')->and($this->state->read()['reason'])->toBe('resolved_by_hand');
});
