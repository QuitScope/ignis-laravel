<?php

namespace Modules\Enotf\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Modules\Enotf\Models\EnotfProtocol;

/**
 * Field-wise autosave merge with revision. Every accepted patch raises `revision` by one and
 * stamps each written key in `field_revisions`. The caller holds the row lock. A key written by another patch after the
 * client's revision is a conflict when the client writes it too (the later request wins, the client learns what it
 * overwrote), otherwise it comes back in `changes_since` for the client to adopt.
 */
final class ProtocolMerger
{
    public const DESTINATION_KEYS = ['transportziel', 'ziel_poi', 'ziel_adresse'];

    /**
     * @param  array<string, mixed>  $values  typed values from FieldSchema::validate
     * @param  array<string, mixed>  $extraColumns  header columns the caller sets itself (allowlisted there), e.g. destination_poi_id
     * @return array{revision: int, applied: list<string>, conflicts: list<array{key: string, value: mixed, revision: int}>, changes_since: array<string, mixed>}
     */
    public function apply(EnotfProtocol $protocol, int $clientRevision, array $values, array $extraColumns = []): array
    {
        if (($values['transportziel'] ?? null) !== null && ! array_key_exists('destination_poi_id', $extraColumns)) {
            $extraColumns['destination_poi_id'] = null;
            if ($protocol->destination_poi_id !== null) {
                $values += ['ziel_poi' => null, 'ziel_adresse' => null];
            }
        }
        $stamps = $protocol->field_revisions ?? [];
        $before = self::values($protocol);
        $revision = $protocol->revision + 1;
        $conflicts = [];
        $changes = [];
        foreach ($stamps as $key => $at) {
            if ($at > $clientRevision && array_key_exists($key, $before)) {
                if (array_key_exists($key, $values)) {
                    $conflicts[] = ['key' => $key, 'value' => $before[$key], 'revision' => $at];
                } else {
                    $changes[$key] = $before[$key];
                }
            }
        }

        $header = FieldSchema::headerKeys();
        $data = $protocol->data ?? [];
        $columns = $extraColumns;
        foreach ($values as $key => $value) {
            if (isset($header[$key])) {
                $columns[$header[$key]] = $value;
            } elseif ($value === null) {
                unset($data[$key]);
            } else {
                $data[$key] = $value;
            }
            $stamps[$key] = $revision;
        }
        $protocol->forceFill($columns + ['data' => $data, 'field_revisions' => $stamps, 'revision' => $revision])->save();
        $after = self::values($protocol);
        $conflicts = array_values(array_filter($conflicts, fn (array $c) => $c['value'] !== $after[$c['key']]));
        if (array_intersect_key($changes, array_flip(self::DESTINATION_KEYS)) !== []) {
            $changes['destination_poi_id'] = $protocol->destination_poi_id;
        }

        return ['revision' => $revision, 'applied' => array_keys($values), 'conflicts' => $conflicts, 'changes_since' => $changes];
    }

    /**
     * Every schema field in the form the client sends back (date-times as organisation wall clock `Y-m-d\TH:i`).
     *
     * @return array<string, mixed>
     */
    public static function values(EnotfProtocol $protocol): array
    {
        $header = FieldSchema::headerKeys();
        $data = $protocol->data ?? [];
        $out = [];
        foreach (FieldSchema::keys() as $key) {
            $raw = isset($header[$key]) ? $protocol->getAttribute($header[$key]) : ($data[$key] ?? null);
            $f = (array) FieldSchema::field($key);
            $out[$key] = match (true) {
                $raw === null => null,
                $f['type'] === 'datetime' => self::wallClock($raw),
                $f['type'] === 'date' && $raw instanceof CarbonInterface => $raw->format('Y-m-d'),
                $f['type'] === 'enum' => FieldSchema::validate([$key => $raw])['values'][$key] ?? null,
                default => $raw,
            };
        }

        return $out;
    }

    private static function wallClock(mixed $at): ?string
    {
        $at = $at instanceof CarbonInterface ? $at : rescue(fn () => CarbonImmutable::parse((string) $at), report: false);

        return $at?->setTimezone((string) config('ignis.timezone'))->format('Y-m-d\TH:i');
    }
}
