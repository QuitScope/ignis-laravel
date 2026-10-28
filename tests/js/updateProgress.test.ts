import { expect, it } from 'vitest';
import { PIPELINE, extraStep, percent, runStarted, stepList, viewOf } from '@/lib/updateProgress';

const state = (status: string, step: string | null, steps: [string, boolean][] = [], started_at: string | null = null) => ({
    status,
    step,
    steps: steps.map(([name, ok]) => ({ name, ok, at: 't' })),
    error: null,
    from: '1.0.0',
    to: '2.0.0',
    started_at,
    backup: null,
});

it('marks finished steps done, the reported one current and the rest pending', () => {
    const list = stepList(state('running', 'backing_up', [['checking', true], ['downloading', true], ['verifying', true], ['backing_up', true]]));
    expect(list.map((s) => s.state)).toEqual(['done', 'done', 'done', 'current', 'pending', 'pending', 'pending', 'pending']);
    expect(percent(list)).toBe(38);
});

it('marks the failing step failed', () => {
    const list = stepList(state('rolled_back', 'switching', [['checking', true], ['switching', false]]));
    expect(list.find((s) => s.name === 'switching')?.state).toBe('failed');
    expect(list.find((s) => s.name === 'checking')?.state).toBe('done');
});

it('shows every step done after a finished run, none before one', () => {
    expect(stepList(state('done', null)).every((s) => s.state === 'done')).toBe(true);
    expect(percent(stepList(state('done', null)))).toBe(100);
    expect(stepList(state('idle', null)).every((s) => s.state === 'pending')).toBe(true);
    expect(stepList(state('idle', null))).toHaveLength(PIPELINE.length);
});

it('picks the screen; a crashed run beats its stored status', () => {
    expect(viewOf({ state: state('running', 'switching'), stale: false })).toBe('running');
    expect(viewOf({ state: state('rolling_back', 'rolling_back'), stale: false })).toBe('running');
    expect(viewOf({ state: state('running', 'switching'), stale: true })).toBe('stale');
    expect(viewOf({ state: state('failed_rollback', null), stale: false })).toBe('failed_rollback');
    expect(viewOf({ state: state('whatever', null), stale: false })).toBe('unknown');
});

it('recognises the new run after a start click', () => {
    expect(runStarted('t1', { state: state('done', null, [], 't1'), stale: false })).toBe(false);
    expect(runStarted('t1', { state: state('running', 'checking', [], 't2'), stale: false })).toBe(true);
    expect(runStarted('t1', { state: state('failed', 'checking', [], 't2'), stale: false })).toBe(true);
    expect(runStarted(null, { state: state('idle', null), stale: false })).toBe(false);
});

it('names a step outside the pipeline (manual rollback) only while active', () => {
    expect(extraStep(state('rolling_back', 'rolling_back'))).toBe('rolling_back');
    expect(extraStep(state('rolling_back', 'switching'))).toBeNull();
    expect(extraStep(state('running', 'checking'))).toBeNull();
    expect(extraStep(state('rolled_back', 'rolling_back'))).toBeNull();
});
