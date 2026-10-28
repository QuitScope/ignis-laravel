<?php

use App\Import\ImportContext;
use App\Import\Importer;
use App\Import\ImportPlan;

/** @param list<string> $deps */
function fakeImporter(string $entity, array $deps = []): Importer
{
    return new class($entity, $deps) implements Importer
    {
        /** @param list<string> $d */
        public function __construct(private string $e, private array $d) {}

        public function entity(): string
        {
            return $this->e;
        }

        public function dependsOn(): array
        {
            return $this->d;
        }

        public function requiredTables(): array
        {
            return [];
        }

        public function import(ImportContext $ctx): void {}
    };
}

/**
 * @param  list<Importer>  $importers
 * @return list<string>
 */
function entities(array $importers): array
{
    return array_map(fn (Importer $i) => $i->entity(), $importers);
}

it('orders by dependencies', function () {
    $plan = ImportPlan::resolve([fakeImporter('c', ['b']), fakeImporter('b', ['a']), fakeImporter('a')]);

    expect(entities($plan))->toBe(['a', 'b', 'c']);
});

it('--only adds the dependencies', function () {
    $all = [fakeImporter('a'), fakeImporter('b', ['a']), fakeImporter('c'), fakeImporter('d', ['b'])];

    expect(entities(ImportPlan::resolve($all, ['d'])))->toBe(['a', 'b', 'd'])
        ->and(entities(ImportPlan::resolve($all, ['c'])))->toBe(['c']);
});

it('--only runs in the order of the full plan, whatever order the entities were typed in', function () {
    $all = [fakeImporter('user'), fakeImporter('vehicle'), fakeImporter('notification', ['user'])];

    expect(entities(ImportPlan::resolve($all, ['notification', 'vehicle'])))->toBe(['user', 'vehicle', 'notification'])
        ->and(entities(ImportPlan::resolve($all, ['vehicle', 'notification'])))->toBe(['user', 'vehicle', 'notification']);
});

it('rejects an unknown dependency', function () {
    ImportPlan::resolve([fakeImporter('b', ['nope'])]);
})->throws(InvalidArgumentException::class, 'unknown entity nope');

it('rejects a cycle', function () {
    ImportPlan::resolve([fakeImporter('a', ['b']), fakeImporter('b', ['a'])]);
})->throws(InvalidArgumentException::class, 'cycle');

it('rejects an unknown --only entity and lists the known ones', function () {
    ImportPlan::resolve([fakeImporter('a'), fakeImporter('b')], ['x']);
})->throws(InvalidArgumentException::class, 'Known entities: a, b');

it('rejects duplicate entities', function () {
    ImportPlan::resolve([fakeImporter('a'), fakeImporter('a')]);
})->throws(InvalidArgumentException::class, 'twice');
