<?php

use App\Discord\DiscordException;
use App\Discord\DiscordHttp;
use App\Discord\DiscordWebhook;
use App\Discord\Embeds;
use App\Discord\WebhookKind;
use App\Jobs\SendDiscordWebhook;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\SettingDefinitions;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const WH_URL = 'https://discord.com/api/webhooks/123456789012345678/SECRETtoken_abc-123';
const WH_URL2 = 'https://discordapp.com/api/webhooks/223456789012345678/OTHERsecret_456';

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Http::preventStrayRequests();
    config(['app.url' => 'https://ignis.example']);
    $this->logs = [];
    Event::listen(MessageLogged::class, fn (MessageLogged $m) => $this->logs[] = $m->message.json_encode($m->context));
});

function whAdmin(): User
{
    $role = Role::create(['name' => 'W'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions(['admin']);

    return User::factory()->withRole($role)->create();
}

function whStore(WebhookKind $kind = WebhookKind::EnotfProtocol, string $url = WH_URL): void
{
    app(Settings::class)->set($kind->settingKey(), $url, encrypted: true);
}

function whStored(WebhookKind $kind = WebhookKind::EnotfProtocol): mixed
{
    return app(Settings::class)->get($kind->settingKey());
}

function whNoLeak($test, string ...$secrets): void
{
    $haystack = [Activity::all()->toJson(), implode("\n", $test->logs), json_encode(DB::table('failed_jobs')->get()), json_encode(DB::table('jobs')->get())];
    foreach ($secrets as $secret) {
        foreach ($haystack as $h) {
            expect($h)->not->toContain($secret);
        }
    }
}

it('registers the three keys as encrypted secrets with the url rule', function () {
    $defs = app(SettingDefinitions::class);
    foreach (WebhookKind::cases() as $kind) {
        $d = $defs->definition('discord.webhooks.'.$kind->value);
        expect($d)->not->toBeNull()->and($d->secret)->toBeTrue()->and($d->rules)->not->toBeEmpty();
    }
});

it('stores a valid url encrypted and the page only says set', function () {
    $admin = whAdmin();
    $this->actingAs($admin)->put('/settings/system/discord/webhooks/enotf_protocol', ['webhook_url' => '  '.WH_URL.' '])
        ->assertRedirect('/settings/system/discord')->assertSessionHas('status', 'settings.discord.webhooks.saved');

    expect(whStored())->toBe(WH_URL)
        ->and(Setting::where('key', 'discord.webhooks.enotf_protocol')->value('is_encrypted'))->toBeTruthy()
        ->and(Setting::where('key', 'discord.webhooks.enotf_protocol')->value('value'))->not->toContain('SECRETtoken');

    $page = $this->actingAs($admin)->get('/settings/system/discord');
    $page->assertInertia(fn (Assert $p) => $p->where('webhooks.0.kind', 'enotf_protocol')->where('webhooks.0.set', true)->where('webhooks.1.set', false)->where('webhooks.2.set', false));
    expect($page->getContent())->not->toContain('SECRETtoken')
        ->and(json_encode(session()->all()))->not->toContain('SECRETtoken');
    whNoLeak($this, 'SECRETtoken_abc');

    expect(Activity::where('description', 'Discord-Webhook geändert')->sole()->details)->toBe('enotf_protocol: geändert');
});

it('refuses a bad url without echoing it and keeps the old one', function (string $bad) {
    whStore();
    $this->actingAs(whAdmin())->from('/settings/system/discord')->put('/settings/system/discord/webhooks/enotf_protocol', ['webhook_url' => $bad])
        ->assertRedirect('/settings/system/discord')->assertSessionHasErrors('webhook_url');
    expect(whStored())->toBe(WH_URL)
        ->and(json_encode(session()->all()))->not->toContain('evilsecret')
        ->and(session()->getOldInput())->not->toHaveKey('webhook_url');
    whNoLeak($this, 'evilsecret');
})->with([
    'foreign host' => 'https://evil.com/api/webhooks/123456789012345678/evilsecret',
    'lookalike' => 'https://discord.com.evil.com/api/webhooks/123456789012345678/evilsecret',
    'userinfo' => 'https://discord.com@evil.com/api/webhooks/123456789012345678/evilsecret',
    'http' => 'http://discord.com/api/webhooks/123456789012345678/evilsecret',
]);

it('answers an array as webhook_url with a validation error, not a 500', function () {
    whStore();
    $this->actingAs(whAdmin())->from('/settings/system/discord')->put('/settings/system/discord/webhooks/enotf_protocol', ['webhook_url' => ['a' => 'b']])
        ->assertRedirect('/settings/system/discord')->assertSessionHasErrors('webhook_url');
    expect(whStored())->toBe(WH_URL);
});

it('leaves everything as it was for an empty field', function () {
    whStore();
    $this->actingAs(whAdmin())->put('/settings/system/discord/webhooks/enotf_protocol', ['webhook_url' => ''])->assertSessionHasNoErrors();
    expect(whStored())->toBe(WH_URL)->and(Activity::count())->toBe(0);
});

it('removes a url and audits only that', function () {
    whStore();
    $this->actingAs(whAdmin())->delete('/settings/system/discord/webhooks/enotf_protocol')->assertRedirect('/settings/system/discord');
    expect(app(Settings::class)->has('discord.webhooks.enotf_protocol'))->toBeFalse()
        ->and(Activity::where('description', 'Discord-Webhook geändert')->sole()->details)->toBe('enotf_protocol: entfernt');
    whNoLeak($this, 'SECRETtoken_abc');
});

it('needs the admin permission and a known kind', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->put('/settings/system/discord/webhooks/enotf_protocol', ['webhook_url' => WH_URL])->assertForbidden();
    $this->actingAs($user)->post('/settings/system/discord/webhooks/enotf_protocol/test')->assertForbidden();
    $this->actingAs($user)->delete('/settings/system/discord/webhooks/enotf_protocol')->assertForbidden();
    $this->actingAs(whAdmin())->put('/settings/system/discord/webhooks/nope', ['webhook_url' => WH_URL])->assertNotFound();
    expect(whStored())->toBe('');
});

it('sends the sample embed on test, synchronously, without pings', function () {
    whStore(WebhookKind::FireProtocol, WH_URL2);
    Http::fake([WH_URL2 => Http::response('', 204)]);

    $this->actingAs(whAdmin())->post('/settings/system/discord/webhooks/fire_protocol/test')
        ->assertRedirect('/settings/system/discord')->assertSessionHas('status', 'settings.discord.webhooks.test_sent');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r->url() === WH_URL2 && $r->method() === 'POST'
        && $r->data()['allowed_mentions'] === ['parse' => []]
        && $r->data()['embeds'][0]['color'] === 15158332
        && isset($r->data()['embeds'][0]['timestamp']));
});

