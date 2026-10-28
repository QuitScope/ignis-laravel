<?php

use App\Update\UpdateLock;
use App\Update\UpdateState;

uses()->group('slow');

function lockFile(): string
{
    $f = sys_get_temp_dir().'/ignis-lock-'.bin2hex(random_bytes(4)).'.lock';
    register_shutdown_function(fn () => array_map('unlink', glob($f.'*') ?: []));

    return $f;
}

function spawn(string $code, string ...$args): array
{
    $p = proc_open([PHP_BINARY, '-r', $code, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    return [$p, $pipes[1]];
}

function holderCode(bool $grandchild): string
{
    $auto = var_export(base_path('vendor/autoload.php'), true);
    $gc = $grandchild
        ? '$p=proc_open([PHP_BINARY,"-r","sleep(25);"],[],$x); $pid=proc_get_status($p)["pid"];'
        : '$pid=0;';

    return 'require '.$auto.'; $l=new App\Update\UpdateLock($argv[1]); if(!$l->acquire()){exit(3);} '.$gc.' echo "ready $pid\n"; sleep(25);';
}

function killPid(int $pid): void
{
    if ($pid > 0) {
        PHP_OS_FAMILY === 'Windows' ? exec('taskkill /F /PID '.$pid.' 2>NUL') : exec('kill -9 '.$pid.' 2>/dev/null');
    }
}

function skipWithoutProcOpen(): void
{
    if (! function_exists('proc_open')) {
        test()->markTestSkipped('proc_open unavailable');
    }
}

function fakeStat(int $pid, string $start, string $state = 'S'): string
{
    return $pid.' (php) '.$state.' '.implode(' ', [...array_fill(0, 18, '0'), $start]).' 0 0';
}

it('second lock acquire fails while first holds it; works after release', function () {
    $f = lockFile();
    $a = new UpdateLock($f);
    $b = new UpdateLock($f);

    expect($a->acquire())->toBeTrue()->and($a->acquire())->toBeTrue()->and($b->acquire())->toBeFalse();
    $a->release();
    expect($b->acquire())->toBeTrue();
    $b->release();
});

it('release by a non-owner does nothing', function () {
    $f = lockFile();
    $a = new UpdateLock($f);
    $a->acquire();
    (new UpdateLock($f))->release();

    expect($a->ownerAlive())->toBeTrue()->and((new UpdateLock($f))->acquire())->toBeFalse();
    $a->release();
    expect((new UpdateLock($f))->ownerAlive())->toBeFalse()->and(is_file($f.'.pid'))->toBeFalse();
});

it('lock is free after the holder process dies', function () {
    skipWithoutProcOpen();
    $f = lockFile();
    [$p, $out] = spawn(holderCode(false), $f);
    expect(trim((string) fgets($out)))->toStartWith('ready');
    expect((new UpdateLock($f))->acquire())->toBeFalse();
    proc_terminate($p, 9);
    proc_close($p);

    $lock = new UpdateLock($f);
    expect($lock->acquire())->toBeTrue();
    $lock->release();
});

it('exactly one of 8 concurrent acquirers wins, in every round', function () {
    skipWithoutProcOpen();
    $auto = var_export(base_path('vendor/autoload.php'), true);
    $code = 'require '.$auto.'; while(microtime(true)<(float)$argv[2]) usleep(200); $l=new App\Update\UpdateLock($argv[1]); $w=$l->acquire(); echo $w?"won\n":"lost\n"; if($w){sleep(20);}';

    foreach (range(1, 3) as $round) {
        $f = lockFile();
        $at = (string) (microtime(true) + 1.5);
        $procs = array_map(fn () => spawn($code, $f, $at), range(1, 8));
        $results = array_map(fn ($c) => trim((string) fgets($c[1])), $procs);
        foreach ($procs as [$p]) {
            proc_terminate($p, 9);
            proc_close($p);
        }
        expect(array_count_values($results))->toEqualCanonicalizing(['won' => 1, 'lost' => 7]);
    }
});

it('a killed holder leaves the update stale', function () {
    skipWithoutProcOpen();
    $f = lockFile();
    [$p, $out] = spawn(holderCode(false), $f);
    expect(trim((string) fgets($out)))->toStartWith('ready');

    $state = new UpdateState(sys_get_temp_dir().'/ignis-stale-'.bin2hex(random_bytes(4)).'.json');
    register_shutdown_function(fn () => @unlink($state->path()));
    $state->begin('1', '2');
    $state->step('migrating');

    expect($state->isStale(new UpdateLock($f)))->toBeFalse();
    proc_terminate($p, 9);
    proc_close($p);
    expect($state->isStale(new UpdateLock($f)))->toBeTrue();
    $state->fail('migrating', 'x', false);
    expect($state->isStale(new UpdateLock($f)))->toBeFalse();
});

it('a surviving grandchild neither hides the crash nor lets a third process in after takeover', function () {
    skipWithoutProcOpen();
    $f = lockFile();
    [$p, $out] = spawn(holderCode(true), $f);
    $line = trim((string) fgets($out));
    expect($line)->toStartWith('ready');
    $gcPid = (int) substr($line, 6);

    $state = new UpdateState(sys_get_temp_dir().'/ignis-stale-'.bin2hex(random_bytes(4)).'.json');
    register_shutdown_function(fn () => @unlink($state->path()));
    $state->begin('1', '2');

    $b = new UpdateLock($f);
    expect($b->acquire())->toBeFalse()->and($state->isStale($b))->toBeFalse();

    try {
        proc_terminate($p, 9);
        proc_close($p);
        expect($state->isStale($b))->toBeTrue()->and($b->acquire())->toBeTrue()->and($state->isStale($b))->toBeFalse();

        killPid($gcPid);
        usleep(300_000);
        expect((new UpdateLock($f))->acquire())->toBeFalse()->and($b->ownerAlive())->toBeTrue();
        $b->release();
    } finally {
        killPid($gcPid);
    }
});

it('decides liveness from the start time when /proc is readable and from the pid when it is not', function () {
    $f = lockFile();
    $me = getmypid();

    foreach ([['99', 'S', false], ['100', 'S', true], ['99', 'Z', true]] as [$start, $state, $free]) {
        is_file($f.'.pid') && unlink($f.'.pid');
        file_put_contents($f.'.pid', "{$me}|99|abcd");
        $l = new UpdateLock($f);
        $l->procStat = fn (int $pid) => fakeStat($pid, $start, $state);
        expect($l->acquire())->toBe($free);
        $l->release();
    }

    is_file($f.'.pid') && unlink($f.'.pid');
    file_put_contents($f.'.pid', "{$me}|99|abcd");
    $l = new UpdateLock($f);
    $l->procStat = fn () => false;
    expect($l->acquire())->toBeFalse();

    skipWithoutProcOpen();
    [$p] = spawn('exit(0);');
    $dead = proc_get_status($p)['pid'];
    proc_close($p);
    file_put_contents($f.'.pid', "{$dead}|99|abcd");
    expect($l->acquire())->toBeTrue();
    $l->release();
});

it('tells why acquire failed and a forced acquire takes over', function () {
    $f = lockFile();
    $a = new UpdateLock($f);
    $b = new UpdateLock($f);
    $a->acquire();

    expect($b->acquire())->toBeFalse()->and($b->lastFailure())->toBe('busy')
        ->and($b->acquire(force: true))->toBeTrue()->and($b->lastFailure())->toBeNull()
        ->and($a->ownerAlive())->toBeTrue();
    $b->release();

    $blocked = sys_get_temp_dir().'/ignis-lock-file-'.bin2hex(random_bytes(4));
    file_put_contents($blocked, 'a file where the lock directory should be');
    $c = new UpdateLock("$blocked/update.lock");
    expect($c->acquire())->toBeFalse()->and($c->lastFailure())->toBe('io');
    unlink($blocked);
});

it('guard file is readable and writable for other users', function () {
    $f = lockFile();
    (new UpdateLock($f))->acquire();

    expect(fileperms("$f.guard") & 0666)->toBe(0666);
})->skip(PHP_OS_FAMILY === 'Windows', 'no POSIX permissions on Windows');
