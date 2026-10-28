/**
 * Vital signs (twin of modules/Enotf/Support/VitalparameterCatalog.php): parameter codes, keypad input rules and colour
 * grading of the legacy vitals-keypad.js. Plain ES2020, no library (Chromium 103).
 */
export type BzUnit = 'mg/dl' | 'mmol/l';
export type VitalRow = { id: number; parameter: string; value: number | null; text: string | null; display: string; unit: string; measured_at: string };
export type Grade = 'success' | 'semiwarning' | 'warning' | 'danger';

export const NUMERIC = ['spo2', 'atemfreq', 'etco2', 'herzfreq', 'rrsys', 'rrdias', 'bz', 'temp'] as const;
export const SPECIALS = ['ng', 'nm'];

export function press(value: string, key: string): string {
    if (key === 'clear') return '';
    if (key === 'back') return SPECIALS.includes(value) ? '' : value.slice(0, -1);
    if (SPECIALS.includes(key)) return key;
    const v = SPECIALS.includes(value) ? '' : value;
    if (key === ',') return v.includes(',') ? v : v === '' ? '0,' : `${v},`;
    if (!/^\d$/.test(key)) return v;
    if (v.includes(',')) return (v.split(',')[1] ?? '').length >= 2 ? v : v + key;
    return v.length >= 3 ? v : v + key;
}

export function grade(code: string, raw: string, bzUnit: BzUnit): Grade | null {
    const s = raw.trim().toLowerCase();
    if (s === '' || SPECIALS.includes(s)) return null;
    const v = parseFloat(s.replace(',', '.'));
    if (Number.isNaN(v)) return null;
    switch (code) {
        case 'spo2':
            return v < 87 ? 'danger' : v < 92 ? 'warning' : v < 97 ? 'semiwarning' : 'success';
        case 'atemfreq':
            if (v < 5 || v > 25) return 'danger';
            if (v === 5 || v === 6 || (v > 20 && v < 26)) return 'warning';
            return (v > 6 && v < 9) || (v > 15 && v < 21) ? 'semiwarning' : 'success';
        case 'etco2':
            return v < 6 || v > 55 ? 'danger' : v < 36 || v > 45 ? 'semiwarning' : 'success';
        case 'rrsys':
            if (v < 80 || v > 199) return 'danger';
            if (v < 90 || v > 169) return 'warning';
            return v < 101 || v > 149 ? 'semiwarning' : 'success';
        case 'rrdias':
            if (v <= 40 || v >= 121) return 'danger';
            if (v <= 50 || v >= 111) return 'warning';
            return v <= 60 || v >= 101 ? 'semiwarning' : 'success';
        case 'herzfreq':
            return v < 41 || v > 160 ? 'danger' : v < 51 || v > 130 ? 'warning' : v < 61 || v > 100 ? 'semiwarning' : 'success';
        case 'bz':
            if (bzUnit === 'mmol/l') {
                if (v < 2.2 || v > 13.9) return 'danger';
                if (v < 2.8 || v >= 10) return 'warning';
                return v < 4.5 || v >= 8.3 ? 'semiwarning' : 'success';
            }
            if (v < 40 || v > 250) return 'danger';
            if (v < 51 || v >= 180) return 'warning';
            return v < 81 || v >= 150 ? 'semiwarning' : 'success';
        case 'temp':
            return v <= 34 || v > 40 ? 'danger' : v < 36.1 || v > 38 ? 'semiwarning' : 'success';
    }
    return null;
}

export const RIGHT_AXIS = ['rrsys', 'rrdias', 'herzfreq', 'bz'];

export function series(rows: VitalRow[]): { times: string[]; lines: { code: string; right: boolean; points: [number, number][] }[]; max: number; maxRight: number } {
    const sorted = rows.filter((r) => r.value !== null).sort((a, b) => (a.measured_at < b.measured_at ? -1 : a.measured_at > b.measured_at ? 1 : a.id - b.id));
    const times = [...new Set(sorted.map((r) => r.measured_at))];
    const lines = NUMERIC.map((code) => ({
        code,
        right: RIGHT_AXIS.includes(code),
        points: sorted.filter((r) => r.parameter === code).map((r) => [times.indexOf(r.measured_at), r.value as number] as [number, number]),
    })).filter((l) => l.points.length);
    const top = (right: boolean) => Math.ceil(Math.max(50, ...sorted.filter((r) => RIGHT_AXIS.includes(r.parameter) === right).map((r) => r.value as number)) / 50) * 50;
    return { times, lines, max: top(false), maxRight: top(true) };
}

export function vitalGroups(rows: VitalRow[]): { at: string; rows: VitalRow[] }[] {
    const groups: { at: string; rows: VitalRow[] }[] = [];
    for (const r of rows) {
        const g = groups[groups.length - 1];
        if (g?.at === r.measured_at) g.rows.push(r);
        else groups.push({ at: r.measured_at, rows: [r] });
    }
    return groups;
}