it('shows a german error on test when nothing is stored or Discord refuses', function () {
    $admin = whAdmin();
    $this->actingAs($admin)->post('/settings/system/discord/webhooks/enotf_prereg/test')->assertSessionHasErrors(['webhook_test' => WebhookKind::EnotfPrereg->label().': '.__('settings.discord.webhooks.test_unset')]);

    whStore(WebhookKind::EnotfPrereg);
    Http::fake([WH_URL => Http::response(['message' => 'Unknown Webhook', 'code' => 10015], 404)]);
    $this->actingAs($admin)->post('/settings/system/discord/webhooks/enotf_prereg/test')
        ->assertSessionHasErrors(['webhook_test' => WebhookKind::EnotfPrereg->label().': '.__('discord.error.webhook_gone')]);
    whNoLeak($this, 'SECRETtoken_abc');
});

it('does not follow a redirect', function () {
    whStore();
    Http::fake([
        WH_URL => Http::response('', 302, ['Location' => 'https://evil.example/steal']),
        'https://evil.example/*' => Http::response('', 204),
    ]);
    $this->actingAs(whAdmin())->post('/settings/system/discord/webhooks/enotf_protocol/test')->assertSessionHasErrors('webhook_test');
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'evil.example'));
});

it('refuses a stored url that is not on the allowlist (changed behind the form)', function () {
    whStore(WebhookKind::EnotfProtocol, 'https://evil.example/api/webhooks/123456789012345678/leakme');
    $this->actingAs(whAdmin())->post('/settings/system/discord/webhooks/enotf_protocol/test')->assertSessionHasErrors('webhook_test');
    Http::assertNothingSent();
    whNoLeak($this, 'leakme');
});

