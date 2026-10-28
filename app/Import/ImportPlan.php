<?php

namespace App\Import;

use InvalidArgumentException;

final class ImportPlan
{
    /**
     * Validates the importers and returns them in dependency order. With $only, just those
     * entities plus everything they depend on.
     *
     * @param  list<Importer>  $importers
     * @param  list<string>  $only
     * @return list<Importer>
     */
    public static function resolve(array $importers, array $only = []): array
    {
        $byEntity = [];
        foreach ($importers as $importer) {
            if (isset($byEntity[$importer->entity()])) {
                throw new InvalidArgumentException("Entity {$importer->entity()} is registered twice.");
            }
            $byEntity[$importer->entity()] = $importer;
        }
        $known = 'Known entities: '.implode(', ', array_keys($byEntity));

        foreach ($importers as $importer) {
            foreach ($importer->dependsOn() as $dep) {
                if (! isset($byEntity[$dep])) {
                    throw new InvalidArgumentException("Importer {$importer->entity()} depends on unknown entity {$dep}. {$known}");
                }
            }
        }
        foreach ($only as $entity) {
            if (! isset($byEntity[$entity])) {
                throw new InvalidArgumentException("Unknown entity {$entity}. {$known}");
            }
        }

        $all = self::order($byEntity, array_keys($byEntity));

        if (! $only) {
            return $all;
        }
        $needed = array_map(fn (Importer $i) => $i->entity(), self::order($byEntity, $only));

        return array_values(array_filter($all, fn (Importer $i) => in_array($i->entity(), $needed, true)));
    }

    /**
     * Depth-first order of $roots and their dependencies.
     *
     * @param  array<string, Importer>  $byEntity
     * @param  list<string>  $roots
     * @return list<Importer>
     */
    private static function order(array $byEntity, array $roots): array
    {
        $ordered = [];
        $state = [];
        $visit = function (string $entity) use (&$visit, &$state, &$ordered, $byEntity): void {
            if (($state[$entity] ?? 0) === 2) {
                return;
            }
            if (($state[$entity] ?? 0) === 1) {
                throw new InvalidArgumentException("Dependency cycle at entity {$entity}.");
            }
            $state[$entity] = 1;
            foreach ($byEntity[$entity]->dependsOn() as $dep) {
                $visit($dep);
            }
            $state[$entity] = 2;
            $ordered[] = $byEntity[$entity];
        };
        foreach ($roots as $entity) {
            $visit($entity);
        }

        return $ordered;
    }
}
