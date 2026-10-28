<?php

use App\Update\ProcessUpdateStarter;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\PhpExecutableFinder;

uses()->group('slow');

it('spawns a detached process and returns before it finishes', function () {
    $dir = sys_get_temp_dir().'/ignis-launch-'.bin2hex(random_bytes(4));
    mkdir($dir);
    register_shutdown_function(fn () => (new Filesystem)->deleteDirectory($dir));
    file_put_contents("$dir/job.php", '<?php sleep(3); file_put_contents($argv[1], getcwd());');

    $start = microtime(true);
    (new ProcessUpdateStarter)->spawn([(string) (new PhpExecutableFinder)->find(false), "$dir/job.php", "$dir/done.txt"], $dir);
    $took = microtime(true) - $start;

    expect(file_exists("$dir/done.txt"))->toBeFalse();
    for ($i = 0; $i < 60 && ! file_exists("$dir/done.txt"); $i++) {
        usleep(250_000);
    }
    expect($took)->toBeLessThan(3.0)
        ->and(realpath((string) @file_get_contents("$dir/done.txt")))->toBe(realpath($dir));
});

it('refuses quotes in arguments', function () {
    expect(fn () => (new ProcessUpdateStarter)->spawn(['php', "x' ; evil"], sys_get_temp_dir()))->toThrow(RuntimeException::class);
});