it('reads at most a few kilobytes of an answer', function () {
    $answer = fn (string $body) => new Response(new GuzzleHttp\Psr7\Response(200, [], $body));

    expect(DiscordHttp::json($answer('{"a":1}')))->toBe(['a' => 1])
        ->and(DiscordHttp::json($answer(json_encode(['a' => str_repeat('x', DiscordHttp::CAP * 2)]))))->toBe([])
        ->and(DiscordHttp::json($answer('not json')))->toBe([]);
});

it('reads the answer from the start even when the body was read before', function () {
    $body = new GuzzleHttp\Psr7\Response(200, [], '{"a":1}');
    $body->getBody()->getContents();

    expect(DiscordHttp::json(new Response($body)))->toBe(['a' => 1]);
});

it('queues a job whose payload has no url', function () {
    Queue::fake();
    whStore();
    DiscordWebhook::dispatch(WebhookKind::EnotfProtocol, Embeds::enotfProtocol(['enr' => '42']));
    Queue::assertPushedOn('default', SendDiscordWebhook::class);

    $job = Queue::pushed(SendDiscordWebhook::class)->first();
    expect(serialize($job))->not->toContain('SECRETtoken')->not->toContain('discord.com/api/webhooks')
        ->and($job->tries)->toBe(3);
});

it('job: delivers on 204', function () {
    whStore();
    Http::fake([WH_URL => Http::response('', 204)]);
    (new SendDiscordWebhook(WebhookKind::EnotfProtocol, Embeds::enotfProtocol(['enr' => '42'])))->handle(app(DiscordWebhook::class));
    Http::assertSentCount(1);
});

it('job: retries an outage by rethrowing', function () {
    whStore();
    Http::fake([WH_URL => Http::response('', 502)]);
    expect(fn () => (new SendDiscordWebhook(WebhookKind::EnotfProtocol, ['title' => 't']))->handle(app(DiscordWebhook::class)))->toThrow(DiscordException::class);
    whNoLeak($this, 'SECRETtoken_abc');
});

