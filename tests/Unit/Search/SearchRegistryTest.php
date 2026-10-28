<?php

use App\Models\User;
use App\Search\SearchRegistry;
use App\Search\SearchSource;
use App\Search\VocabularyCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

/** In-memory source: case-insensitive substring over $rows, records every query it gets. */
class FakeSource implements SearchSource
{
    /** @var list<string> */
    public array $queries = [];

    /** @param list<string> $rows */
    public function __construct(
        public string $key,
        public array $rows,
        public bool $fuzzy = true,
        public ?Closure $allowed = null,
        public ?array $vocabulary = null,
        public bool $fuzzyAllowed = true,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return ucfirst($this->key);
    }

    public function allowed(User $user): bool
    {
        return $this->allowed === null || ($this->allowed)($user);
    }

    public function search(User $user, string $q, int $limit): array
    {
        $this->queries[] = $q;
        $hits = array_values(array_filter($this->rows, fn (string $r) => mb_stripos($r, $q) !== false));

        return array_map(fn (string $r) => ['label' => $r, 'sub' => '', 'href' => '/'.$this->key.'/'.md5($r)], array_slice($hits, 0, $limit));
    }

    public function vocabulary(): ?array
    {
        return $this->fuzzy ? ($this->vocabulary ?? $this->rows) : null;
    }

    public function fuzzyAllowed(User $user): bool
    {
        return $this->fuzzyAllowed;
    }
}

function sr_registry(SearchSource ...$sources): SearchRegistry
{
    $registry = new SearchRegistry(new VocabularyCache);
    foreach ($sources as $source) {
        $registry->register($source);
    }

    return $registry;
}

beforeEach(fn () => Cache::flush());

it('finds Müller for mueller and flags the hit approx', function () {
    $source = new FakeSource('people', ['Hans Müller', 'Petra Schmidt']);
    $result = sr_registry($source)->search(new User, 'mueller');

    expect($result['results'])->toBe([['key' => 'people', 'label' => 'People', 'items' => [
        ['label' => 'Hans Müller', 'sub' => '', 'href' => '/people/'.md5('Hans Müller'), 'approx' => true],
    ]]]);
});

it('runs no fuzzy step when the exact search fills the limit or the source has no vocabulary', function () {
    $full = new FakeSource('full', array_map(fn ($i) => "Meier {$i}", range(1, 6)));
    $plain = new FakeSource('plain', ['Hans Müller'], fuzzy: false);
    $result = sr_registry($full, $plain)->search(new User, 'Meier');

    expect($full->queries)->toBe(['Meier'])->and($result['results'][0]['items'])->toHaveCount(5);
    $plain->queries = [];
    sr_registry($plain)->search(new User, 'mueller');
    expect($plain->queries)->toBe(['mueller']);
});

it('appends approximate hits after the exact ones without duplicates', function () {
    $source = new FakeSource('people', ['Meier Hans', 'Maier Hans', 'Meyer Hans']);
    $items = sr_registry($source)->search(new User, 'Meier')['results'][0]['items'];

    expect(array_column($items, 'label'))->toBe(['Meier Hans', 'Maier Hans', 'Meyer Hans'])
        ->and(array_map(fn ($i) => $i['approx'] ?? false, $items))->toBe([false, true, true])
        ->and(count(array_unique(array_column($items, 'href'))))->toBe(3);
});

it('caps candidates: three vocabulary hits per token, two tokens replaced, three extra queries', function () {
    $vocabulary = ['baum', 'bauk', 'baul', 'baux', 'haus', 'hauk', 'maus', 'mauk', 'raus', 'rauk'];
    $source = new FakeSource('things', [], vocabulary: $vocabulary);
    sr_registry($source)->search(new User, 'bauz hauz mauz rauz');

    $extra = array_slice($source->queries, 1);
    expect(count($extra))->toBeLessThanOrEqual(3)->toBeGreaterThan(0);
    foreach ($extra as $q) {
        $words = explode(' ', $q);
        expect($words[2])->toBe('mauz')->and($words[3])->toBe('rauz')
            ->and(in_array($words[0], ['baum', 'bauk', 'baul'], true))->toBeTrue();
    }
});

