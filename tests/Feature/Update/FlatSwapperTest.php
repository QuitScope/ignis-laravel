<?php

use App\Update\FlatSwapper;
use App\Update\UpdateException;
use Illuminate\Filesystem\Filesystem;

/**
 * A temporary flat installation (version 1.0.0 with .env, storage, .git, SQLite inside database/, public/storage) and a
 * staged release 2.0.0. Never the project directory.
 *
 * @return array{app: string, stage: string, db: string}
 */
function fsSetUp(): array
{
    $root = sys_get_temp_dir().'/ignis-fs-'.bin2hex(random_bytes(4));
    register_shutdown_function(fn () => (new Filesystem)->deleteDirectory($root));
    $app = "$root/app";
    $stage = "$root/stage";
    foreach (['app/Models', 'bootstrap/cache', 'config', 'database/migrations', 'public/build', 'public/storage', 'resources', 'routes', 'vendor/pkg', 'storage/app/public', '.git', 'docs'] as $d) {
        mkdir("$app/$d", 0777, true);
    }
    file_put_contents("$app/app/Models/User.php", '<?php // old');
    file_put_contents("$app/bootstrap/cache/config.php", '<?php // old cache');
    file_put_contents("$app/config/app.php", '<?php // old config');
    file_put_contents("$app/database/migrations/0001.php", 'old migration');
    file_put_contents("$app/public/index.php", 'old index');
    file_put_contents("$app/public/storage/linked-upload.png", 'upload via public/storage');
    file_put_contents("$app/vendor/pkg/a.php", random_bytes(2000));
    file_put_contents("$app/docs/old.md", 'old docs');
    file_put_contents("$app/artisan", 'old artisan');
    file_put_contents("$app/composer.json", '{"old":true}');
    file_put_contents("$app/VERSION", "1.0.0\n");
    file_put_contents("$app/.env", "APP_KEY=base64:abc\n");
    file_put_contents("$app/storage/app/public/upload.png", 'upload');
    file_put_contents("$app/.git/HEAD", 'ref');
    file_put_contents("$app/database/database.sqlite", random_bytes(4096));
    file_put_contents("$app/database/database.sqlite-wal", random_bytes(100));

    foreach (['app/Models', 'app/Http', 'bootstrap/cache', 'config', 'database/migrations', 'public/build', 'resources', 'routes', 'vendor/pkg', 'lang'] as $d) {
        mkdir("$stage/$d", 0777, true);
    }
    file_put_contents("$stage/app/Models/User.php", '<?php // new');
    file_put_contents("$stage/app/Http/New.php", '<?php // added');
    file_put_contents("$stage/database/migrations/0002.php", 'new migration');
    file_put_contents("$stage/public/build/app.js", 'new js');
    file_put_contents("$stage/vendor/pkg/a.php", 'new vendor');
    file_put_contents("$stage/lang/en.json", '{}');
    file_put_contents("$stage/artisan", 'new artisan');
    file_put_contents("$stage/VERSION", "2.0.0\n");

    $db = "$app/database/database.sqlite";

    return ['app' => $app, 'stage' => $stage, 'db' => $db];
}

function fsSwapper(string $app, string $db): FlatSwapper
{
    return new FlatSwapper($app, ["$app/public/storage", $db, "$db-wal", "$db-shm"]);
}

/** @return array<string, string> relative path => sha256|dir, without .update-old */
function fsTree(string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
        if (str_starts_with($rel, '.update-old')) {
            continue;
        }
        $out[$rel] = $f->isDir() ? 'dir' : hash_file('sha256', $f->getPathname());
    }
    ksort($out);

    return $out;
}

