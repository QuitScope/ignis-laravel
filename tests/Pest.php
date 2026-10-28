<?php

use App\Install\InstallState;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Storage::fake() appends this token to the disk root, so concurrent test processes (two terminals)
// no longer share and wipe storage/framework/testing/disks/<disk>. Paratest sets its own token, which wins.
$_SERVER['TEST_TOKEN'] ??= 'p'.getmypid();
if (str_starts_with($_SERVER['TEST_TOKEN'], 'p')) {
    // The per-process roots are git-ignored; remove this process's leftovers at exit.
    register_shutdown_function(fn () => array_map(
        fn (string $dir) => (new Filesystem)->deleteDirectory($dir),
        glob(__DIR__.'/../storage/framework/testing/disks/*_test_'.$_SERVER['TEST_TOKEN']) ?: [],
    ));
}

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // No test may reach Discord, a peer, GitHub or a tile server: an unfaked outgoing request throws.
        Http::preventStrayRequests();

        // EnsureInstalled would redirect every web request to /install. Feature tests run
        // as an installed app, except Install*Test.php, which tests the real install state.
        if (! str_starts_with(class_basename($this), 'Install')) {
            $this->mock(InstallState::class)->shouldReceive('isInstalled')->andReturnTrue();
        }
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/
