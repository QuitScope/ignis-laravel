import { expect, it } from 'vitest';
import { lanes, patientState, triageClass } from '@/lib/manv';

const p = (triage: string | null) => ({ triage });

it('always shows SK1-SK3 and the other lanes only when filled', () => {
    expect(lanes([]).map((l) => l.key)).toEqual(['SK1', 'SK2', 'SK3']);
    const all = lanes([p('SK5'), p(null), p('SK1'), p('SK1'), p('tot')]);
    expect(all.map((l) => l.key)).toEqual(['SK1', 'SK2', 'SK3', 'SK5', 'none']);
    expect(all[0].patients).toHaveLength(2);
    expect(all.find((l) => l.key === 'none')!.patients).toHaveLength(2); // unknown categories land with the untriaged
});

it('keeps the server order inside a lane', () => {
    const a = { triage: 'SK2', n: 1 };
    const b = { triage: 'SK2', n: 2 };
    expect(lanes([a, b])[1].patients).toEqual([a, b]);
});

it('colours the categories and falls back to neutral', () => {
    expect(triageClass('SK1')).toContain('bg-danger');
    expect(triageClass('SK3')).toContain('bg-ok');
    expect(triageClass('SK6')).toContain('fill-3');
    expect(triageClass(null)).toContain('fill-3');
});

it('marks patients with a destination as ready', () => {
    expect(patientState({ destination: 'Klinikum' })).toBe('ready');
    expect(patientState({ destination: null })).toBe('waiting');
    expect(patientState({ destination: '' })).toBe('waiting');
});
