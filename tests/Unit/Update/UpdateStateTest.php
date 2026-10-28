<?php

use App\System\Redact;
use App\Update\UpdateLock;
use App\Update\UpdateLog;
use App\Update\UpdateState;
use Tests\TestCase;

uses(TestCase::class);

function stateIn(): UpdateState
{
    $dir = sys_get_temp_dir().'/ignis-state-'.bin2hex(random_bytes(4));
    register_shutdown_function(function () use ($dir) {
        array_map('unlink', glob($dir.'/*') ?: []);
        @rmdir($dir);
    });

    return new UpdateState($dir.'/state.json');
}

it('missing file is idle', function () {
    expect(stateIn()->read()['status'])->toBe('idle')->and(stateIn()->isRunning())->toBeFalse();
});

it('state transitions are atomic and survive a second read', function () {
    $s = stateIn();
    $s->begin('1.0.0', '1.1.0');
    $s->step('downloading');
    $s->step('backing_up');

    $again = new UpdateState($s->path());
    $r = $again->read();
    expect($r['status'])->toBe('running')->and($r['step'])->toBe('backing_up')->and($r['from'])->toBe('1.0.0')->and($r['to'])->toBe('1.1.0')
        ->and(array_column($r['steps'], 'name'))->toBe(['downloading', 'backing_up'])->and($again->isRunning())->toBeTrue();

    $s->done();
    expect($again->read()['status'])->toBe('done')->and($again->read()['finished_at'])->not->toBeNull();
    expect(glob(dirname($s->path()).'/*.tmp'))->toBe([]);
});

it('fail redacts secrets (APP_KEY, DB password, bearer)', function () {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'database.connections.mysql.password' => 'hunter2secret']);
    $s = stateIn();
    $s->begin('1.0.0', '1.1.0');
    $s->fail('migrating', 'boom '.config('app.key').' pw hunter2secret Bearer abc.def password=xyz', true);

    $r = $s->read();
    expect($r['status'])->toBe('failed')->and($r['rolled_back'])->toBeTrue()->and($r['step'])->toBe('migrating')
        ->and($r['error'])->toBe('boom [redacted] pw [redacted] Bearer [redacted] password=[redacted]')
        ->and($r['steps'][0]['ok'])->toBeFalse();
});

it('falls back to copy when rename keeps failing (Windows)', function () {
    $s = stateIn();
    $s->begin('1', '2');
    $calls = 0;
    $s->rename = function () use (&$calls) {
        $calls++;

        return false;
    };
    $s->step('checking');

    expect($calls)->toBe(10)->and((new UpdateState($s->path()))->read()['step'])->toBe('checking')->and(glob(dirname($s->path()).'/*.tmp'))->toBe([]);
});

it('redacts app key, db passwords, bearer and password assignments', function () {
    config(['app.key' => 'base64:QUJDREVGR0g=', 'database.connections.mysql.password' => 'dbpw-1', 'database.connections.pgsql.password' => '']);
    expect(Redact::text('k=base64:QUJDREVGR0g= p=QUJDREVGR0g= x dbpw-1'))->toBe('k=[redacted] p=[redacted] x [redacted]')
        ->and(Redact::text('Authorization: Bearer abc123'))->toBe('Authorization: Bearer [redacted]')
        ->and(Redact::text('DB_PASSWORD=s3cr3t PWD="a b" password = x'))->toBe('DB_PASSWORD=[redacted] PWD=[redacted] password = [redacted]')
        ->and(Redact::text('plain text'))->toBe('plain text');
});

it('log lines are redacted and appended', function () {
    $f = sys_get_temp_dir().'/ignis-log-'.bin2hex(random_bytes(4)).'.log';
    $l = new UpdateLog($f);
    $l->write('hello Bearer tok');
    $l->write('second');
    $c = file_get_contents($l->path());
    @unlink($f);
    expect($c)->toContain('hello Bearer [redacted]')->and($c)->toContain('second')->and($c)->not->toContain('tok');
});

it('begin starts from a clean slate after a failed or a done run', function () {
    $s = stateIn();
    $s->begin('1.0.0', '1.1.0');
    $s->step('checking');
    $s->fail('migrating', 'bad', true);
    $s->begin('1.1.0', '1.2.0');
    $r = $s->read();
    expect($r['status'])->toBe('running')->and($r['steps'])->toBe([])->and($r['step'])->toBeNull()->and($r['error'])->toBeNull()
        ->and($r['finished_at'])->toBeNull()->and($r['rolled_back'])->toBeFalse()->and($r['from'])->toBe('1.1.0');

    $s->step('checking');
    $s->done();
    $s->begin('1.2.0', '1.3.0');
    expect($s->read()['steps'])->toBe([])->and($s->read()['finished_at'])->toBeNull();
});

