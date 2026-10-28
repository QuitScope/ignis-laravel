/**
 * Atemschutzüberwachung: state of one Trupp derived from its events (port of the legacy firetab-asu-state.js).
 * Pure, no own clock (`now` is an argument); times are ms timestamps. The PHP twin is Modules\FireTab\Support\AsuState,
 * both are pinned by tests/Fixtures/asu-cases.json.
 *
 * FwDV 7: asks at 1/3 and 2/3 of the expected time (7.4); retreat at 2 × (pressure when connected − pressure at the target)
 * (7.3); the lowest pressure in the Trupp decides (7.3).
 */

export const DEFAULT_START_BAR = 300;

export type AsuPhase = 'bereit' | 'vorgehen' | 'ziel' | 'rueckzug' | 'draussen';
export type AsuTone = 'mayday' | 'done' | 'idle' | 'ready' | 'alarm' | 'due' | 'back' | 'go';

export interface AsuTruppInput {
    expected_minutes?: number | null;
    role?: string;
    members?: { id: number; position: string; name: string }[];
}

export interface AsuEventInput {
    id: number;
    type: string;
    at: number;
    member_id?: number | null;
    bar?: number | null;
    note?: string | null;
    voided?: boolean;
}

export interface AsuConfig {
    consumption?: number;
}

export interface AsuMemberState {
    id: number;
    position: string;
    name: string;
    bar: number | null;
    at: number | null;
    rate: number | null;
}

export interface AsuAsk {
    type: 'hinweis_13' | 'hinweis_23';
    at: number;
    ack: boolean;
    ackAt: number | null;
}

export interface AsuMayday {
    at: number;
    member: string;
    standort: string;
    lage: string;
}

export interface TruppState {
    phase: AsuPhase;
    tone: AsuTone;
    nextAction: 'anschliessen' | 'ziel' | 'rueckzug' | 'draussen' | null;
    startAt: number | null;
    zielAt: number | null;
    rueckzugAt: number | null;
    endAt: number | null;
    elapsedMs: number;
    expectedMs: number;
    overtime: boolean;
    startBar: number | null;
    zielBar: number | null;
    retreatBar: number | null;
    members: AsuMemberState[];
    lowest: AsuMemberState | null;
    rate: number | null;
    rateSource: 'gemessen' | 'angenommen' | null;
    retreatAt: number | null;
    restMin: number;
    restSource: 'uhr' | 'druck';
    restByClock: number;
    restByPressure: number | null;
    askMin: number;
    askKind: 'erst' | '1/3' | '2/3' | 'seit';
    belowRetreat: boolean;
    retreatOverdue: boolean;
    asks: AsuAsk[];
    nextAsk: AsuAsk | null;
    askDue: boolean;
    underAir: boolean;
    lastReportAt: number | null;
    mayday: AsuMayday | null;
}

const REPORTS = ['angeschlossen', 'druck', 'ziel', 'hinweis_13', 'hinweis_23', 'rueckzug'];
const NEXT_ACTION = { bereit: 'anschliessen', vorgehen: 'ziel', ziel: 'rueckzug', rueckzug: 'draussen', draussen: null } as const;

function live(events: AsuEventInput[]): AsuEventInput[] {
    return events.filter((e) => !e.voided).sort((a, b) => a.at - b.at || a.id - b.id);
}

function rateOf(readings: { bar: number; at: number }[]): number | null {
    if (readings.length < 2) return null;
    const a = readings[readings.length - 2];
    const b = readings[readings.length - 1];
    const minutes = (b.at - a.at) / 60000;
    if (minutes < 0.5 || b.bar >= a.bar) return null;
    return (a.bar - b.bar) / minutes;
}