it('keeps .env storage .git sqlite untouched', function () {
    ['app' => $app, 'stage' => $stage, 'db' => $db] = fsSetUp();
    $before = fsTree($app);

    fsSwapper($app, $db)->swap($stage);

    $after = fsTree($app);
    foreach (['.env', 'storage/app/public/upload.png', '.git/HEAD', 'database/database.sqlite', 'database/database.sqlite-wal', 'public/storage/linked-upload.png'] as $kept) {
        expect($after[$kept] ?? null)->toBe($before[$kept], $kept);
    }
    expect(file_get_contents("$app/app/Models/User.php"))->toBe('<?php // new')
        ->and(file_get_contents("$app/database/migrations/0002.php"))->toBe('new migration')
        ->and(file_exists("$app/database/migrations/0001.php"))->toBeFalse()
        ->and(file_exists("$app/bootstrap/cache/config.php"))->toBeFalse()
        ->and(file_exists("$app/docs"))->toBeFalse()
        ->and(trim((string) file_get_contents("$app/VERSION")))->toBe('2.0.0')
        ->and(file_get_contents("$app/.update-old/1.0.0/app/Models/User.php"))->toBe('<?php // old');
});

it('revert restores old tree bit-identical', function () {
    ['app' => $app, 'stage' => $stage, 'db' => $db] = fsSetUp();
    $before = fsTree($app);
    $swapper = fsSwapper($app, $db);
    $swapper->swap($stage);
    file_put_contents($db, 'migrated by the new version');

    $swapper->revert();

    $after = fsTree($app);
    unset($before['database/database.sqlite'], $after['database/database.sqlite']);
    expect($after)->toBe($before)
        ->and(file_get_contents($db))->toBe('migrated by the new version')
        ->and(glob("$app/.update-old/*"))->toBe([]);
    $swapper->revert();
    expect(fsTree($app))->toHaveCount(count($before) + 1);
});

it('a fresh process reverts a swap that crashed at any point', function () {
    foreach ([0, 1, 5, 11, 12, 13, 18, 22, 23, 24, 25, 30] as $crashAfter) {
        ['app' => $app, 'stage' => $stage, 'db' => $db] = fsSetUp();
        $before = fsTree($app);
        $swapper = fsSwapper($app, $db);
        $moves = 0;
        $swapper->rename = function (string $a, string $b) use (&$moves, $crashAfter): bool {
            if ($moves++ === $crashAfter) {
                throw new LogicException('process killed');
            }

            return @rename($a, $b);
        };
        try {
            $swapper->swap($stage);
        } catch (LogicException) {
        }

        $fresh = fsSwapper($app, $db);
        expect($fresh->pending())->toHaveCount(1);
        $fresh->revert();

        expect(fsTree($app))->toBe($before, "crash after $crashAfter moves")->and($fresh->pending())->toBe([]);
    }
});

it('retries locked file then fails cleanly', function () {
    ['app' => $app, 'stage' => $stage, 'db' => $db] = fsSetUp();
    $before = fsTree($app);
    $swapper = fsSwapper($app, $db);
    $tries = 0;
    $swapper->rename = function (string $a, string $b) use (&$tries): bool {
        if (str_ends_with(str_replace('\\', '/', $a), '/app/vendor')) {
            $tries++;

            return false;
        }

        return @rename($a, $b);
    };

    expect(fn () => $swapper->swap($stage))->toThrow(fn (UpdateException $e) => expect($e->reason)->toBe('swap_failed'));
    expect($tries)->toBe(5);
    $swapper->revert();
    expect(fsTree($app))->toBe($before);
});

it('a locked file that frees up within the retries does not fail the swap', function () {
    ['app' => $app, 'stage' => $stage, 'db' => $db] = fsSetUp();
    $swapper = fsSwapper($app, $db);
    $tries = 0;
    $swapper->rename = function (string $a, string $b) use (&$tries): bool {
        if (str_ends_with(str_replace('\\', '/', $a), '/app/config') && ++$tries < 3) {
            return false;
        }

        return @rename($a, $b);
    };

    $swapper->swap($stage);

    expect($tries)->toBe(3)->and(trim((string) file_get_contents("$app/VERSION")))->toBe('2.0.0');
});

it('commit makes the swap final and removes the old code', function () {
    ['app' => $app, 'stage' => $stage, 'db' => $db] = fsSetUp();
    $swapper = fsSwapper($app, $db);
    $swapper->swap($stage);
    $swapper->commit();

    expect($swapper->pending())->toBe([])->and(is_dir("$app/.update-old/1.0.0"))->toBeFalse();
    (new FlatSwapper($app, []))->revert();
    expect(trim((string) file_get_contents("$app/VERSION")))->toBe('2.0.0');
});