it('an unreadable state file is unknown, not idle, and is kept as .corrupt', function () {
    $s = stateIn();
    $s->begin('1', '2');
    file_put_contents($s->path(), '{garbage');

    $r = $s->read();
    expect($r['status'])->toBe('unknown')->and($r['error'])->toBe('state file unreadable')->and($s->isRunning())->toBeFalse();

    $s->begin('2', '3');
    expect($s->read()['status'])->toBe('running')->and(file_get_contents($s->path().'.corrupt'))->toBe('{garbage');
    @unlink($s->path().'.corrupt');
});

it('allows exactly the documented transitions', function () {
    $s = stateIn();
    $s->begin('1', '2');
    $s->step('checking');
    $s->done();
    expect($s->read()['status'])->toBe('done')->and($s->isRunning())->toBeFalse();

    $s->begin('1', '2');
    $s->fail('verifying', 'nope');
    expect($s->read()['status'])->toBe('failed')->and($s->read()['rolled_back'])->toBeFalse();

    $s->begin('1', '2');
    $s->step('migrating');
    $s->beginRollback('migrating', 'boom');
    expect($s->read()['status'])->toBe('rolling_back')->and($s->isRunning())->toBeTrue()->and($s->read()['step'])->toBe('migrating')
        ->and($s->read()['error'])->toBe('boom')->and(array_column($s->read()['steps'], 'ok'))->toBe([true, false]);
    $s->step('rolling_back');
    $s->finishRollback(true);
    $r = $s->read();
    expect($r['status'])->toBe('rolled_back')->and($r['rolled_back'])->toBeTrue()->and($r['error'])->toBe('boom')->and($r['finished_at'])->not->toBeNull()
        ->and(count($r['steps']))->toBe(3)->and($s->isRunning())->toBeFalse();

    $s->begin('1', '2');
    $s->beginRollback('switching', 'a');
    $s->finishRollback(false, 'rollback broke');
    expect($s->read()['status'])->toBe('failed_rollback')->and($s->read()['rolled_back'])->toBeFalse()->and($s->read()['error'])->toBe('rollback broke');
});

it('illegal transitions throw LogicException', function () {
    $s = stateIn();
    $illegal = [
        'step on idle' => fn () => $s->step('checking'),
        'done on idle' => fn () => $s->done(),
        'fail on idle' => fn () => $s->fail('checking', 'x'),
        'beginRollback on idle' => fn () => $s->beginRollback('checking', 'x'),
        'finishRollback on idle' => fn () => $s->finishRollback(true),
    ];
    foreach ($illegal as $fn) {
        expect($fn)->toThrow(LogicException::class);
    }

    $s->begin('1', '2');
    expect(fn () => $s->finishRollback(true))->toThrow(LogicException::class);
    $s->beginRollback('migrating', 'x');
    foreach ([fn () => $s->done(), fn () => $s->fail('migrating', 'x'), fn () => $s->beginRollback('migrating', 'x')] as $fn) {
        expect($fn)->toThrow(LogicException::class);
    }
    $s->finishRollback(true);
    foreach ([fn () => $s->step('checking'), fn () => $s->done(), fn () => $s->finishRollback(false)] as $fn) {
        expect($fn)->toThrow(LogicException::class);
    }
    expect(fn () => $s->step('nonsense'))->toThrow(InvalidArgumentException::class);
    expect($s->read()['status'])->toBe('rolled_back');
});

it('beginRollback and finishRollback redact the error', function () {
    config(['database.connections.mysql.password' => 'hunter2secret']);
    $s = stateIn();
    $s->begin('1', '2');
    $s->beginRollback('migrating', 'x hunter2secret Bearer tok');
    expect($s->read()['error'])->toBe('x [redacted] Bearer [redacted]');
    $s->finishRollback(false, 'y password=hunter2secret');
    expect($s->read()['error'])->toBe('y password=[redacted]');
});

it('a stale rolling_back run is detected too', function () {
    $s = stateIn();
    $s->begin('1', '2');
    $s->beginRollback('migrating', 'x');
    $lock = new UpdateLock(sys_get_temp_dir().'/ignis-rb-'.bin2hex(random_bytes(4)).'.lock');
    expect($s->isStale($lock))->toBeTrue();
    $s->finishRollback(true);
    expect($s->isStale($lock))->toBeFalse();
});
it('redacts json password keys and ignores very short secrets', function () {
    config(['database.connections.mysql.password' => 'ab']);
    expect(Redact::text('{"password":"x y","pwd": "q\"z","secret":"s","other":"ab"}'))
        ->toBe('{"password":"[redacted]","pwd": "[redacted]","secret":"[redacted]","other":"ab"}');
});

it('log write never throws', function () {
    (new UpdateLog(sys_get_temp_dir().'/ignis-no-such-dir-'.bin2hex(random_bytes(4)).'/\0bad/x.log'))->write('x');
    expect(true)->toBeTrue();
});
