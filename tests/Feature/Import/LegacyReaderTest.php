<?php

use App\Import\LegacyReader;
use Illuminate\Support\Facades\DB;

function sqliteReader(): LegacyReader
{
    $c = DB::connectUsing('t_legacy_reader', ['driver' => 'sqlite', 'database' => ':memory:'], true);
    $c->statement('create table things (id integer primary key, name text)');
    $c->table('things')->insert(['name' => 'x']);
    $reader = new LegacyReader($c);

    return $reader;
}

it('reads but exposes no write methods', function () {
    $r = sqliteReader();

    expect($r->table('things')->count())->toBe(1)
        ->and($r->select('select name from things')[0]->name)->toBe('x')
        ->and(iterator_to_array($r->cursor('select * from things')))->toHaveCount(1)
        ->and($r->tableExists('things'))->toBeTrue()
        ->and($r->tableExists('nope'))->toBeFalse();

    foreach (['insert', 'update', 'delete', 'statement', 'unprepared', 'transaction', 'getPdo', 'connection'] as $method) {
        expect(method_exists($r, $method))->toBeFalse($method);
    }
});

it('refuses writes even through the query builder', function () {
    sqliteReader()->table('things')->insert(['name' => 'y']);
})->throws(LogicException::class, 'read-only');

it('refuses raw write statements', function () {
    sqliteReader()->select('delete from things');
})->throws(LogicException::class, 'read-only');

it('refuses locking reads, dumps and CTE writes', function (string $sql) {
    sqliteReader()->select($sql);
})->with([
    'for update' => 'select * from things for update',
    'lock in share mode' => 'select * from things LOCK IN SHARE MODE',
    'outfile' => "select * from things into outfile '/tmp/x'",
    'dumpfile' => "select * from things INTO DUMPFILE '/tmp/x'",
    'with' => 'with t as (select 1) delete from things',
])->throws(LogicException::class, 'read-only');