export function derive(trupp: AsuTruppInput, allEvents: AsuEventInput[], now: number, config: AsuConfig = {}): TruppState {
    const events = live(allEvents);
    const first = (type: string) => events.find((e) => e.type === type) ?? null;
    const start = first('angeschlossen');
    const ziel = first('ziel');
    const rueckzug = first('rueckzug');
    const draussen = first('draussen');
    const expectedMs = (trupp.expected_minutes || 30) * 60000;

    let phase: AsuPhase = 'bereit';
    if (start) phase = 'vorgehen';
    if (start && ziel) phase = 'ziel';
    if (start && rueckzug) phase = 'rueckzug';
    if (start && draussen) phase = 'draussen';

    const startBar = start ? start.bar || DEFAULT_START_BAR : null;

    const members: AsuMemberState[] = (trupp.members ?? []).map((m) => {
        const readings = start ? [{ bar: startBar as number, at: start.at }] : [];
        for (const e of events) {
            if (e.type === 'druck' && e.member_id === m.id && e.bar != null) readings.push({ bar: e.bar, at: e.at });
        }
        const last = readings.length ? readings[readings.length - 1] : null;
        return { id: m.id, position: m.position, name: m.name, bar: last ? last.bar : null, at: last ? last.at : null, rate: rateOf(readings) };
    });

    let lowest: AsuMemberState | null = null;
    for (const m of members) {
        if (m.bar != null && (lowest === null || m.bar < (lowest.bar as number))) lowest = m;
    }

    const zielBar = ziel ? (ziel.bar != null ? ziel.bar : (lowest?.bar ?? null)) : null;
    const retreatBar = startBar != null && zielBar != null ? Math.max(0, 2 * (startBar - zielBar)) : null;

    let rate = lowest ? lowest.rate : null;
    let rateSource: TruppState['rateSource'] = rate !== null ? 'gemessen' : null;
    if (rate === null && lowest && (config.consumption ?? 0) > 0) {
        rate = config.consumption as number;
        rateSource = 'angenommen';
    }
    let retreatAt: number | null = null;
    if (retreatBar != null && rate && lowest && (lowest.bar as number) > retreatBar) {
        retreatAt = (lowest.at as number) + (((lowest.bar as number) - retreatBar) / rate) * 60000;
    }

    const advancing = phase === 'vorgehen' || phase === 'ziel';
    const underAir = advancing || phase === 'rueckzug';
    const belowRetreat = retreatBar != null && lowest !== null && (lowest.bar as number) <= retreatBar && advancing;
    const retreatOverdue = retreatAt !== null && now >= retreatAt && advancing;

    const asks: AsuAsk[] = [];
    if (start) {
        for (const [type, share] of [['hinweis_13', 1 / 3], ['hinweis_23', 2 / 3]] as const) {
            const ack = first(type);
            asks.push({ type, at: start.at + expectedMs * share, ack: !!ack, ackAt: ack ? ack.at : null });
        }
    }
    const nextAsk = asks.find((a) => !a.ack) ?? null;
    const askDue = advancing && nextAsk !== null && now >= nextAsk.at;

    let mayday: AsuMayday | null = null;
    for (const e of events) {
        if (e.type === 'mayday') {
            let info: Record<string, string> = {};
            try {
                info = JSON.parse(e.note || '{}') || {};
            } catch {
                info = { lage: e.note as string };
            }
            mayday = { at: e.at, member: info.member || '', standort: info.standort || '', lage: info.lage || '' };
        } else if (e.type === 'mayday_ende') {
            mayday = null;
        }
    }

    let lastReportAt: number | null = null;
    for (const e of events) if (REPORTS.includes(e.type)) lastReportAt = e.at;

    const endAt = draussen ? draussen.at : null;
    const elapsedMs = start ? Math.max(0, (endAt || now) - start.at) : 0;
    const overtime = underAir && elapsedMs > expectedMs;

    let tone: AsuTone;
    if (mayday) tone = 'mayday';
    else if (phase === 'draussen') tone = 'done';
    else if (!start) tone = trupp.role === 'sicherheit' ? 'ready' : 'idle';
    else if (belowRetreat || retreatOverdue) tone = 'alarm';
    else if (askDue || overtime) tone = 'due';
    else if (phase === 'rueckzug') tone = 'back';
    else tone = 'go';

    const restByClock = Math.max(0, Math.round((expectedMs - elapsedMs) / 60000));
    let restByPressure = retreatAt !== null ? Math.max(0, Math.round((retreatAt - now) / 60000)) : null;
    if (belowRetreat) restByPressure = 0;
    let restMin = restByClock;
    let restSource: TruppState['restSource'] = 'uhr';
    if (restByPressure !== null && restByPressure <= restByClock) {
        restMin = restByPressure;
        restSource = 'druck';
    }

    let askMin: number;
    let askKind: TruppState['askKind'];
    if (!start) {
        askMin = Math.round(expectedMs / 3 / 60000);
        askKind = 'erst';
    } else if (nextAsk) {
        askMin = Math.max(0, Math.ceil((nextAsk.at - now) / 60000));
        askKind = nextAsk.type === 'hinweis_13' ? '1/3' : '2/3';
    } else {
        askMin = lastReportAt !== null ? Math.max(0, Math.floor((now - lastReportAt) / 60000)) : 0;
        askKind = 'seit';
    }

    return {
        phase, tone, nextAction: NEXT_ACTION[phase],
        startAt: start ? start.at : null, zielAt: ziel ? ziel.at : null, rueckzugAt: rueckzug ? rueckzug.at : null, endAt,
        elapsedMs, expectedMs, overtime, startBar, zielBar, retreatBar, members, lowest, rate, rateSource, retreatAt,
        restMin, restSource, restByClock, restByPressure, askMin, askKind, belowRetreat, retreatOverdue,
        asks, nextAsk, askDue, underAir, lastReportAt, mayday,
    };
}
