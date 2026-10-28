<?php

use App\Update\Manifest;
use App\Update\ReleaseSource;
use App\Update\UpdateException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\UpdateFixtures;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->dir = UpdateFixtures::setUp('1.0.0');
    $this->target = $this->dir.'/download.zip';
});

function rs_reason(Closure $fn): string
{
    try {
        $fn();
    } catch (UpdateException $e) {
        return $e->reason;
    }

    return 'no exception';
}

function rs_verified(): array
{
    $source = new ReleaseSource;

    return [$source, $source->fetchVerifiedManifest($source->latest())];
}

it('reads the latest release and a verified manifest', function () {
    UpdateFixtures::fake();
    [, $manifest] = rs_verified();

    $release = (new ReleaseSource)->latest();
    expect($release->tag)->toBe('v2.0.0')
        ->and($release->version)->toBe('2.0.0')
        ->and($release->htmlUrl)->toBe('https://github.com/ignis-test/ignis/releases/tag/v2.0.0')
        ->and($release->manifestUrl)->toBe(UpdateFixtures::assetUrl('manifest.json'))
        ->and($release->signatureUrl)->toBe(UpdateFixtures::assetUrl('manifest.json.sig'))
        ->and($manifest->version)->toBe('2.0.0');
    Http::assertSent(fn (Request $r) => $r->url() === UpdateFixtures::API && $r->hasHeader('Accept', 'application/vnd.github+json'));
});

it('returns null when the repository has no release', function () {
    Http::fake([UpdateFixtures::API => Http::response(['message' => 'Not Found'], 404)]);

    expect((new ReleaseSource)->latest())->toBeNull();
});

it('refuses to ask without a valid repository', function (string $repo) {
    config(['ignis.release_repo' => $repo]);
    Http::fake();

    expect(rs_reason(fn () => (new ReleaseSource)->latest()))->toBe('repo_missing');
    Http::assertNothingSent();
})->with(['', 'owner', 'owner/name/extra', '../x/y', 'own er/name']);

it('follows redirects only to allowed https hosts', function () {
    $cdn = 'https://objects.githubusercontent.com/github-production-release-asset/1?sig=x';
    $json = UpdateFixtures::manifest();
    UpdateFixtures::fake(extra: [
        UpdateFixtures::assetUrl('manifest.json') => Http::response('', 302, ['Location' => $cdn]),
        UpdateFixtures::assetUrl('manifest.json.sig') => Http::response(UpdateFixtures::sign($json)),
        $cdn => Http::response($json),
    ]);

    expect(rs_verified()[1]->version)->toBe('2.0.0');
});

it('release source refuses redirect to other host and non-https', function (string $location) {
    UpdateFixtures::fake(extra: [UpdateFixtures::assetUrl('manifest.json') => Http::response('', 302, ['Location' => $location])]);

    expect(rs_reason(fn () => rs_verified()))->toBe('host');
})->with([
    'other host' => 'https://evil.example/manifest.json',
    'look-alike host' => 'https://github.com.evil.example/x',
    'plain http' => 'http://objects.githubusercontent.com/x',
    'credentials' => 'https://user:pw@github.com/x',
    'other port' => 'https://github.com:8443/x',
    'relative' => '/somewhere/else',
]);

it('stops after three redirects', function () {
    $hop = fn (int $i) => "https://github.com/hop/$i";
    UpdateFixtures::fake(extra: [
        UpdateFixtures::assetUrl('manifest.json') => Http::response('', 302, ['Location' => $hop(1)]),
        $hop(1) => Http::response('', 302, ['Location' => $hop(2)]),
        $hop(2) => Http::response('', 302, ['Location' => $hop(3)]),
        $hop(3) => Http::response('', 302, ['Location' => $hop(4)]),
        $hop(4) => Http::response(UpdateFixtures::manifest()),
    ]);

    expect(rs_reason(fn () => rs_verified()))->toBe('redirects');
});

