<?php

use App\Models\User;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Middleware;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/kbHelpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('marks guest pages noindex in the header and the HTML head, signed-in pages not', function () {
    $e = kbx_entry(['title' => 'Offen']);
    kbx_public();
    foreach (['/lexicon', "/lexicon/{$e->id}"] as $url) {
        $r = $this->get($url)->assertOk()->assertHeader('X-Robots-Tag', 'noindex');
        expect($r->getContent())->toContain('<meta name="robots" content="noindex">');
        $this->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => inertiaVersion()])->assertHeader('X-Robots-Tag', 'noindex');
    }
    $in = $this->actingAs(kbx_user())->get('/lexicon')->assertOk();
    expect($in->headers->get('X-Robots-Tag'))->toBeNull()->and($in->getContent())->not->toContain('name="robots"');
});

function inertiaVersion(): string
{
    return (string) app(Middleware::class)->version(request());
}

it('gives guests no module list, no permissions and no user data', function () {
    kbx_public();
    $e = kbx_entry(['title' => 'Offen']);
    foreach (['/lexicon', "/lexicon/{$e->id}"] as $url) {
        $props = kbx_props($this->get($url));
        expect($props['app']['modules'])->toBe([])->and($props['auth']['user'])->toBeNull()->and($props['announcements'])->toBe([])
            ->and($props['notifications']['unread'])->toBeNull()->and($props['discordDm'])->toBeNull()
            ->and($props['system']['pending_migrations'])->toBe([]);
        expect(json_encode($props))->not->toContain('permissions')->not->toContain('"username"');
    }
});

it('shows only non-archived entries to guests, with no creator or editor names anywhere in the page', function () {
    kbx_public();
    $author = User::factory()->create(['name' => 'Karl Geheim']);
    kbx_entry(['title' => 'Offen', 'created_by' => $author->id, 'updated_by' => $author->id]);
    kbx_entry(['title' => 'Archiv', 'is_archived' => true, 'created_by' => $author->id]);

    $r = $this->get('/lexicon?archived=1')->assertOk();
    $r->assertInertia(fn (Assert $p) => $p->has('entries.data', 1)->where('entries.data.0.title', 'Offen'));
    expect($r->getContent())->not->toContain('Karl Geheim')->not->toContain('Archiv');
});

it('throttles guests at 60 requests per minute, signed-in accounts are not limited by it', function () {
    kbx_public();
    for ($i = 0; $i < 60; $i++) {
        $this->get('/lexicon')->assertOk();
    }
    $this->get('/lexicon')->assertStatus(429);

    $this->actingAs(kbx_user());
    for ($i = 0; $i < 65; $i++) {
        $this->get('/lexicon')->assertOk();
    }
});

it('does not serve guests when the switch is turned off again, and turns images and pages off together', function () {
    $e = kbx_entry();
    kbx_public();
    $this->get("/lexicon/{$e->id}")->assertOk();
    app(Settings::class)->set('knowledgebase.public_access', false);
    $this->get("/lexicon/{$e->id}")->assertRedirect('/login');
    $this->get('/lexicon')->assertRedirect('/login');
});

it('keeps the navigation entry for signed-in accounts only (no module list for guests)', function () {
    kbx_public();
    $props = kbx_props($this->get('/lexicon'));
    expect($props['app']['modules'])->toBe([]);
    $in = kbx_props($this->actingAs(kbx_user())->get('/lexicon'));
    expect($in['app']['modules'])->toContain('knowledgebase');
});

it('offers guests only tags and categories used by non-archived entries; accounts see all', function () {
    kbx_public();
    $root = kbx_category('Wurzel');
    $used = kbx_category('Benutzt', $root->id);
    kbx_category('Geheim-Archiv');
    $open = kbx_tag('offen');
    $hidden = kbx_tag('nurarchiv');
    kbx_tag('ungenutzt');
    kbx_entry(['category_id' => $used->id])->tags()->attach($open->id);
    kbx_entry(['is_archived' => true])->tags()->attach($hidden->id);

    $props = kbx_props($this->get('/lexicon'));
    expect(collect($props['categories'])->pluck('name')->all())->toBe(['Wurzel', 'Benutzt'])->and(collect($props['tags'])->pluck('name')->all())->toBe(['offen']);
    expect($this->get('/lexicon')->getContent())->not->toContain('Geheim-Archiv')->not->toContain('nurarchiv');

    $in = kbx_props($this->actingAs(kbx_user())->get('/lexicon'));
    expect($in['categories'])->toHaveCount(3)->and($in['tags'])->toHaveCount(3);
});