it('needs four characters for an edit, but an exact fold always counts', function () {
    $source = new FakeSource('things', [], vocabulary: ['Abc', 'Öl', 'Xyz']);
    sr_registry($source)->search(new User, 'abd oel');

    expect(array_slice($source->queries, 1))->toBe(['abd Öl']);
});

it('never searches a source the user may not see, also not through the fuzzy step', function () {
    $hidden = new FakeSource('hidden', ['Hans Müller'], allowed: fn () => false);
    $result = sr_registry($hidden)->search(new User, 'mueller');

    expect($result)->toBe(['q' => 'mueller', 'results' => [], 'scopes' => []])->and($hidden->queries)->toBe([]);
});

it('isolates a throwing source and a throwing vocabulary', function () {
    Log::spy();
    $broken = new class extends FakeSource
    {
        public function __construct()
        {
            parent::__construct('broken', []);
        }

        public function search(User $user, string $q, int $limit): array
        {
            throw new RuntimeException('boom');
        }
    };
    $badVocabulary = new class extends FakeSource
    {
        public function __construct()
        {
            parent::__construct('badvocab', ['Hans Mueller x']);
        }

        public function vocabulary(): ?array
        {
            throw new RuntimeException('boom');
        }
    };
    $good = new FakeSource('good', ['Hans Müller']);

    $result = sr_registry($broken, $badVocabulary, $good)->search(new User, 'Mueller');

    expect(array_column($result['results'], 'key'))->toBe(['badvocab', 'good'])
        ->and($result['results'][0]['items'][0]['label'])->toBe('Hans Mueller x');
    Log::shouldHaveReceived('error')->twice();
});

it('validates the scope, trims and cuts the query', function () {
    $a = new FakeSource('a', ['Alpha one']);
    $b = new FakeSource('b', ['Alpha two']);
    $registry = sr_registry($a, $b);

    expect(array_column($registry->search(new User, 'alpha', 'b')['results'], 'key'))->toBe(['b'])
        ->and(array_column($registry->search(new User, 'alpha', 'zzz')['results'], 'key'))->toBe(['a', 'b'])
        ->and($registry->search(new User, '  al  ')['q'])->toBe('al')
        ->and($registry->search(new User, 'a')['results'])->toBe([])
        ->and(mb_strlen($registry->search(new User, str_repeat('ä', 120))['q']))->toBe(100);
});

it('bounds the vocabulary and keeps only words', function () {
    $phrases = array_map(fn ($i) => "wort{$i} zusatz{$i}", range(1, 4000));
    $words = (new VocabularyCache)->words(new FakeSource('big', [], vocabulary: $phrases));

    expect(count($words))->toBe(VocabularyCache::MAX_WORDS)
        ->and($words['wort1'])->toBe(['wort1']);
});

it('runs no fuzzy step for a user the source does not allow it for', function () {
    $source = new FakeSource('people', ['Hans Müller'], fuzzyAllowed: false);

    expect(sr_registry($source)->search(new User, 'mueller')['results'])->toBe([])->and($source->queries)->toBe(['mueller']);
});

it('drops a source whose allowed() throws, alone', function () {
    Log::spy();
    $broken = new class extends FakeSource
    {
        public function __construct()
        {
            parent::__construct('broken', ['Alpha']);
        }

        public function allowed(User $user): bool
        {
            throw new RuntimeException('boom');
        }
    };
    $result = sr_registry($broken, new FakeSource('good', ['Alpha']))->search(new User, 'Alpha');

    expect(array_column($result['results'], 'key'))->toBe(['good'])->and(array_column($result['scopes'], 'key'))->toBe(['good']);
    Log::shouldHaveReceived('error')->once();
});

it('ignores a second source with the same key and logs it', function () {
    Log::spy();
    $result = sr_registry(new FakeSource('a', ['Alpha one']), new FakeSource('a', ['Alpha two']))->search(new User, 'Alpha');

    expect($result['results'][0]['items'][0]['label'])->toBe('Alpha one')->and($result['scopes'])->toHaveCount(1);
    Log::shouldHaveReceived('warning')->once();
});
