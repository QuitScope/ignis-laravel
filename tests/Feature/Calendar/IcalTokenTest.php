<?php

use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Modules\Calendar\Support\IcalToken;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/calendarHelpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function ical_db_dump(): string
{
    $out = '';
    foreach (Schema::getTableListing() as $table) {
        $name = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
        $out .= json_encode(DB::table($name)->get(), JSON_UNESCAPED_UNICODE);
    }

    return $out;
}

it('stores only the sha256 hash and returns the 64 hex token once', function () {
    $user = cal_user();
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->message.json_encode($e->context);
    });

    $token = IcalToken::issue($user);

    expect($token)->toMatch('/^[a-f0-9]{64}$/')
        ->and($user->fresh()->calendar_token_hash)->toBe(hash('sha256', $token))
        ->and(ical_db_dump())->not->toContain($token)
        ->and(implode("\n", $logged))->not->toContain($token);
    expect(IcalToken::resolve($token)?->id)->toBe($user->id);
});

it('creates the subscription by POST, shows the URL once and audits without the token', function () {
    $user = cal_user();
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->message.json_encode($e->context);
    });

    $this->actingAs($user)->getJson('/calendar/subscription')->assertOk()->assertExactJson(['has_subscription' => false]);

    $res = $this->actingAs($user)->postJson('/calendar/subscription')->assertOk();
    $url = $res->json('url');
    expect($url)->toMatch('#^http://localhost/api/calendar/ical/[a-f0-9]{64}$#');
    $token = substr($url, -64);
    expect(strtolower((string) $res->headers->get('Cache-Control')))->toContain('no-store');

    $show = $this->actingAs($user)->getJson('/calendar/subscription')->assertOk()->assertExactJson(['has_subscription' => true]);
    expect($show->getContent())->not->toContain($token);

    $audit = Activity::query()->where('description', 'Kalender-Abo erneuert')->sole();
    expect($audit->log_name)->toBe('Kalender')
        ->and($audit->causer_id)->toBe($user->id)
        ->and(ical_db_dump())->not->toContain($token)
        ->and(implode("\n", $logged))->not->toContain($token);

    $this->get('/api/calendar/ical/'.$token)->assertOk();
});

it('invalidates the previous link immediately when regenerated', function () {
    $user = cal_user();
    $first = substr($this->actingAs($user)->postJson('/calendar/subscription')->json('url'), -64);
    $second = substr($this->actingAs($user)->postJson('/calendar/subscription')->json('url'), -64);

    expect($first)->not->toBe($second)
        ->and(IcalToken::resolve($first))->toBeNull()
        ->and(IcalToken::resolve($second)?->id)->toBe($user->id);
    $this->get('/api/calendar/ical/'.$first)->assertNotFound();
    $this->get('/api/calendar/ical/'.$second)->assertOk();
    expect(Activity::query()->where('description', 'Kalender-Abo erneuert')->count())->toBe(2);
});

it('revokes the subscription by DELETE', function () {
    $user = cal_user();
    $token = IcalToken::issue($user);

    $this->actingAs($user)->deleteJson('/calendar/subscription')->assertNoContent();

    expect($user->fresh()->calendar_token_hash)->toBeNull()->and(IcalToken::resolve($token))->toBeNull();
    $this->get('/api/calendar/ical/'.$token)->assertNotFound();
    $this->actingAs($user)->getJson('/calendar/subscription')->assertExactJson(['has_subscription' => false]);
    expect(Activity::query()->where('description', 'Kalender-Abo widerrufen')->where('log_name', 'Kalender')->count())->toBe(1);
});

it('resolves only active accounts with a calendar right while the module is on', function () {
    $ok = cal_user();
    $inactive = cal_user();
    $noRight = cal_user(['calendar.create']);
    $tokens = [IcalToken::issue($ok), IcalToken::issue($inactive), IcalToken::issue($noRight)];
    $inactive->forceFill(['is_active' => false])->save();

    expect(IcalToken::resolve($tokens[0])?->id)->toBe($ok->id)
        ->and(IcalToken::resolve($tokens[1]))->toBeNull()
        ->and(IcalToken::resolve($tokens[2]))->toBeNull()
        ->and(IcalToken::resolve(str_repeat('a', 64)))->toBeNull();

    foreach (['', 'xyz', strtoupper($tokens[0]), substr($tokens[0], 0, 19), $tokens[0].'a', $tokens[0]."\n", ' '.$tokens[0]] as $bad) {
        expect(IcalToken::resolve($bad))->toBeNull();
    }

    foreach ([cal_user(['calendar.manage']), cal_user(['admin']), User::factory()->superAdmin()->create()] as $user) {
        expect(IcalToken::resolve(IcalToken::issue($user))?->id)->toBe($user->id);
    }

    app(Settings::class)->set('modules.calendar.enabled', false);
    expect(IcalToken::resolve($tokens[0]))->toBeNull();
});

it('accepts an imported legacy token (48 hex) by the hash of its plaintext', function () {
    $user = cal_user();
    $legacy = str_repeat('0123456789abcdef', 3);
    $user->forceFill(['calendar_token_hash' => hash('sha256', $legacy)])->save();

    expect(IcalToken::resolve($legacy)?->id)->toBe($user->id);
    $this->get('/api/calendar/ical/'.$legacy)->assertOk();
});

it('guards the subscription endpoints', function () {
    foreach (['getJson', 'postJson', 'deleteJson'] as $method) {
        $this->{$method}('/calendar/subscription')->assertUnauthorized();
    }
    $this->get('/calendar/subscription')->assertRedirect('/login');

    $noView = cal_user(['calendar.create']);
    foreach (['getJson', 'postJson', 'deleteJson'] as $method) {
        $this->actingAs($noView)->{$method}('/calendar/subscription')->assertForbidden();
    }
    expect($noView->fresh()->calendar_token_hash)->toBeNull();

    $user = cal_user();
    app(Settings::class)->set('modules.calendar.enabled', false);
    foreach (['getJson', 'postJson', 'deleteJson'] as $method) {
        $this->actingAs($user)->{$method}('/calendar/subscription')->assertNotFound();
    }
});

it('builds the link from app.url, never from the Host header', function () {
    config(['app.url' => 'https://intra.example.org/']);
    $user = cal_user();

    $url = $this->actingAs($user)->withHeader('Host', 'evil.example.com')->postJson('/calendar/subscription')->json('url');

    expect($url)->toMatch('#^https://intra\.example\.org/api/calendar/ical/[a-f0-9]{64}$#');
});

it('never exposes the hash in serialisation or the shared page props', function () {
    $user = cal_user();
    IcalToken::issue($user);
    $user = $user->fresh();

    expect($user->toArray())->not->toHaveKey('calendar_token_hash');
    $page = $this->actingAs($user)->get('/calendar')->assertOk();
    expect($page->getContent())->not->toContain((string) $user->calendar_token_hash);
});
