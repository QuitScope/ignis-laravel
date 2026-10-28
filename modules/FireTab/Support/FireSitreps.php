<?php

namespace Modules\FireTab\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\FireTab\Enums\SitrepSource;
use Modules\FireTab\Models\FireIncident;
use Modules\FireTab\Models\FireSitrep;

/**
 * Sitrep exchange with the dispatch (EMD sync; no route here).
 * Sending: call pendingFor() and markSynced() inside one DB::transaction; the row lock keeps a parallel sync from
 * sending the same sitreps twice, and markSynced() only marks rows still unsynced.
 */
final class FireSitreps
{
    public const DEDUPE_MINUTES = 15;

    /** @return Collection<int, FireSitrep> local, not yet sent, oldest first, locked FOR UPDATE (inside a transaction only) */
    public static function pendingFor(FireIncident $incident): Collection
    {
        throw_if(DB::transactionLevel() === 0, LogicException::class, 'FireSitreps::pendingFor needs a transaction (with markSynced).');

        return FireSitrep::query()->where('fire_incident_id', $incident->id)->where('source', SitrepSource::Local)
            ->whereNull('synced_at')->orderBy('reported_at')->orderBy('id')->lockForUpdate()->get();
    }

    /** @param list<int> $ids */
    public static function markSynced(array $ids): int
    {
        return $ids === [] ? 0 : FireSitrep::query()->whereIn('id', $ids)->whereNull('synced_at')->update(['synced_at' => now()]);
    }

    /** Stores a dispatch sitrep unless the same text exists within ±15 min. @return ?FireSitrep null when empty or a duplicate */
    public static function importDispatchSitrep(FireIncident $incident, string $text, CarbonInterface $at, bool $important): ?FireSitrep
    {
        $text = trim($text);
        $at = $at->copy()->utc();
        if ($text === '' || FireSitrep::query()->where('fire_incident_id', $incident->id)->where('text', $text)
            ->whereBetween('reported_at', [$at->copy()->subMinutes(self::DEDUPE_MINUTES), $at->copy()->addMinutes(self::DEDUPE_MINUTES)])->exists()) {
            return null;
        }

        $sitrep = (new FireSitrep)->forceFill([
            'fire_incident_id' => $incident->id, 'reported_at' => $at, 'text' => $text, 'reporter_name' => 'Leitstelle',
            'source' => SitrepSource::Dispatch, 'is_important' => $important, 'synced_at' => now(),
        ]);
        $sitrep->save();

        return $sitrep;
    }
}
