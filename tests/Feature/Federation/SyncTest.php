<?php

use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Federation\Jobs\SyncLink;
use Modules\Federation\Models\FederatedPerson;
use Modules\Federation\Models\FederatedProtocol;
use Modules\Federation\Models\FederationLink;
use Modules\Federation\Models\SyncRun;
use Modules\Federation\Support\FederatedPersonSearch;
use Modules\Federation\Support\PeerHttp;

uses(RefreshDatabase::class)->group('mariadb');

beforeEach(function () {
    app(Settings::class)->set('modules.federation.enabled', true);
    PeerHttp::resolveUsing(fn () => ['93.184.216.34']);
    $this->link = (new FederationLink)->forceFill(['instance_id' => (string) Str::uuid(), 'name' => 'Peer', 'url' => 'https://peer.example',
        'secret_outgoing' => Str::random(40), 'secret_incoming' => Str::random(40), 'consume_personnel' => true, 'consume_enotf' => true]);
    $this->link->save();
});

afterEach(fn () => PeerHttp::resolveUsing(null));

function fed_fake_peer(bool &$fail): void
{
    $p = fn (int $id) => ['id' => $id, 'visible' => true, 'enr' => "E$id", 'pat_vorname' => 'Max', 'sendezeit' => '2026-10-01T10:00:00+00:00', 'updated_at' => '2026-10-01T10:00:00+00:00'];
    Http::fake(function (Request $r) use (&$fail, $p) {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
        $after = $q['after'] ?? null;
        if (str_contains($r->url(), '/personnel')) {
            return match ($after) {
                null => Http::response(['data' => [['id' => 1, 'full_name' => 'A One']], 'cursor' => 'p1', 'next' => 'p1']),
                'p1' => $fail ? Http::response([], 500) : Http::response(['data' => [['id' => 2, 'full_name' => 'B Two']], 'cursor' => 'p2', 'next' => null]),
            };
        }

        return match ($after) {
            null => Http::response(['data' => [$p(1), $p(2)], 'cursor' => 'c1', 'next' => 'c1']),
            'c1' => $fail ? Http::response([], 500) : Http::response(['data' => [['id' => 1, 'visible' => false], $p(3)], 'cursor' => 'c2', 'next' => null]),
            'c2' => Http::response(['data' => [], 'cursor' => null, 'next' => null]),
        };
    });
}

it('syncs every page idempotently, resumes after a failure and applies tombstones', function () {
    $fail = true;
    fed_fake_peer($fail);
    (new FederatedPerson)->forceFill(['link_id' => $this->link->id, 'remote_id' => 99, 'full_name' => 'Gone', 'seen_run' => (string) Str::uuid()])->save();
    $protocols = fn () => FederatedProtocol::query()->orderBy('remote_id')->pluck('remote_id')->all();

    SyncLink::dispatchSync($this->link->id);
    expect($protocols())->toBe([1, 2])
        ->and($this->link->fresh()->cursors['enotf'])->toBe('c1')
        ->and($this->link->fresh()->last_sync_status)->toBe('error')
        ->and($this->link->fresh()->last_sync_error)->toBe('http_500')
        ->and(FederatedPerson::query()->where('remote_id', 99)->exists())->toBeTrue();

    $fail = false;
    SyncLink::dispatchSync($this->link->id);
    expect($protocols())->toBe([2, 3])
        ->and($this->link->fresh()->cursors['enotf'])->toBe('c2')
        ->and($this->link->fresh()->last_sync_status)->toBe('success')
        ->and(FederatedPerson::query()->orderBy('remote_id')->pluck('remote_id')->all())->toBe([1, 2])
        ->and(FederatedProtocol::query()->where('remote_id', 2)->value('data')['pat_vorname'])->toBe('Max');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/enotf') && str_contains($r->url(), 'after=c1'));

    SyncLink::dispatchSync($this->link->id);
    $this->link->forceFill(['cursors' => null])->save();
    SyncLink::dispatchSync($this->link->id);
    expect($protocols())->toBe([2, 3])
        ->and(FederatedPerson::query()->count())->toBe(2)
        ->and(SyncRun::query()->where('status', 'error')->count())->toBe(2);
});

it('hides cached personnel once the link stops consuming it', function () {
    (new FederatedPerson)->forceFill(['link_id' => $this->link->id, 'remote_id' => 5, 'full_name' => 'Anna Peer', 'seen_run' => (string) Str::uuid()])->save();
    expect(FederatedPersonSearch::search('Anna', 10))->toHaveCount(1);
    $this->link->forceFill(['consume_personnel' => false])->save();
    expect(FederatedPersonSearch::search('Anna', 10))->toBe([])->and(FederatedPersonSearch::resolve("fed:{$this->link->id}:5"))->toBeNull();
});

it('queues due links only and prunes old caches, runs and an expired invite', function () {
    Queue::fake();
    $this->link->forceFill(['last_sync_at' => now()->subMinutes(5)])->save();
    $this->artisan('federation:sync')->assertSuccessful();
    Queue::assertNothingPushed();
    $this->travel(11)->minutes();
    $this->artisan('federation:sync')->assertSuccessful();
    Queue::assertPushed(SyncLink::class, 1);

    (new FederatedProtocol)->forceFill(['link_id' => $this->link->id, 'remote_id' => 1, 'remote_updated_at' => now()->subDays(91)])->save();
    (new FederatedProtocol)->forceFill(['link_id' => $this->link->id, 'remote_id' => 2, 'remote_updated_at' => now()->subDays(5)])->save();
    (new SyncRun)->forceFill(['link_id' => $this->link->id, 'type' => 'enotf', 'status' => 'success', 'created_at' => now()->subDays(31)])->save();
    app(Settings::class)->set('federation.invite', ['hash' => 'h', 'expires' => now()->subMinute()->getTimestamp()]);

    $this->artisan('federation:prune')->assertSuccessful();
    expect(FederatedProtocol::query()->pluck('remote_id')->all())->toBe([2])
        ->and(SyncRun::query()->count())->toBe(0)
        ->and(app(Settings::class)->has('federation.invite'))->toBeFalse();
});
