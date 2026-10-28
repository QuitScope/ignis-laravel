<?php

namespace Modules\FireTab\Support;

use DateTimeInterface;

/**
 * Atemschutzüberwachung: state of one Trupp derived from its events. PHP twin of resources/js/lib/asuState.ts
 * (port of the legacy firetab-asu-state.js); both are pinned by tests/Fixtures/asu-cases.json. Pure: `now` is an argument.
 * Event `at` and `now` take ms timestamps or DateTimeInterface; all times in the result are ms timestamps.
 * The report (Registrierung FwDV 7.4) reads startAt/zielAt/rueckzugAt/endAt/asks from here instead of its own "first event per type".
 */
final class AsuState
{
    public const DEFAULT_START_BAR = 300;

    private const REPORTS = ['angeschlossen', 'druck', 'ziel', 'hinweis_13', 'hinweis_23', 'rueckzug'];

    private const NEXT_ACTION = ['bereit' => 'anschliessen', 'vorgehen' => 'ziel', 'ziel' => 'rueckzug', 'rueckzug' => 'draussen', 'draussen' => null];

    /**
     * @param  array{expected_minutes?: int|null, role?: string, members?: list<array{id: int, position: string, name: string}>}  $trupp
     * @param  list<array{id: int, type: string, at: int|DateTimeInterface, member_id?: int|null, bar?: int|null, note?: string|null, voided?: bool}>  $allEvents
     * @param  array{consumption?: int|float}  $config  consumption: normal bar/min (firetab.asu_consumption) while none is measurable
     * @return array<string, mixed>
     */
    public static function derive(array $trupp, array $allEvents, int|DateTimeInterface $now, array $config = []): array
    {
        $now = self::ms($now);
        $events = self::live($allEvents);
        $start = self::first($events, 'angeschlossen');
        $ziel = self::first($events, 'ziel');
        $rueckzug = self::first($events, 'rueckzug');
        $draussen = self::first($events, 'draussen');
        $expectedMs = ((int) ($trupp['expected_minutes'] ?? 0) ?: 30) * 60000;

        $phase = 'bereit';
        if ($start !== null) {
            $phase = 'vorgehen';
            if ($ziel !== null) {
                $phase = 'ziel';
            }
            if ($rueckzug !== null) {
                $phase = 'rueckzug';
            }
            if ($draussen !== null) {
                $phase = 'draussen';
            }
        }

        $startBar = $start !== null ? (($start['bar'] ?? 0) ?: self::DEFAULT_START_BAR) : null;

        $members = [];
        foreach ($trupp['members'] ?? [] as $m) {
            $readings = $start !== null ? [['bar' => $startBar, 'at' => $start['at']]] : [];
            foreach ($events as $e) {
                if ($e['type'] === 'druck' && ($e['member_id'] ?? null) === $m['id'] && ($e['bar'] ?? null) !== null) {
                    $readings[] = ['bar' => $e['bar'], 'at' => $e['at']];
                }
            }
            $last = $readings === [] ? null : $readings[count($readings) - 1];
            $members[] = ['id' => $m['id'], 'position' => $m['position'], 'name' => $m['name'], 'bar' => $last['bar'] ?? null, 'at' => $last['at'] ?? null, 'rate' => self::rateOf($readings)];
        }

        $lowest = null;
        foreach ($members as $m) {
            if ($m['bar'] !== null && ($lowest === null || $m['bar'] < $lowest['bar'])) {
                $lowest = $m;
            }
        }

        $zielBar = $ziel !== null ? ($ziel['bar'] ?? $lowest['bar'] ?? null) : null;
        $retreatBar = $startBar !== null && $zielBar !== null ? max(0, 2 * ($startBar - $zielBar)) : null;

        $rate = $lowest['rate'] ?? null;
        $rateSource = $rate !== null ? 'gemessen' : null;
        if ($rate === null && $lowest !== null && ($config['consumption'] ?? 0) > 0) {
            $rate = $config['consumption'];
            $rateSource = 'angenommen';
        }
        $retreatAt = null;
        if ($retreatBar !== null && $rate && $lowest !== null && $lowest['bar'] > $retreatBar) {
            $retreatAt = $lowest['at'] + (($lowest['bar'] - $retreatBar) / $rate) * 60000;
        }

        $advancing = $phase === 'vorgehen' || $phase === 'ziel';
        $underAir = $advancing || $phase === 'rueckzug';
        $belowRetreat = $retreatBar !== null && $lowest !== null && $lowest['bar'] <= $retreatBar && $advancing;
        $retreatOverdue = $retreatAt !== null && $now >= $retreatAt && $advancing;

        $asks = [];
        if ($start !== null) {
            foreach (['hinweis_13' => 1 / 3, 'hinweis_23' => 2 / 3] as $type => $share) {
                $ack = self::first($events, $type);
                $asks[] = ['type' => $type, 'at' => $start['at'] + $expectedMs * $share, 'ack' => $ack !== null, 'ackAt' => $ack['at'] ?? null];
            }
        }
        $nextAsk = null;
        foreach ($asks as $a) {
            if (! $a['ack']) {
                $nextAsk = $a;
                break;
            }
        }
        $askDue = $advancing && $nextAsk !== null && $now >= $nextAsk['at'];

        $mayday = null;
        foreach ($events as $e) {
            if ($e['type'] === 'mayday') {
                $note = ($e['note'] ?? '') ?: '{}';
                $info = json_decode($note, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $info = ['lage' => $note];
                }
                $info = is_array($info) ? $info : [];
                $mayday = ['at' => $e['at'], 'member' => ($info['member'] ?? '') ?: '', 'standort' => ($info['standort'] ?? '') ?: '', 'lage' => ($info['lage'] ?? '') ?: ''];
            } elseif ($e['type'] === 'mayday_ende') {
                $mayday = null;
            }
        }

        $lastReportAt = null;
        foreach ($events as $e) {
            if (in_array($e['type'], self::REPORTS, true)) {
                $lastReportAt = $e['at'];
            }
        }

        $endAt = $draussen['at'] ?? null;
        $elapsedMs = $start !== null ? max(0, ($endAt ?: $now) - $start['at']) : 0;
        $overtime = $underAir && $elapsedMs > $expectedMs;

        $tone = match (true) {
            $mayday !== null => 'mayday',
            $phase === 'draussen' => 'done',
            $start === null => ($trupp['role'] ?? null) === 'sicherheit' ? 'ready' : 'idle',
            $belowRetreat || $retreatOverdue => 'alarm',
            $askDue || $overtime => 'due',
            $phase === 'rueckzug' => 'back',
            default => 'go',
        };

        $restByClock = max(0, self::round(($expectedMs - $elapsedMs) / 60000));
        $restByPressure = $retreatAt !== null ? max(0, self::round(($retreatAt - $now) / 60000)) : null;
        if ($belowRetreat) {
            $restByPressure = 0;
        }
        $restMin = $restByClock;
        $restSource = 'uhr';
        if ($restByPressure !== null && $restByPressure <= $restByClock) {
            $restMin = $restByPressure;
            $restSource = 'druck';
        }

        if ($start === null) {
            $askMin = self::round($expectedMs / 3 / 60000);
            $askKind = 'erst';
        } elseif ($nextAsk !== null) {
            $askMin = max(0, (int) ceil(($nextAsk['at'] - $now) / 60000));
            $askKind = $nextAsk['type'] === 'hinweis_13' ? '1/3' : '2/3';
        } else {
            $askMin = $lastReportAt !== null ? max(0, (int) floor(($now - $lastReportAt) / 60000)) : 0;
            $askKind = 'seit';
        }

        return [
            'phase' => $phase, 'tone' => $tone, 'nextAction' => self::NEXT_ACTION[$phase],
            'startAt' => $start['at'] ?? null, 'zielAt' => $ziel['at'] ?? null, 'rueckzugAt' => $rueckzug['at'] ?? null, 'endAt' => $endAt,
            'elapsedMs' => $elapsedMs, 'expectedMs' => $expectedMs, 'overtime' => $overtime,
            'startBar' => $startBar, 'zielBar' => $zielBar, 'retreatBar' => $retreatBar,
            'members' => $members, 'lowest' => $lowest, 'rate' => $rate, 'rateSource' => $rateSource, 'retreatAt' => $retreatAt,
            'restMin' => $restMin, 'restSource' => $restSource, 'restByClock' => $restByClock, 'restByPressure' => $restByPressure,
            'askMin' => $askMin, 'askKind' => $askKind, 'belowRetreat' => $belowRetreat, 'retreatOverdue' => $retreatOverdue,
            'asks' => $asks, 'nextAsk' => $nextAsk, 'askDue' => $askDue, 'underAir' => $underAir,
            'lastReportAt' => $lastReportAt, 'mayday' => $mayday,
        ];
    }

