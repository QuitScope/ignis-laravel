import { describe, expect, it } from 'vitest';
import { grade, press, rangeStrip, series, type VitalRow, vitalGroups, vitalValue } from '@/lib/enotfVitals';

describe('enotfVitals', () => {
    it('rejects values above the schema maximum', () => {
        expect(vitalValue('etco2', '250', 'mg/dl', 200)).toBeUndefined();
        expect(vitalValue('temp', '36,5', 'mg/dl', 50)).toBe(36.5);
        expect(vitalValue('bz', '120', 'mmol/l', 2000)).toBeUndefined(); // 120 mmol/l = 2162 mg/dl
        expect(vitalValue('spo2', 'ng', 'mg/dl', 100)).toBe('ng');
    });

    it('applies the legacy keypad rules', () => {
        expect(['1', '2', '3', '4'].reduce(press, '')).toBe('123');
        expect([',', '5', '5', '5'].reduce(press, '')).toBe('0,55');
        expect(press('ng', '7')).toBe('7');
        expect(press('36', 'nm')).toBe('nm');
        expect(press('36,5', 'back')).toBe('36,');
        expect(press('36,5', 'clear')).toBe('');
    });

    it('grades like vitals-keypad.js and ignores ng/nm', () => {
        expect(grade('spo2', '86', 'mg/dl')).toBe('danger');
        expect(grade('spo2', '98', 'mg/dl')).toBe('success');
        expect(grade('bz', '5,5', 'mmol/l')).toBe('success');
        expect(grade('bz', '5,5', 'mg/dl')).toBe('danger');
        expect(grade('bz', 'ng', 'mg/dl')).toBeNull();
    });

    it('never charts ng/nm as 0', () => {
        const row = (id: number, parameter: string, value: number | null, at: string): VitalRow => ({ id, parameter, value, text: value === null ? 'ng' : null, display: '', unit: '', measured_at: at });
        const s = series([row(1, 'bz', null, '2026-10-23 10:00'), row(2, 'bz', 120, '2026-10-23 10:05'), row(3, 'spo2', 97, '2026-10-23 10:00')]);
        expect(s.lines.find((l) => l.code === 'bz')?.points).toEqual([[1, 120]]);
        expect(s.times).toEqual(['2026-10-23 10:00', '2026-10-23 10:05']);
    });

    it('range strip: legacy segments per parameter and blood sugar unit, marker at the value', () => {
        const mg = rangeStrip('bz', '81', 'mg/dl')!;
        expect(mg.segs.map((x) => x.top)).toEqual([360, 250, 180, 150, 81, 51, 40]);
        expect(mg.marker).toBeCloseTo(77.5);
        expect(rangeStrip('bz', '', 'mmol/l')!.segs[3]).toMatchObject({ grade: 'success', top: 8.3 });
        expect(rangeStrip('bemerkung', '1', 'mg/dl')).toBeNull();
    });

    it('charts RR/HF/BZ on the right axis and groups the table by time', () => {
        const row = (id: number, parameter: string, value: number, at: string): VitalRow => ({ id, parameter, value, text: null, display: '', unit: '', measured_at: at });
        const rows = [row(3, 'rrsys', 180, '2026-10-23 10:05'), row(2, 'spo2', 97, '2026-10-23 10:05'), row(1, 'temp', 37, '2026-10-23 10:00')];
        const s = series(rows);
        expect([s.max, s.maxRight, s.lines.find((l) => l.code === 'rrsys')?.right]).toEqual([100, 200, true]);
        expect(vitalGroups(rows).map((g) => [g.at, g.rows.length])).toEqual([['2026-10-23 10:05', 2], ['2026-10-23 10:00', 1]]);
    });
});
