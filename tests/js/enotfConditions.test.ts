import { expect, it } from 'vitest';
import fixture from '../Fixtures/enotf-conditions.json';
import { activeRules, countBySection, evaluate, sectionStatus } from '@/lib/enotfConditions';

// Same fixture as tests/Unit/Enotf/ConditionsServiceTest.php.
type Case = {
    name: string;
    values?: Record<string, unknown>;
    extends?: 'full';
    set?: Record<string, unknown>;
    unset?: string[];
    violations?: string[];
    violation_count?: number;
    counts?: Record<string, number>;
    active_count?: number;
    active?: string[];
    sections?: Record<string, unknown>;
    section_order?: string[];
};

it.each((fixture.cases as Case[]).map((c) => [c.name, c] as const))('%s', (_name, c) => {
    let values: Record<string, unknown> = c.values ?? {};
    if (c.extends) {
        values = { ...fixture[c.extends], ...c.set };
        for (const k of c.unset ?? []) delete values[k];
    }
    const violations = evaluate(values);
    const tz = values.transportziel == null ? null : Number.parseInt(String(values.transportziel), 10);

    if (c.violations) expect(violations.map((v) => `${v.rule}:${v.field}`).sort()).toEqual([...c.violations].sort());
    if (c.violation_count !== undefined) expect(violations).toHaveLength(c.violation_count);
    const counts = countBySection(violations);
    for (const [section, n] of Object.entries(c.counts ?? {})) expect(counts[section] ?? 0, section).toBe(n);
    if (c.active_count !== undefined) expect(Object.keys(activeRules(tz))).toHaveLength(c.active_count);
    if (c.active) expect(Object.keys(activeRules(tz)).sort()).toEqual([...c.active].sort());
    const status = sectionStatus(values);
    for (const [section, want] of Object.entries(c.sections ?? {})) {
        expect(typeof want === 'string' ? status[section].status : status[section], section).toEqual(want);
    }
    if (c.section_order) expect(Object.keys(status)).toEqual(c.section_order);
});
