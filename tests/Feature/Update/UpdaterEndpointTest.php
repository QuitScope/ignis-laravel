<?php

use App\Update\DockerDriver;
use App\Update\DockerUpdateEndpoint;
use App\Update\DockerUpdateService;
use App\Update\UpdateLock;
use App\Update\UpdateLog;
use App\Update\UpdateState;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeDocker;
use Tests\Support\UpdateFixtures;

const UE_SECRET = 'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210';

beforeEach(function () {
    Http::preventStrayRequests();
    $this->base = sys_get_temp_dir().'/ignis-ue-'.bin2hex(random_bytes(4));
    mkdir($this->base, 0777, true);
    register_shutdown_function(fn () => (new Filesystem)->deleteDirectory($this->base));
    config(['ignis.update.state_path' => $this->base.'/state.json', 'ignis.update.backup_path' => $this->base.'/backups', 'ignis.update.log_dir' => $this->base]);
    UpdateFixtures::setUp('1.0.0');
    UpdateFixtures::fake(['image' => 'ghcr.io/ignis-test/ignis', 'image_digest' => 'sha256:'.str_repeat('b', 64)]);
    $this->lock = new UpdateLock;
    $updater = new DockerUpdateService(new UpdateState, $this->lock, new UpdateLog($this->base.'/update-test.log'));
    $this->docker = new FakeDocker($updater);
    $updater->run = $this->docker->__invoke(...);
    $this->spawned = [];
    $this->endpoint = new DockerUpdateEndpoint(UE_SECRET, $updater, $this->lock, $this->base.'/seen.json', function (array $args) {
        $this->spawned[] = $args;
    });
});

/** @return array{0: int, 1: array<string, mixed>} a correctly signed request */
function ueSend(DockerUpdateEndpoint $e, string $path, string $body, ?int $ts = null, ?string $sig = null, ?int $now = null, string $method = 'POST', ?string $nonce = null): array
{
    $ts ??= time();
    $nonce ??= bin2hex(random_bytes(16));

    return $e->handle($method, $path, (string) $ts, $nonce, $sig ?? DockerDriver::signature(UE_SECRET, (string) $ts, $nonce, $method, $path, $body), $body, $now);
}

it('answers a signed ping', function () {
    expect(ueSend($this->endpoint, '/ping', '{"action":"ping"}'))->toBe([200, ['ok' => true, 'running' => false]]);
});

it('rejects a bad hmac, an old or future timestamp, a missing header and a replay', function () {
    $body = '{"action":"ping"}';
    expect(ueSend($this->endpoint, '/ping', $body, sig: str_repeat('0', 64))[0])->toBe(401)
        ->and(ueSend($this->endpoint, '/ping', $body, sig: DockerDriver::signature('another-secret-another-secret-123', (string) time(), str_repeat('0', 32), 'POST', '/ping', $body), nonce: str_repeat('0', 32))[0])->toBe(401)
        ->and(ueSend($this->endpoint, '/ping', $body, ts: time() - 61)[0])->toBe(401)
        ->and(ueSend($this->endpoint, '/ping', $body, ts: time() + 61)[0])->toBe(401)
        ->and($this->endpoint->handle('POST', '/ping', null, null, null, $body)[0])->toBe(401);
    $ts = time();
    $nonce = str_repeat('1', 32);
    expect(ueSend($this->endpoint, '/ping', $body, ts: $ts, nonce: $nonce)[0])->toBe(200)
        ->and(ueSend($this->endpoint, '/ping', $body, ts: $ts, nonce: $nonce)[0])->toBe(401)
        ->and(ueSend($this->endpoint, '/ping', $body, ts: $ts)[0])->toBe(200);
    $sig = DockerDriver::signature(UE_SECRET, (string) $ts, $nonce, 'POST', '/ping', '{"action":"rollback"}');
    expect(ueSend($this->endpoint, '/rollback', '{"action":"rollback"}', ts: $ts, sig: $sig, nonce: $nonce)[0])->toBe(401)
        ->and($this->spawned)->toBe([]);
});

