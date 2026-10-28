<?php

use App\Search\SearchRegistry;
use App\Support\Settings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/kbHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Cache::flush();
});

/** @return array<string, mixed> the lexicon group of the response, or [] */
function kbs_group(mixed $user, string $q): array
{
    $body = test()->actingAs($user)->getJson('/api/system/global-search?'.http_build_query(['q' => $q]))->assertOk()->json();

    return collect($body['results'])->firstWhere('key', 'lexicon') ?? [];
}

it('finds entries by title, subtitle, content and tag for a plain account', function (string $q, string $title) {
    $tag = kbx_tag('Reanimation');
    $e = kbx_entry(['title' => 'Adrenalin', 'subtitle' => 'Katecholamin', 'search_text' => 'Gabe bei Herzstillstand 1 mg i.v.']);
    $e->tags()->attach($tag->id);
    kbx_entry(['title' => 'Unrelated']);

    $group = kbs_group(kbx_user(), $q);

    expect(array_column($group['items'], 'label'))->toBe([$title])
        ->and($group['items'][0]['href'])->toBe("/lexicon/{$e->id}");
})->with([
    'title' => ['adrena', 'Adrenalin'],
    'subtitle' => ['katechol', 'Adrenalin'],
    'content' => ['herzstillstand', 'Adrenalin'],
    'tag' => ['reanimat', 'Adrenalin'],
]);

it('shows type and category as sub line and a snippet around the content match', function () {
    $cat = kbx_category('Notfall');
    kbx_entry(['type' => 'medication', 'title' => 'Adrenalin', 'category_id' => $cat->id, 'search_text' => str_repeat('a ', 100).'Herzstillstand'.str_repeat(' b', 100)]);

    $item = kbs_group(kbx_user(), 'herzstillstand')['items'][0];

    expect($item['sub'])->toBe('Medikament · Notfall')
        ->and($item['snippet'])->toContain('Herzstillstand')->toStartWith('…')->toEndWith('…')
        ->and(mb_strlen($item['snippet']))->toBeLessThan(140);
});

it('never lists archived entries, not even for admin', function () {
    kbx_entry(['title' => 'Alt Archiv', 'is_archived' => true]);
    kbx_entry(['title' => 'Alt Aktiv']);

    $admin = kbx_user('admin', 'kb.archive');

    expect(array_column(kbs_group($admin, 'alt')['items'], 'label'))->toBe(['Alt Aktiv']);
});

it('is not available to guests', function () {
    kbx_entry(['title' => 'Offen']);
    kbx_public();

    $this->getJson('/api/system/global-search?q=offen')->assertUnauthorized();
});

it('corrects typos from the titles of non-archived entries only', function () {
    kbx_entry(['title' => 'Defibrillation']);
    kbx_entry(['title' => 'Geheimarchiv', 'is_archived' => true]);
    $u = kbx_user();

    $items = kbs_group($u, 'defibrilation')['items'];
    expect($items[0]['label'])->toBe('Defibrillation')->and($items[0]['approx'])->toBeTrue()
        ->and(kbs_group($u, 'geheimarchiv'))->toBe([]);
});

it('returns nothing while the module is off', function () {
    kbx_entry(['title' => 'Adrenalin']);
    app(Settings::class)->set('modules.knowledgebase.enabled', false);

    $body = $this->actingAs(kbx_user())->getJson('/api/system/global-search?q=adrenalin')->assertOk()->json();

    expect($body['results'])->toBe([])->and(array_column($body['scopes'], 'key'))->not->toContain('lexicon');
});

it('limits the hits per group', function () {
    foreach (range(1, 8) as $i) {
        kbx_entry(['title' => "Sepsis $i"]);
    }

    expect(kbs_group(kbx_user(), 'sepsis')['items'])->toHaveCount(SearchRegistry::LIMIT);
});

it('takes wildcards in the query literally', function () {
    kbx_entry(['title' => 'Sauerstoff']);

    expect(kbs_group(kbx_user(), '%%'))->toBe([]);
});
