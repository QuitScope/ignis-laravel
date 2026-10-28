<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\LegacyDocument;
use App\Models\Personnel;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDefect;
use App\Search\SearchRegistry;
use App\Search\SearchSource;
use App\Search\VocabularyCache;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RoleSeeder::class));

function gs_user(string ...$perms): User
{
    $role = Role::create(['name' => 'S'.uniqid(), 'guard_name' => 'web', 'priority' => 500, 'color' => 'info']);
    $role->syncPermissions($perms);

    return User::factory()->withRole($role)->create();
}

/** @return array<string, mixed> */
function gs_search(User $user, string $q, string $scope = 'all'): array
{
    return test()->actingAs($user)->getJson('/api/system/global-search?'.http_build_query(['q' => $q, 'scope' => $scope]))->assertOk()->json();
}

/** @return array<string, list<string>> group key => item labels */
function gs_groups(array $body): array
{
    return collect($body['results'])->mapWithKeys(fn ($g) => [$g['key'] => array_column($g['items'], 'label')])->all();
}

it('answers guests with a JSON 401', function () {
    $this->getJson('/api/system/global-search?q=mu')->assertUnauthorized();
    $this->get('/api/system/global-search?q=mu')->assertUnauthorized();
});

it('answers in the legacy shape, private and uncached', function () {
    $p = Personnel::factory()->create(['full_name' => 'Sucht Estmann', 'service_number' => 'ST-01']);
    $res = $this->actingAs(gs_user('personnel.view'))->getJson('/api/system/global-search?q=estmann')->assertOk();

    expect($res->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($res->json())->toBe([
            'q' => 'estmann',
            'results' => [['key' => 'personnel', 'label' => 'Mitarbeiter', 'items' => [
                ['label' => 'Sucht Estmann', 'sub' => 'Dienstnr. ST-01', 'href' => "/personnel/{$p->id}"],
            ]]],
            'scopes' => [['key' => 'personnel', 'label' => 'Mitarbeiter'], ['key' => 'lexicon', 'label' => 'Lexikon']],
        ]);
});

it('returns no results but the scopes below two characters', function (string $q) {
    Personnel::factory()->create(['full_name' => 'K']);
    $body = gs_search(gs_user('personnel.view', 'vehicles.view'), $q);

    expect($body['results'])->toBe([])->and($body['q'])->toBe(trim($q))
        ->and(array_column($body['scopes'], 'key'))->toBe(['personnel', 'vehicles', 'defects', 'lexicon']);
})->with(['', 'K', '  K  ', ' ']);

it('treats array input as missing instead of failing', function () {
    $u = gs_user('personnel.view');
    Personnel::factory()->create(['full_name' => 'Array Probe']);
    $this->actingAs($u)->getJson('/api/system/global-search?q[]=Array')->assertOk()->assertJsonPath('results', []);
    $this->actingAs($u)->getJson('/api/system/global-search?q=Array&scope[]=x')->assertOk()
        ->assertJsonPath('results.0.items.0.label', 'Array Probe');
});

it('cuts the query at 100 characters', function () {
    $body = gs_search(gs_user('personnel.view'), str_repeat('a', 150));
    expect(mb_strlen($body['q']))->toBe(100);
});

it('matches LIKE wildcards literally', function () {
    Personnel::factory()->create(['full_name' => 'Hundert % Prozent']);
    Personnel::factory()->create(['full_name' => 'Unter_Strich']);
    Personnel::factory()->create(['full_name' => 'Ausruf!Zeichen']);
    Personnel::factory()->create(['full_name' => 'Ganz Anders']);
    $u = gs_user('personnel.view');

    expect(gs_groups(gs_search($u, '% P')))->toBe(['personnel' => ['Hundert % Prozent']])
        ->and(gs_groups(gs_search($u, 'r_S')))->toBe(['personnel' => ['Unter_Strich']])
        ->and(gs_groups(gs_search($u, 'f!Z')))->toBe(['personnel' => ['Ausruf!Zeichen']])
        ->and(gs_search($u, '%%')['results'])->toBe([])
        ->and(gs_search($u, '__')['results'])->toBe([]);
})->group('mariadb');

it('filters personnel by viewList and searches name and service number', function () {
    $p = Personnel::factory()->create(['full_name' => 'Rechte Testperson', 'service_number' => 'RT-77']);

    expect(gs_search(gs_user('vehicles.view'), 'testperson')['results'])->toBe([])
        ->and(gs_groups(gs_search(gs_user('personnel.view'), 'RT-77')))->toBe(['personnel' => ['Rechte Testperson']])
        ->and(gs_groups(gs_search(gs_user('admin'), 'testperson')))->toBe(['personnel' => ['Rechte Testperson']])
        ->and(gs_groups(gs_search(User::factory()->superAdmin()->create(), 'testperson')))->toBe(['personnel' => ['Rechte Testperson']]);
    unset($p);
})->group('mariadb');

it('filters vehicles and defects by vehicle.view (manage alone is not enough)', function () {
    $v = Vehicle::factory()->create(['name' => 'Florian Suchtest 1/83/1', 'identifier' => 'suchtest_rtw', 'license_plate' => null]);
    $d = VehicleDefect::factory()->create(['vehicle_id' => $v->id, 'title' => 'Blaulicht defekt', 'description' => 'Suchtest Lampe', 'status' => VehicleDefect::IN_PROGRESS]);

    expect(gs_search(gs_user('vehicles.manage'), 'suchtest')['results'])->toBe([]);

    $body = gs_search(gs_user('vehicles.view'), 'suchtest');
    expect(array_column($body['results'], 'key'))->toBe(['vehicles', 'defects'])
        ->and($body['results'][0]['items'][0])->toBe(['label' => 'Florian Suchtest 1/83/1', 'sub' => 'suchtest_rtw', 'href' => "/vehicles/{$v->id}"])
        ->and($body['results'][1]['items'][0]['label'])->toBe('Blaulicht defekt')
        ->and($body['results'][1]['items'][0]['href'])->toBe("/vehicles/defects?vehicle={$v->id}")
        ->and($body['results'][1]['items'][0]['sub'])->toStartWith('Florian Suchtest 1/83/1 · In Bearbeitung');

    $v->update(['license_plate' => 'B-RK 112']);
    expect(gs_search(gs_user('vehicles.view'), 'RK 112')['results'][0]['items'][0]['sub'])->toBe('suchtest_rtw · B-RK 112');
});

it('shows templates only with document.manage', function () {
    $t = DocumentTemplate::factory()->create(['name' => 'Abmahnung Vorlage', 'category' => 'Personal']);

    expect(gs_search(gs_user('personnel.documents.view'), 'Abmahnung')['results'])->toBe([]);
    $body = gs_search(gs_user('personnel.documents.manage'), 'Abmahnung');
    expect($body['results'][array_search('templates', array_column($body['results'], 'key'))]['items'][0])
        ->toBe(['label' => 'Abmahnung Vorlage', 'sub' => 'Personal', 'href' => "/settings/documents/editor-templates/{$t->id}"]);
});

describe('documents', function () {
    beforeEach(function () {
        $this->own = Personnel::factory()->create(['full_name' => 'Eigene Akte']);
        $this->other = Personnel::factory()->create(['full_name' => 'Fremde Akte']);
        $this->draft = Document::factory()->create(['personnel_id' => $this->own->id, 'title' => 'Doksuche Entwurf']);
        $this->issued = Document::factory()->issued()->create(['personnel_id' => $this->own->id, 'title' => 'Doksuche Eigen']);
        $this->foreign = Document::factory()->issued()->create(['personnel_id' => $this->other->id, 'title' => 'Doksuche Fremd']);
        $this->legacyOwn = LegacyDocument::factory()->create(['personnel_id' => $this->own->id, 'recipient_name' => 'Doksuche Alt Eigen', 'public_id' => 'AAAA-BBBB-CCCC']);
        $this->legacyForeign = LegacyDocument::factory()->create(['personnel_id' => $this->other->id, 'recipient_name' => 'Doksuche Alt Fremd']);
        $this->legacyArchived = LegacyDocument::factory()->create(['personnel_id' => $this->other->id, 'recipient_name' => 'Doksuche Alt Archiv', 'is_archived' => true]);
    });

    $labels = fn (array $body) => gs_groups($body)['documents'] ?? [];

    it('shows drafts only to document.manage and never archived legacy rows', function () use ($labels) {
        expect($labels(gs_search(gs_user('personnel.documents.manage'), 'Doksuche')))
            ->toEqualCanonicalizing(['Doksuche Entwurf', 'Doksuche Eigen', 'Doksuche Fremd', 'Doksuche Alt Eigen', 'Doksuche Alt Fremd'])
            ->and($labels(gs_search(gs_user('personnel.documents.view'), 'Doksuche')))
            ->toEqualCanonicalizing(['Doksuche Eigen', 'Doksuche Fremd', 'Doksuche Alt Eigen', 'Doksuche Alt Fremd']);
    });

    it('shows a recipient only their own issued and legacy documents', function () use ($labels) {
        $recipient = gs_user();
        $recipient->forceFill(['personnel_id' => $this->own->id])->save();

        $body = gs_search($recipient, 'Doksuche');
        expect($labels($body))->toEqualCanonicalizing(['Doksuche Eigen', 'Doksuche Alt Eigen'])
            ->and(array_column($body['scopes'], 'key'))->toBe(['documents', 'lexicon']);

        $hrefs = array_column($body['results'][0]['items'], 'href');
        expect($hrefs)->toContain("/documents/{$this->issued->id}")->toContain('/personnel/document-view?docid=AAAA-BBBB-CCCC');
    });

    it('shows the issuer their legacy documents', function () use ($labels) {
        $issuer = gs_user();
        $this->legacyForeign->update(['issuer_user_id' => $issuer->id]);

        expect($labels(gs_search($issuer, 'Doksuche')))->toBe(['Doksuche Alt Fremd']);
    });

    it('offers no documents to an account without rights or record', function () {
        $body = gs_search(gs_user(), 'Doksuche');
        expect($body['results'])->toBe([])->and(array_column($body['scopes'], 'key'))->toBe(['lexicon']);
    });

    it('searches public id and recipient name of editor documents', function () use ($labels) {
        $viewer = gs_user('personnel.documents.view');
        expect($labels(gs_search($viewer, $this->foreign->public_id)))->toBe(['Doksuche Fremd'])
            ->and($labels(gs_search($viewer, 'Fremde Akte')))->toContain('Doksuche Fremd');
    });

    it('never puts draft titles or archived recipients into the shared vocabulary', function () {
        Document::factory()->create(['personnel_id' => $this->own->id, 'title' => 'Geheimentwurf']);
        $source = collect(app(SearchRegistry::class)->sources())->first(fn (SearchSource $s) => $s->key() === 'documents');
        $words = array_keys(app(VocabularyCache::class)->words($source));

        expect($words)->not->toContain('geheimentwurf')->not->toContain('archiv')
            ->toContain('eigen')->toContain('fremd');
    });
});

it('finds a typo in a name through the vocabulary, flagged approx', function () {
    Personnel::factory()->create(['full_name' => 'Hans Müller', 'service_number' => 'FUZ-01']);
    Personnel::factory()->create(['full_name' => 'Petra Schmidt', 'service_number' => 'FUZ-02']);
    $u = gs_user('personnel.view');

    $mueller = gs_search($u, 'Mueller')['results'][0]['items'];
    $schmidt = gs_search($u, 'Schmit')['results'][0]['items'];

    expect($mueller)->toHaveCount(1)->and($mueller[0]['label'])->toBe('Hans Müller')->and($mueller[0]['approx'])->toBeTrue()
        ->and($schmidt[0]['label'])->toBe('Petra Schmidt')->and($schmidt[0]['approx'])->toBeTrue()
        ->and(gs_search($u, 'FUZ-03')['results'])->toBe([]);
});

it('keeps the rights when the fuzzy step runs', function () {
    Personnel::factory()->create(['full_name' => 'Hans Müller']);
    gs_search(gs_user('personnel.view'), 'Mueller');

    expect(gs_search(gs_user('vehicles.view'), 'Mueller')['results'])->toBe([]);
});

it('filters to one allowed scope and ignores unknown or forbidden ones', function () {
    Personnel::factory()->create(['full_name' => 'Florian Person']);
    Vehicle::factory()->create(['name' => 'Florian 1/1']);
    $u = gs_user('personnel.view', 'vehicles.view');

    expect(array_keys(gs_groups(gs_search($u, 'Florian'))))->toBe(['personnel', 'vehicles'])
        ->and(array_keys(gs_groups(gs_search($u, 'Florian', 'vehicles'))))->toBe(['vehicles'])
        ->and(array_keys(gs_groups(gs_search($u, 'Florian', 'nonsense'))))->toBe(['personnel', 'vehicles'])
        ->and(array_keys(gs_groups(gs_search($u, 'Florian', 'templates'))))->toBe(['personnel', 'vehicles']);
});

it('keeps five hits per group and the fixed group order', function () {
    foreach (range(1, 7) as $i) {
        Personnel::factory()->create(['full_name' => "Ordnung Person {$i}"]);
        Vehicle::factory()->create(['name' => "Ordnung Fahrzeug {$i}"]);
    }
    DocumentTemplate::factory()->create(['name' => 'Ordnung Vorlage']);
    $body = gs_search(User::factory()->superAdmin()->create(), 'Ordnung');

    expect(array_column($body['results'], 'key'))->toBe(['personnel', 'templates', 'vehicles'])
        ->and($body['results'][0]['items'])->toHaveCount(5)
        ->and(array_column($body['scopes'], 'key'))->toBe(['personnel', 'documents', 'templates', 'vehicles', 'defects', 'enotf-protocols', 'fire-incidents', 'lexicon']);
});

it('drops a failing source alone and logs it; a total failure has no exception text', function () {
    Personnel::factory()->create(['full_name' => 'Ausfall Person']);
    Log::spy();
    app(SearchRegistry::class)->register(new class implements SearchSource
    {
        public function key(): string
        {
            return 'broken';
        }

        public function label(): string
        {
            return 'Kaputt';
        }

        public function allowed(User $user): bool
        {
            return true;
        }

        public function search(User $user, string $q, int $limit): array
        {
            throw new RuntimeException('secret sql');
        }

        public function vocabulary(): ?array
        {
            return null;
        }

        public function fuzzyAllowed(User $user): bool
        {
            return true;
        }
    });

    expect(gs_groups(gs_search(gs_user('personnel.view'), 'Ausfall')))->toBe(['personnel' => ['Ausfall Person']]);
    Log::shouldHaveReceived('error')->once();

    $this->mock(SearchRegistry::class)->shouldReceive('search')->andThrow(new RuntimeException('secret sql'));
    $res = $this->actingAs(gs_user())->getJson('/api/system/global-search?q=Ausfall')->assertStatus(500);
    expect($res->getContent())->not->toContain('secret');
});

it('throttles the search in its own bucket at 60 per minute', function () {
    $u = gs_user();
    foreach (range(1, 60) as $_) {
        $this->actingAs($u)->getJson('/api/system/global-search?q=x')->assertOk();
    }
    $this->actingAs($u)->getJson('/api/system/global-search?q=x')->assertStatus(429);
    $this->actingAs($u)->getJson('/api/notifications/poll')->assertOk();
});

it('rewrites the legacy endpoint internally for every method', function () {
    Personnel::factory()->create(['full_name' => 'Altpfad Person']);
    $u = gs_user('personnel.view');

    foreach (['/assets/functions/system/global-search-api', '/assets/functions/system/global-search-api.php'] as $path) {
        $this->actingAs($u)->getJson($path.'?q=Altpfad')->assertOk()->assertJsonPath('results.0.items.0.label', 'Altpfad Person');
        $this->actingAs($u)->postJson($path.'?q=Altpfad')->assertStatus(405);
        $this->actingAs($u)->deleteJson($path.'?q=Altpfad')->assertStatus(405);
    }
    $this->actingAs($u)->postJson('/api/system/global-search?q=Altpfad')->assertStatus(405);
});

it('caches the vocabulary per source for ten minutes', function () {
    Personnel::factory()->create(['full_name' => 'Hans Müller']);
    $u = gs_user('personnel.view');
    gs_search($u, 'Mueller');
    Personnel::factory()->create(['full_name' => 'Olaf Schröder']);

    expect(gs_search($u, 'Schroeter')['results'])->toBe([]);
    Cache::flush();
    expect(gs_groups(gs_search($u, 'Schroeter')))->toBe(['personnel' => ['Olaf Schröder']]);
});

it('links every hit to a page the searcher can open as an Inertia visit', function () {
    $person = Personnel::factory()->create(['full_name' => 'Zielprobe Person']);
    $v = Vehicle::factory()->create(['name' => 'Zielprobe Fahrzeug']);
    VehicleDefect::factory()->create(['vehicle_id' => $v->id, 'title' => 'Zielprobe Mangel']);
    DocumentTemplate::factory()->create(['name' => 'Zielprobe Vorlage']);
    Document::factory()->issued()->create(['personnel_id' => $person->id, 'title' => 'Zielprobe Dokument']);
    LegacyDocument::factory()->create(['personnel_id' => $person->id, 'recipient_name' => 'Zielprobe Alt', 'public_id' => 'ZZZZ-BBBB-CCCC']);
    $user = gs_user('personnel.view', 'vehicles.view', 'personnel.documents.manage');
    $version = (string) app(HandleInertiaRequests::class)->version(request());

    $body = gs_search($user, 'Zielprobe');
    expect(array_column($body['results'], 'key'))->toBe(['personnel', 'documents', 'templates', 'vehicles', 'defects'])
        ->and(count($body['results'][1]['items']))->toBe(2);
    foreach ($body['results'] as $group) {
        foreach ($group['items'] as $item) {
            $res = $this->actingAs($user)->get($item['href'], ['X-Inertia' => 'true', 'X-Inertia-Version' => $version]);
            expect($res->status())->toBe(200, $item['href'])
                ->and($res->headers->get('X-Inertia'))->toBe('true', $item['href'])
                ->and($res->json('component'))->toBeString();
        }
    }
});

it('gives a recipient-only account no fuzzy document hits, a reader still gets them', function () {
    $own = Personnel::factory()->create(['full_name' => 'Empfänger Person']);
    $other = Personnel::factory()->create(['full_name' => 'Andere Person']);
    Document::factory()->issued()->create(['personnel_id' => $other->id, 'title' => 'Abmahnung Kaltenbach']);
    Document::factory()->issued()->create(['personnel_id' => $own->id, 'title' => 'Bescheinigung Kaltenbachweg']);
    $recipient = gs_user();
    $recipient->forceFill(['personnel_id' => $own->id])->save();

    expect(gs_search($recipient, 'Kaltenbacg')['results'])->toBe([])
        ->and(gs_groups(gs_search($recipient, 'Kaltenbachweg')))->toBe(['documents' => ['Bescheinigung Kaltenbachweg']]);

    $items = gs_search(gs_user('personnel.documents.view'), 'Kaltenbacg')['results'][0]['items'];
    expect(array_column($items, 'label'))->toContain('Abmahnung Kaltenbach')->and($items[0]['approx'])->toBeTrue();
});