it('refuses an oversized manifest', function () {
    UpdateFixtures::fake(extra: [UpdateFixtures::assetUrl('manifest.json') => Http::response(str_repeat('x', 65 * 1024))]);

    expect(rs_reason(fn () => rs_verified()))->toBe('too_large');
});

it('source rejects downgrade/equal version/old manifest/min_version not met/prerelease/draft', function (Closure $arrange, string $reason) {
    $arrange();

    expect(rs_reason(fn () => rs_verified()))->toBe($reason);
})->with([
    'downgrade' => [function () {
        UpdateFixtures::appDir('3.0.0');
        UpdateFixtures::fake();
    }, 'not_newer'],
    'equal version' => [function () {
        UpdateFixtures::appDir('2.0.0');
        UpdateFixtures::fake();
    }, 'not_newer'],
    'rc of the installed version' => [function () {
        UpdateFixtures::appDir('2.0.0');
        UpdateFixtures::fake(['version' => '2.0.0-rc.1'], ['tag_name' => 'v2.0.0-rc.1']);
    }, 'not_stable'],
    'pre-release version on a release marked stable' => [fn () => UpdateFixtures::fake(['version' => '2.1.0-rc.1'], ['tag_name' => 'v2.1.0-rc.1']), 'not_stable'],
    'old manifest' => [fn () => UpdateFixtures::fake(['created_at' => now()->subDays(181)->utc()->format('Y-m-d\TH:i:s\Z')]), 'manifest_age'],
    'manifest from the future' => [fn () => UpdateFixtures::fake(['created_at' => now()->addDays(2)->utc()->format('Y-m-d\TH:i:s\Z')]), 'manifest_age'],
    'min_version not met' => [fn () => UpdateFixtures::fake(['min_version' => '1.5.0']), 'min_version'],
    'prerelease' => [fn () => UpdateFixtures::fake(release: ['prerelease' => true]), 'not_stable'],
    'draft' => [fn () => UpdateFixtures::fake(release: ['draft' => true]), 'not_stable'],
    'dev install' => [function () {
        UpdateFixtures::appDir('dev');
        UpdateFixtures::fake();
    }, 'dev_version'],
    'bad signature' => [fn () => UpdateFixtures::fake(signature: UpdateFixtures::sign('something else')), 'signature'],
    'missing signature' => [fn () => UpdateFixtures::fake(signature: ''), 'signature'],
    'placeholder key' => [function () {
        config(['ignis.update.public_key' => 'PLACEHOLDER']);
        UpdateFixtures::fake();
    }, 'key_missing'],
    'manifest version differs from tag' => [fn () => UpdateFixtures::fake(['version' => '2.0.1']), 'version_mismatch'],
    'archive not in the release' => [fn () => UpdateFixtures::fake(['archive' => 'other.zip']), 'archive_missing'],
    'signature asset missing' => [fn () => UpdateFixtures::fake(release: ['assets' => [['name' => 'manifest.json', 'browser_download_url' => UpdateFixtures::assetUrl('manifest.json')]]]), 'assets_missing'],
    'tag not a version' => [fn () => UpdateFixtures::fake(release: ['tag_name' => 'latest']), 'bad_release'],
    'asset on a foreign host' => [fn () => UpdateFixtures::fake(release: ['assets' => [
        ['name' => 'manifest.json', 'browser_download_url' => 'https://evil.example/manifest.json'],
        ['name' => 'manifest.json.sig', 'browser_download_url' => UpdateFixtures::assetUrl('manifest.json.sig')],
    ]]), 'host'],
]);

it('reports an unreachable or failing server', function (Closure $answer, string $reason) {
    Http::fake([UpdateFixtures::API => $answer]);

    expect(rs_reason(fn () => (new ReleaseSource)->latest()))->toBe($reason);
})->with([
    'server error' => [fn () => Http::response('', 503), 'http'],
    'not json' => [fn () => Http::response('<html>'), 'bad_release'],
    'unreachable' => [fn () => throw new ConnectionException('cURL error 28'), 'unreachable'],
]);

