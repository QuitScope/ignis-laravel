<?php

namespace App\Import;

use Illuminate\Support\Facades\DB;

/** Legacy id => new id per entity (table legacy_id_map, target connection). */
final class IdMap
{
    public function put(string $entity, string|int $legacyId, int $newId): void
    {
        DB::table('legacy_id_map')->upsert(
            [['entity' => $entity, 'legacy_id' => (string) $legacyId, 'new_id' => $newId]],
            ['entity', 'legacy_id'],
            ['new_id'],
        );
    }

    public function get(string $entity, string|int $legacyId): ?int
    {
        $id = DB::table('legacy_id_map')->where('entity', $entity)->where('legacy_id', (string) $legacyId)->value('new_id');

        return $id === null ? null : (int) $id;
    }

    public function has(string $entity, string|int $legacyId): bool
    {
        return $this->get($entity, $legacyId) !== null;
    }

    /**
     * Updates the row mapped to $legacyId in $table (when it still exists) or inserts one, and maps it. Returns its id.
     *
     * @param  array<string, mixed>  $values
     */
    public function write(string $entity, string|int $legacyId, string $table, array $values): int
    {
        $id = $this->get($entity, $legacyId);
        if ($id !== null && DB::table($table)->where('id', $id)->update($values) === 0 && ! DB::table($table)->where('id', $id)->exists()) {
            $id = null;
        }
        $id ??= (int) DB::table($table)->insertGetId($values);
        $this->put($entity, $legacyId, $id);

        return $id;
    }

    /** @return array<int|string, int> legacy id => new id */
    public function forEntity(string $entity): array
    {
        return DB::table('legacy_id_map')->where('entity', $entity)->pluck('new_id', 'legacy_id')
            ->map(fn ($id) => (int) $id)->all();
    }
}