type Segment = [min: number, max: number, grade: Grade];
const RANGES: Record<string, { min: number; max: number; segs: Segment[] }> = {
    spo2: { min: 70, max: 100, segs: [[97, 100, 'success'], [92, 97, 'semiwarning'], [87, 92, 'warning'], [70, 87, 'danger']] },
    atemfreq: { min: 0, max: 35, segs: [[26, 35, 'danger'], [21, 26, 'warning'], [16, 21, 'semiwarning'], [9, 16, 'success'], [7, 9, 'semiwarning'], [5, 7, 'warning'], [0, 5, 'danger']] },
    etco2: { min: 0, max: 60, segs: [[55, 60, 'danger'], [45, 55, 'semiwarning'], [36, 45, 'success'], [6, 36, 'semiwarning'], [0, 6, 'danger']] },
    herzfreq: { min: 20, max: 210, segs: [[160, 210, 'danger'], [130, 160, 'warning'], [100, 130, 'semiwarning'], [61, 100, 'success'], [51, 61, 'semiwarning'], [41, 51, 'warning'], [20, 41, 'danger']] },
    rrsys: { min: 0, max: 260, segs: [[199, 260, 'danger'], [169, 199, 'warning'], [149, 169, 'semiwarning'], [101, 149, 'success'], [90, 101, 'semiwarning'], [80, 90, 'warning'], [0, 80, 'danger']] },
    rrdias: { min: 0, max: 140, segs: [[121, 140, 'danger'], [111, 121, 'warning'], [101, 111, 'semiwarning'], [61, 101, 'success'], [51, 61, 'semiwarning'], [41, 51, 'warning'], [0, 41, 'danger']] },
    'bz:mg/dl': { min: 0, max: 360, segs: [[250, 360, 'danger'], [180, 250, 'warning'], [150, 180, 'semiwarning'], [81, 150, 'success'], [51, 81, 'semiwarning'], [40, 51, 'warning'], [0, 40, 'danger']] },
    'bz:mmol/l': { min: 0, max: 20, segs: [[13.9, 20, 'danger'], [10, 13.9, 'warning'], [8.3, 10, 'semiwarning'], [4.5, 8.3, 'success'], [2.8, 4.5, 'semiwarning'], [2.2, 2.8, 'warning'], [0, 2.2, 'danger']] },
    temp: { min: 30, max: 44, segs: [[40, 44, 'danger'], [38, 40, 'semiwarning'], [36.1, 38, 'success'], [34, 36.1, 'semiwarning'], [30, 34, 'danger']] },
};

export function rangeStrip(code: string, raw: string, bzUnit: BzUnit): { segs: { grade: Grade; share: number; top: number }[]; marker: number | null } | null {
    const r = RANGES[code === 'bz' ? `bz:${bzUnit}` : code];
    if (!r) return null;
    const total = r.max - r.min;
    const v = parseFloat(raw.trim().replace(',', '.'));
    return {
        segs: r.segs.map(([min, max, grade]) => ({ grade, share: ((max - min) / total) * 100, top: max })),
        marker: Number.isNaN(v) ? null : Math.max(0, Math.min(100, 100 - ((v - r.min) / total) * 100)),
    };
}

export const bzToMgdl = (v: number, unit: BzUnit) => Math.round(unit === 'mmol/l' ? v / 0.0555 : v);
export const bzFromMgdl = (v: number, unit: BzUnit) => (unit === 'mmol/l' ? Math.round(v * 0.0555 * 10) / 10 : Math.round(v));

export function vitalText(code: string, v: unknown, bzUnit: BzUnit): string {
    if (v === null || v === undefined || v === '') return '';
    if (typeof v === 'string' && SPECIALS.includes(v)) return v;
    const n = Number(v);
    if (Number.isNaN(n)) return String(v);
    return String(code === 'bz' ? bzFromMgdl(n, bzUnit) : n).replace('.', ',');
}

const UNITS: Record<string, string> = { spo2: '%', atemfreq: '/min', herzfreq: '/min', etco2: 'mmHg', rrsys: 'mmHg', rrdias: 'mmHg', temp: '°C' };
export function vitalPrint(code: string, v: unknown, bzUnit: BzUnit): string {
    const text = vitalText(code, v, bzUnit);
    const unit = code === 'bz' ? bzUnit : UNITS[code];
    return text && unit && !SPECIALS.includes(text) ? `${text} ${unit}` : text;
}

export function vitalValue(code: string, text: string, bzUnit: BzUnit, max?: number): number | string | null | undefined {
    const s = text.trim().toLowerCase();
    if (s === '') return null;
    if (SPECIALS.includes(s)) return s;
    if (!/^\d{1,4}([.,]\d{1,2})?$/.test(s)) return undefined;
    const n = parseFloat(s.replace(',', '.'));
    const v = code === 'bz' ? bzToMgdl(n, bzUnit) : n;
    return max !== undefined && v > max ? undefined : v;
}