it('refuses other methods, paths, oversized bodies, unknown actions and extra or missing fields', function () {
    foreach ([
        'GET' => ['GET', '/ping', '{"action":"ping"}', 405],
        'unknown path' => ['POST', '/exec', '{"action":"ping"}', 404],
        'too large' => ['POST', '/start', '{"version":"2.0.0","skip_trial":false,"x":"'.str_repeat('a', 1100).'"}', 413],
        'unknown action' => ['POST', '/rollback', '{"action":"shell"}', 422],
        'ping as rollback' => ['POST', '/rollback', '{"action":"ping"}', 422],
        'extra field image' => ['POST', '/start', '{"version":"2.0.0","skip_trial":false,"image":"evil/image:latest"}', 422],
        'extra field command' => ['POST', '/start', '{"version":"2.0.0","skip_trial":false,"command":"rm -rf /"}', 422],
        'missing skip_trial' => ['POST', '/start', '{"version":"2.0.0"}', 422],
        'list body' => ['POST', '/ping', '["ping"]', 422],
        'not json' => ['POST', '/ping', 'action=ping', 422],
        'non-semver' => ['POST', '/start', '{"version":"2.0","skip_trial":false}', 422],
        'injection' => ['POST', '/start', '{"version":"2.0.0;id","skip_trial":false}', 422],
        'pre-release' => ['POST', '/start', '{"version":"2.0.0-rc.1","skip_trial":false}', 422],
        'skip_trial not bool' => ['POST', '/start', '{"version":"2.0.0","skip_trial":"yes"}', 422],
    ] as [$method, $path, $body, $status]) {
        expect(ueSend($this->endpoint, $path, $body, method: $method)[0])->toBe($status)->and($this->spawned)->toBe([]);
    }
});

it('starts a verified version detached with nothing but version and trial flag', function () {
    expect(ueSend($this->endpoint, '/start', '{"version":"2.0.0","skip_trial":true}'))->toBe([202, ['ok' => true]])
        ->and($this->spawned)->toBe([['run', '2.0.0', '1']]);
    expect(ueSend($this->endpoint, '/rollback', '{"action":"rollback"}'))->toBe([202, ['ok' => true]])
        ->and($this->spawned[1])->toBe(['rollback']);
});

it('refuses unsigned, foreign-signed, downgrade and image-less releases on its own, whatever the app checked', function (array $manifest, ?string $signature, string $installed, string $reason) {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    UpdateFixtures::fake($manifest + ['image' => 'ghcr.io/ignis-test/ignis', 'image_digest' => 'sha256:'.str_repeat('b', 64)], signature: $signature);
    $this->docker->installed = $installed;
    [$status, $data] = ueSend($this->endpoint, '/start', '{"version":"2.0.0","skip_trial":false}');
    expect($status)->toBe(422)->and($data['reason'])->toBe($reason)->and($data['message'])->toBeString()
        ->and($this->spawned)->toBe([]);
})->with([
    'unsigned' => [[], 'AAAA', '1.0.0', 'signature'],
    'other key' => [[], base64_encode(str_repeat("\1", 64)), '1.0.0', 'signature'],
    'downgrade' => [[], null, '2.0.0', 'not_newer'],
    'newer installed' => [[], null, '3.0.0', 'not_newer'],
    'no image' => [['image' => null, 'image_digest' => null], null, '1.0.0', 'image_missing'],
]);

it('refuses a version that is not the latest release', function () {
    [$status, $data] = ueSend($this->endpoint, '/start', '{"version":"9.9.9","skip_trial":false}');
    expect($status)->toBe(422)->and($data['reason'])->toBe('release_changed')->and($this->spawned)->toBe([]);
});

it('refuses while an update runs', function () {
    $owner = new UpdateLock;
    expect($owner->acquire())->toBeTrue();
    try {
        expect(ueSend($this->endpoint, '/start', '{"version":"2.0.0","skip_trial":false}')[0])->toBe(409)
            ->and(ueSend($this->endpoint, '/rollback', '{"action":"rollback"}')[0])->toBe(409)
            ->and(ueSend($this->endpoint, '/ping', '{"action":"ping"}')[1]['running'])->toBeTrue();
    } finally {
        $owner->release();
    }
    expect($this->spawned)->toBe([]);
});

it('refuses everything without a configured secret', function () {
    $e = new DockerUpdateEndpoint('', app(DockerUpdateService::class), $this->lock, $this->base.'/seen2.json', fn () => null);
    $ts = (string) time();
    $n = str_repeat('2', 32);
    expect($e->handle('POST', '/ping', $ts, $n, DockerDriver::signature('', $ts, $n, 'POST', '/ping', '{"action":"ping"}'), '{"action":"ping"}')[0])->toBe(401);
});
