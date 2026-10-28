<?php

use App\Update\SymlinkSwapper;
use App\Update\UpdateException;
use Illuminate\Filesystem\Filesystem;

/**
 * Release layout in a temp dir: releases/1.0.0 with .env and storage linked into shared/, current -> releases/1.0.0,
 * and a staged release 2.0.0.
 *
 * @return array{root: string, current: string, stage: string}
 */
function ssSetUp(): array
{
    $root = sys_get_temp_dir().'/ignis-ss-'.bin2hex(random_bytes(4));
    register_shutdown_function(fn () => (new Filesystem)->deleteDirectory($root));
    mkdir("$root/releases/1.0.0/app", 0777, true);
    mkdir("$root/shared/storage/app", 0777, true);
    mkdir("$root/stage/app", 0777, true);
    file_put_contents("$root/shared/.env", 'APP_KEY=x');
    file_put_contents("$root/shared/storage/app/upload.png", 'upload');
    file_put_contents("$root/releases/1.0.0/VERSION", "1.0.0\n");
    file_put_contents("$root/releases/1.0.0/app/User.php", 'old');
    file_put_contents("$root/stage/VERSION", "2.0.0\n");
    file_put_contents("$root/stage/app/User.php", 'new');
    if (! @symlink('../../shared/.env', "$root/releases/1.0.0/.env") || ! @symlink('../../shared/storage', "$root/releases/1.0.0/storage")
        || ! @symlink('releases/1.0.0', "$root/current")) {
        test()->markTestSkipped('symlinks not permitted here');
    }

    return ['root' => $root, 'current' => "$root/current", 'stage' => "$root/stage"];
}

it('switches current to the new release with the shared links', function () {
    ['root' => $root, 'current' => $current, 'stage' => $stage] = ssSetUp();

    (new SymlinkSwapper($current))->swap($stage);

    expect(readlink($current))->toBe("$root/releases/2.0.0")
        ->and(file_get_contents("$current/app/User.php"))->toBe('new')
        ->and(file_get_contents("$current/.env"))->toBe('APP_KEY=x')
        ->and(file_get_contents("$current/storage/app/upload.png"))->toBe('upload')
        ->and(file_get_contents("$root/releases/1.0.0/app/User.php"))->toBe('old');
});

it('revert points current back, also from a fresh process, and commit makes it final', function () {
    ['root' => $root, 'current' => $current, 'stage' => $stage] = ssSetUp();
    (new SymlinkSwapper($current))->swap($stage);

    $fresh = new SymlinkSwapper($current);
    expect($fresh->pending())->toHaveCount(1);
    $fresh->revert();

    expect(readlink($current))->toBe('releases/1.0.0')->and(is_dir("$root/releases/2.0.0"))->toBeFalse()
        ->and(file_get_contents("$current/app/User.php"))->toBe('old')->and($fresh->pending())->toBe([]);

    mkdir("$root/stage2/app", 0777, true);
    file_put_contents("$root/stage2/VERSION", "2.0.0\n");
    $s = new SymlinkSwapper($current);
    $s->swap("$root/stage2");
    $s->commit();
    (new SymlinkSwapper($current))->revert();
    expect(readlink($current))->toBe("$root/releases/2.0.0");
});

it('refuses a release whose .env is not a shared link', function () {
    ['root' => $root, 'current' => $current, 'stage' => $stage] = ssSetUp();
    unlink("$root/releases/1.0.0/.env");
    file_put_contents("$root/releases/1.0.0/.env", 'local');

    expect(fn () => (new SymlinkSwapper($current))->swap($stage))->toThrow(UpdateException::class);
    expect(readlink($current))->toBe('releases/1.0.0')->and(is_dir("$root/releases/2.0.0"))->toBeFalse();
});