it('carries a public/storage symlink over and back', function () {
    ['app' => $app, 'stage' => $stage, 'db' => $db] = fsSetUp();
    (new Filesystem)->deleteDirectory("$app/public/storage");
    if (! @symlink("$app/storage/app/public", "$app/public/storage")) {
        $this->markTestSkipped('symlinks not permitted here');
    }
    $swapper = fsSwapper($app, $db);
    $swapper->swap($stage);
    expect(is_link("$app/public/storage"))->toBeTrue()->and(file_get_contents("$app/public/storage/upload.png"))->toBe('upload');
    $swapper->revert();
    expect(is_link("$app/public/storage"))->toBeTrue()->and(file_get_contents("$app/storage/app/public/upload.png"))->toBe('upload');
});

it('refuses a new swap while an earlier one is unfinished', function () {
    ['app' => $app, 'stage' => $stage, 'db' => $db] = fsSetUp();
    mkdir("$app/.update-old/0.9.0", 0777, true);
    file_put_contents("$app/.update-old/0.9.0/swap.json", '{"items":{},"keep":[]}');
    $before = fsTree($app);

    expect(fn () => fsSwapper($app, $db)->swap($stage))->toThrow(fn (UpdateException $e) => expect($e->reason)->toBe('swap_failed'));
    expect(fsTree($app))->toBe($before);
});

it('carries installation files in public/ over by default; the installation copy wins over the release', function () {
    ['app' => $app, 'stage' => $stage] = fsSetUp();
    mkdir("$app/public/.well-known");
    file_put_contents("$app/public/.well-known/security.txt", 'contact');
    file_put_contents("$app/public/.user.ini", 'memory_limit=1G');
    file_put_contents("$app/public/robots.txt", 'own robots');
    file_put_contents("$stage/public/robots.txt", 'release robots');

    $swapper = new FlatSwapper($app);
    $swapper->swap($stage);

    expect(file_get_contents("$app/public/.well-known/security.txt"))->toBe('contact')
        ->and(file_get_contents("$app/public/.user.ini"))->toBe('memory_limit=1G')
        ->and(file_get_contents("$app/public/robots.txt"))->toBe('release robots')
        ->and(file_get_contents("$app/public/storage/linked-upload.png"))->toBe('upload via public/storage')
        ->and(file_get_contents("$app/public/build/app.js"))->toBe('new js');
    $swapper->revert();
    expect(file_get_contents("$app/public/robots.txt"))->toBe('own robots')->and(file_exists("$app/public/build/app.js"))->toBeFalse();
});

it('swaps modules/ in when the installation has none yet and takes it out again on revert', function () {
    ['app' => $app, 'stage' => $stage, 'db' => $db] = fsSetUp();
    mkdir("$stage/modules/Fleet", 0777, true);
    file_put_contents("$stage/modules/Fleet/module.json", '{"name":"fleet"}');
    $before = fsTree($app);
    $swapper = fsSwapper($app, $db);

    $swapper->swap($stage);
    expect(file_get_contents("$app/modules/Fleet/module.json"))->toBe('{"name":"fleet"}');

    $swapper->revert();
    expect(file_exists("$app/modules"))->toBeFalse()->and(fsTree($app))->toBe($before);
});

it('replaces an existing modules/ like any other code directory', function () {
    ['app' => $app, 'stage' => $stage, 'db' => $db] = fsSetUp();
    mkdir("$app/modules/Old", 0777, true);
    file_put_contents("$app/modules/Old/a.php", 'old module');
    mkdir("$stage/modules/New", 0777, true);
    file_put_contents("$stage/modules/New/a.php", 'new module');
    $swapper = fsSwapper($app, $db);

    $swapper->swap($stage);
    expect(file_exists("$app/modules/Old"))->toBeFalse()->and(file_get_contents("$app/modules/New/a.php"))->toBe('new module');
    $swapper->revert();
    expect(file_get_contents("$app/modules/Old/a.php"))->toBe('old module')->and(file_exists("$app/modules/New"))->toBeFalse();
});