it('job: drops a refusal without retry and never logs the url', function (int $status) {
    whStore();
    Http::fake([WH_URL => Http::response(['message' => 'bad', 'code' => 50035], $status)]);
    $job = (new SendDiscordWebhook(WebhookKind::EnotfProtocol, ['title' => 't']))->withFakeQueueInteractions();

    $job->handle(app(DiscordWebhook::class));

    $job->assertNotReleased()->assertNotFailed();
    expect(implode('
', $this->logs))->toContain('Discord webhook not delivered');
    whNoLeak($this, 'SECRETtoken_abc', '123456789012345678');
})->with([400, 401, 404]);

it('job: a 429 releases for as long as Discord asks', function () {
    whStore();
    Http::fake([WH_URL => Http::response(['retry_after' => 4.2], 429)]);
    $job = (new SendDiscordWebhook(WebhookKind::EnotfProtocol, ['title' => 't']))->withFakeQueueInteractions();

    $job->handle(app(DiscordWebhook::class));

    $job->assertReleased(5);
});

it('job: nothing stored means nothing sent', function () {
    (new SendDiscordWebhook(WebhookKind::EnotfProtocol, ['title' => 't']))->handle(app(DiscordWebhook::class));
    Http::assertNothingSent();
});

it('job: a network failure is retryable and carries no url', function () {
    whStore();
    Http::fake([WH_URL => fn () => throw new ConnectionException('cURL error 6 for '.WH_URL)]);
    try {
        (new SendDiscordWebhook(WebhookKind::EnotfProtocol, ['title' => 't']))->handle(app(DiscordWebhook::class));
        $this->fail('expected an exception');
    } catch (DiscordException $e) {
        expect($e->retryable())->toBeTrue()->and($e->getMessage())->not->toContain('SECRETtoken')->and($e->getTraceAsString())->not->toContain('SECRETtoken');
    }
});

describe('embeds', function () {
    it('builds the eNOTF protocol embed', function () {
        $e = Embeds::enotfProtocol(['enr' => '2026/1', 'last_edit' => '2026-10-20 10:00:00']);
        expect($e['color'])->toBe(3447003)
            ->and($e['title'])->toBe('📋 eNOTF-Protokoll freigegeben')
            ->and($e['url'])->toBe('https://ignis.example/enotf/p/2026%2F1')
            ->and($e['fields'][0])->toBe(['name' => 'Einsatznummer', 'value' => '**#2026/1**', 'inline' => true])
            ->and($e['fields'][1]['value'])->toBe('2026-10-20 10:00:00');
    });

    it('builds the fireTab embed with the five fields', function () {
        $e = Embeds::fireProtocol(['id' => 7, 'incident_number' => 'F-1', 'location' => 'Hauptstr. 1', 'keyword' => 'B2', 'started_at' => '10:00', 'leader_name' => 'Max']);
        expect($e['color'])->toBe(15158332)->and($e['title'])->toBe('🚒 fireTab-Protokoll freigegeben')
            ->and($e['url'])->toBe('https://ignis.example/firetab/qm/incidents/7')
            ->and(array_column($e['fields'], 'name'))->toBe(['Einsatznummer', 'Einsatzort', 'Stichwort', 'Einsatzbeginn', 'Einsatzleiter']);
    });

    it('colours the pre-registration by priority', function (mixed $priority, int $color) {
        $e = Embeds::prereg(['priority' => $priority, 'arrival' => '2026-10-20 14:30:00', 'vehicle' => 'RTW 1', 'hospital' => 'Klinikum']);
        expect($e['color'])->toBe($color)->and($e['title'])->toBe('🏥 Neue Klinik-Voranmeldung')
            ->and($e['url'])->toBe('https://ignis.example/enotf/schnittstelle')
            ->and($e['fields'][1]['value'])->toBe('20.10.2026 14:30')
            ->and(array_column($e['fields'], 'value'))->toHaveCount(4)->toContain('RTW 1', 'Klinikum');
    })->with([[2, 15158332], ['1', 16776960], [0, 5763719], [null, 10181046], ['x', 10181046]]);

    it('never leaves a field empty and cuts at 1024', function () {
        config(['app.url' => '']);
        $e = Embeds::prereg(['vehicle' => str_repeat('ä', 3000), 'hospital' => '   ', 'arrival' => 'not a date']);
        foreach ($e['fields'] as $f) {
            expect($f['value'])->not->toBe('')->and(mb_strlen($f['value']))->toBeLessThanOrEqual(1024);
        }
        expect($e['fields'][2]['value'])->toEndWith('…')->and(mb_strlen($e['fields'][2]['value']))->toBe(1024)
            ->and($e['fields'][3]['value'])->toBe('Unbekannt')->and($e['fields'][1]['value'])->toBe('Unbekannt')
            ->and($e)->not->toHaveKey('url');
    });

    it('keeps the whole embed within the 6000 characters by cutting the longest texts', function () {
        $fields = array_map(fn ($c) => ['name' => 'n', 'value' => str_repeat($c, 1024), 'inline' => true], ['a', 'b', 'c', 'd', 'e', 'f', 'g']);
        $e = Embeds::fit(['title' => 'T', 'description' => 'D', 'fields' => $fields]);
        $total = 2 + array_sum(array_map(fn ($f) => mb_strlen($f['name']) + mb_strlen($f['value']), $e['fields']));

        expect($total)->toBe(6000)->and(Embeds::fit(['title' => 'T', 'fields' => [['name' => 'n', 'value' => 'v', 'inline' => true]]])['fields'][0]['value'])->toBe('v');
        foreach ($e['fields'] as $f) {
            expect($f['value'])->not->toBe('');
        }
    });

    it('builds links from app.url only and drops them when it is unusable', function () {
        config(['app.url' => 'not a url']);
        expect(Embeds::enotfProtocol(['enr' => '1']))->not->toHaveKey('url');
        config(['app.url' => 'https://ignis.example/']);
        expect(Embeds::enotfProtocol(['enr' => '1'])['url'])->toBe('https://ignis.example/enotf/p/1');
    });

    it('has a sample for every kind', function () {
        foreach (WebhookKind::cases() as $kind) {
            expect(Embeds::sample($kind)['fields'])->not->toBeEmpty();
        }
    });
});
