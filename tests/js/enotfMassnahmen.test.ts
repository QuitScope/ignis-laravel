import { expect, it } from 'vitest';
import { medTime, parseDose, zugangText, zugangToggle } from '@/lib/enotfUi';

const pvk = (groesse: string) => ({ art: 'pvk', groesse, ort: 'Handrücken', seite: 'links' });
const zvk = { art: 'zvk', groesse: null, ort: 'Hals', seite: 'rechts' };

it('keeps one size per access site, the last one off = [] and foreign entries stay', () => {
    expect(zugangToggle([zvk, pvk('20G')], pvk('18G'))).toEqual([zvk, pvk('18G')]); // size replaced
    expect(zugangToggle([pvk('18G')], pvk('18G'))).toEqual([]); // last one off -> "Kein Zugang"
    expect(zugangToggle(null, pvk('18G'))).toEqual([pvk('18G')]);
    expect(zugangText([zvk, pvk('18G')], 'pvk', 'Kein Zugang', '-')).toBe('PVK 18 G Handrücken links');
});

it('dates a manual medication time on the server day, yesterday when later than now', () => {
    expect(medTime('10:15', '2026-10-23T10:20')).toBe('2026-10-23 10:15');
    expect(medTime('23:50', '2026-10-23T00:10')).toBe('2026-10-22 23:50');
    expect(medTime(null, '2026-10-23T10:20')).toBeNull(); // server time
});

it('reads dose and unit from a catalog dose', () => {
    expect(parseDose('100 mg')).toEqual({ dose: '100', unit: 'mg' });
    expect(parseDose('0,5µg')).toEqual({ dose: '0,5', unit: 'mcg' });
    expect(parseDose('1 Hub')).toBeNull();
});
