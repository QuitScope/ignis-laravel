<?php

use App\Update\Installation;
use App\Update\InstalledVersion;
use Tests\Support\UpdateFixtures;
use Tests\TestCase;

uses(TestCase::class);

function iv_env(?string $value): void
{
    if ($value === null) {
        unset($_ENV['IGNIS_VERSION'], $_SERVER['IGNIS_VERSION']);
    } else {
        $_ENV['IGNIS_VERSION'] = $_SERVER['IGNIS_VERSION'] = $value;
    }
}

afterEach(fn () => iv_env(null));

it('installed version prefers VERSION file', function () {
    iv_env('9.9.9');
    UpdateFixtures::appDir(" 1.2.3 \n");

    expect(InstalledVersion::current())->toBe('1.2.3');
});

it('falls back to IGNIS_VERSION, then dev', function () {
    $dir = UpdateFixtures::appDir(null);
    iv_env('1.4.0');
    expect(InstalledVersion::current())->toBe('1.4.0');

    iv_env(null);
    config(['ignis.version' => '1.3.0']);
    expect(InstalledVersion::current())->toBe('1.3.0');

    config(['ignis.version' => null]);
    expect(InstalledVersion::current())->toBe('dev');

    file_put_contents("$dir/VERSION", "  \n");
    expect(InstalledVersion::current())->toBe('dev');
});

it('compares semantic versions', function () {
    foreach ([
        ['1.0.0', '1.0.1', -1],
        ['1.10.0', '1.9.0', 1],
        ['2.0.0', '2.0.0', 0],
        ['1.0.0-rc.1', '1.0.0', -1],
        ['1.0.0-rc.2', '1.0.0-rc.10', -1],
        ['1.0.0-alpha', '1.0.0-alpha.1', -1],
        ['1.0.0-alpha.1', '1.0.0-alpha.beta', -1],
        ['1.0.0-beta', '1.0.0-rc.1', -1],
        ['1.0.0+build.1', '1.0.0', 0],
    ] as [$a, $b, $expected]) {
        expect(InstalledVersion::compare($a, $b))->toBe($expected)
            ->and(InstalledVersion::compare($b, $a))->toBe(-$expected);
    }
});

it('knows valid versions', function () {
    expect(InstalledVersion::isValid('1.0.0-rc.1'))->toBeTrue()
        ->and(InstalledVersion::isValid('1.0'))->toBeFalse()
        ->and(InstalledVersion::isValid('v1.0.0'))->toBeFalse()
        ->and(InstalledVersion::isValid('01.0.0'))->toBeFalse()
        ->and(InstalledVersion::isValid('dev'))->toBeFalse()
        ->and(InstalledVersion::isValid("1.0.0\n"))->toBeFalse()
        ->and(InstalledVersion::isStable('1.0.0+build.5'))->toBeTrue()
        ->and(InstalledVersion::isStable('1.0.0-rc.1'))->toBeFalse()
        ->and(InstalledVersion::isStable("1.0.0\n"))->toBeFalse();
});

it('installation blocks git checkout and dev', function () {
    $dir = UpdateFixtures::setUp('1.0.0');
    expect(Installation::selfUpdateBlocker())->toBeNull();

    mkdir("$dir/.git");
    expect(Installation::selfUpdateBlocker())->toBe('git_checkout');
    rmdir("$dir/.git");

    file_put_contents("$dir/.git", 'gitdir: /elsewhere/.git/worktrees/x');
    expect(Installation::selfUpdateBlocker())->toBe('git_checkout');
    unlink("$dir/.git");

    file_put_contents("$dir/VERSION", 'dev');
    expect(Installation::selfUpdateBlocker())->toBe('dev_version');
});

it('installation blocks a missing repository or key', function () {
    UpdateFixtures::setUp('1.0.0');

    config(['ignis.release_repo' => '']);
    expect(Installation::selfUpdateBlocker())->toBe('repo_missing');
    config(['ignis.release_repo' => 'not a repo']);
    expect(Installation::selfUpdateBlocker())->toBe('repo_missing');
    config(['ignis.release_repo' => UpdateFixtures::REPO."\n"]);
    expect(Installation::selfUpdateBlocker())->toBe('repo_missing');

    config(['ignis.release_repo' => UpdateFixtures::REPO, 'ignis.update.public_key' => 'PLACEHOLDER']);
    expect(Installation::selfUpdateBlocker())->toBe('key_missing');
});

it('detects the driver', function () {
    config(['ignis.update.driver' => 'docker']);
    expect(Installation::driver())->toBe('docker');

    config(['ignis.update.driver' => null]);
    expect(Installation::driver())->toBe(file_exists('/.dockerenv') ? 'docker' : 'files');
});
