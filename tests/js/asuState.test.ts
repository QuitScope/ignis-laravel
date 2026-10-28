import { expect, it } from 'vitest';
import cases from '../Fixtures/asu-cases.json';
import { derive, type AsuConfig, type AsuEventInput, type AsuTruppInput } from '@/lib/asuState';

// Same fixture as tests/Unit/FireTab/AsuStateTest.php; times in the fixture are ms, which is what the TS port takes.
const get = (o: unknown, path: string) => path.split('.').reduce<unknown>((v, k) => (v == null ? v : (v as Record<string, unknown>)[k]), o);

it.each(cases.cases.map((c) => [c.name, c] as const))('%s', (_name, c) => {
    const s = derive(c.trupp as AsuTruppInput, c.events as AsuEventInput[], c.now, c.config as AsuConfig);
    for (const [path, want] of Object.entries(c.expect)) {
        const got = get(s, path);
        if (typeof want === 'number') {
            expect(Math.abs((got as number) - want), path).toBeLessThanOrEqual(1e-6);
        } else {
            expect(got, path).toEqual(want);
        }
    }
});