    private static function ms(int|DateTimeInterface $t): int
    {
        return $t instanceof DateTimeInterface ? (int) $t->format('Uv') : $t;
    }

    private static function round(float|int $x): int
    {
        return (int) floor($x + 0.5);
    }

    /**
     * Live events ordered by time, then id; `at` normalised to ms.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private static function live(array $events): array
    {
        $live = [];
        foreach ($events as $e) {
            if (empty($e['voided'])) {
                $e['at'] = self::ms($e['at']);
                $live[] = $e;
            }
        }
        usort($live, fn (array $a, array $b) => [$a['at'], $a['id']] <=> [$b['at'], $b['id']]);

        return $live;
    }

    /**
     * The first live event of a type is the one that counts.
     *
     * @param  list<array<string, mixed>>  $events
     * @return array<string, mixed>|null
     */
    private static function first(array $events, string $type): ?array
    {
        foreach ($events as $e) {
            if ($e['type'] === $type) {
                return $e;
            }
        }

        return null;
    }

    /**
     * bar/min from a person's last two readings; under 30 s apart it is only noise.
     *
     * @param  list<array{bar: int|float, at: int}>  $readings
     */
    private static function rateOf(array $readings): ?float
    {
        $n = count($readings);
        if ($n < 2) {
            return null;
        }
        [$a, $b] = [$readings[$n - 2], $readings[$n - 1]];
        $minutes = ($b['at'] - $a['at']) / 60000;
        if ($minutes < 0.5 || $b['bar'] >= $a['bar']) {
            return null;
        }

        return ($a['bar'] - $b['bar']) / $minutes;
    }
}
