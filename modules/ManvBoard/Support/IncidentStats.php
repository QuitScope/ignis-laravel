<?php

namespace Modules\ManvBoard\Support;

use Illuminate\Support\Facades\DB;
use Modules\ManvBoard\Enums\Triage;

/** Counters for the list: one grouped query over all given Lagen, whatever their number. */
class IncidentStats
{
    /**
     * @param  list<int>  $ids
     * @return array<int, array{total: int, waiting: int, departed: int, triage: array<string, int>}>
     */
    public static function counts(array $ids): array
    {
        $out = array_fill_keys($ids, ['total' => 0, 'waiting' => 0, 'departed' => 0, 'triage' => array_fill_keys(array_column(Triage::cases(), 'value'), 0)]);
        if ($ids === []) {
            return $out;
        }

        $rows = DB::table('manv_patients')->whereIn('manv_incident_id', $ids)
            ->selectRaw('manv_incident_id, triage, count(*) as total, sum(case when departed_at is null then 0 else 1 end) as departed')
            ->groupBy('manv_incident_id', 'triage')->get();

        foreach ($rows as $r) {
            $c = &$out[$r->manv_incident_id];
            $c['total'] += (int) $r->total;
            $c['departed'] += (int) $r->departed;
            $c['waiting'] += (int) $r->total - (int) $r->departed;
            if ($r->triage !== null) {
                $c['triage'][$r->triage] = (int) $r->total;
            }
            unset($c);
        }

        return $out;
    }
}