it('downloads the archive and checks its hash', function () {
    UpdateFixtures::fake(archive: UpdateFixtures::zip());
    [$source, $manifest] = rs_verified();

    $source->downloadArchive($manifest, $this->target);

    expect(hash_file('sha256', $this->target))->toBe($manifest->sha256);
});

it('download aborts on sha mismatch and size limit and removes the file', function (Closure $arrange, string $reason) {
    $arrange();
    [$source, $manifest] = rs_verified();

    expect(rs_reason(fn () => $source->downloadArchive($manifest, $this->target)))->toBe($reason)
        ->and(file_exists($this->target))->toBeFalse();
})->with([
    'sha mismatch' => [fn () => UpdateFixtures::fake(extra: [UpdateFixtures::assetUrl('ignis-2.0.0.zip') => Http::response('tampered')]), 'sha256'],
    'size limit' => [function () {
        config(['ignis.update.archive_max_bytes' => 4]);
        UpdateFixtures::fake();
    }, 'too_large'],
    'size limit by header' => [function () {
        config(['ignis.update.archive_max_bytes' => 100]);
        UpdateFixtures::fake(extra: [UpdateFixtures::assetUrl('ignis-2.0.0.zip') => Http::response('zip-bytes', 200, ['Content-Length' => '999999'])]);
    }, 'too_large'],
    'redirect to other host' => [fn () => UpdateFixtures::fake(extra: [UpdateFixtures::assetUrl('ignis-2.0.0.zip') => Http::response('', 302, ['Location' => 'https://evil.example/a.zip'])]), 'host'],
]);

it('asks for nothing but the release without a signing key', function () {
    config(['ignis.update.public_key' => 'PLACEHOLDER']);
    UpdateFixtures::fake();
    $source = new ReleaseSource;
    $release = $source->latest();

    expect(rs_reason(fn () => $source->fetchVerifiedManifest($release)))->toBe('key_missing');
    Http::assertSentCount(1);
});

it('follows a redirect to release-assets.githubusercontent.com', function () {
    $cdn = 'https://release-assets.githubusercontent.com/github-production-release-asset/1/2?sp=r&sig=x';
    UpdateFixtures::fake(archive: 'zip-bytes', extra: [
        UpdateFixtures::assetUrl('ignis-2.0.0.zip') => Http::response('', 302, ['Location' => $cdn]),
        $cdn => Http::response('zip-bytes'),
    ]);
    [$source, $manifest] = rs_verified();

    $source->downloadArchive($manifest, $this->target);

    expect(file_get_contents($this->target))->toBe('zip-bytes');
});

it('keeps an existing target when the download fails and leaves no temporary file', function () {
    file_put_contents($this->target, 'previous');
    UpdateFixtures::fake(extra: [UpdateFixtures::assetUrl('ignis-2.0.0.zip') => Http::response('tampered')]);
    [$source, $manifest] = rs_verified();

    expect(rs_reason(fn () => $source->downloadArchive($manifest, $this->target)))->toBe('sha256')
        ->and(file_get_contents($this->target))->toBe('previous')
        ->and(glob($this->target.'.part-*'))->toBe([]);
});

it('downloads only for the very manifest object it verified', function () {
    UpdateFixtures::fake();
    [$source, $manifest] = rs_verified();
    $copy = new Manifest($manifest->version, $manifest->archive, $manifest->sha256, $manifest->minVersion, $manifest->createdAt);

    expect(rs_reason(fn () => $source->downloadArchive($copy, $this->target)))->toBe('archive_missing');
});

it('downloads only archives of a manifest it verified', function () {
    UpdateFixtures::fake();
    [, $manifest] = rs_verified();

    expect(rs_reason(fn () => (new ReleaseSource)->downloadArchive($manifest, $this->target)))->toBe('archive_missing');
});
