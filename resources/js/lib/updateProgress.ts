// View logic of the update panel: which of the pipeline steps is done, current or failed, and which screen a status shows.

export type StepLog = { name: string; at: string; ok: boolean };
export type UpdateStatus = {
    state: { status: string; step: string | null; steps: StepLog[]; reason: string | null; error: string | null; from: string | null; to: string | null; started_at: string | null; backup: string | null };
    stale: boolean;
    maintenance: boolean;
    blocker: string | null;
    trial_unavailable: boolean;
    preflight: { reason: string; message: string } | null;
    backup_available: boolean;
    installed: string;
    image_ref?: string | null;
    driver?: 'files' | 'docker';
    paths?: { state: string; log: string };
    log: string[];
};
export type StepState = 'done' | 'current' | 'failed' | 'pending';
export type View = 'idle' | 'running' | 'done' | 'rolled_back' | 'failed_rollback' | 'failed' | 'stale' | 'unknown';

export const PIPELINE = ['checking', 'downloading', 'verifying', 'backing_up', 'trial_migration', 'switching', 'migrating', 'health_check'] as const;

export function viewOf(s: Pick<UpdateStatus, 'state' | 'stale'>): View {
    if (s.stale) return 'stale';
    const v = s.state.status;
    if (v === 'running' || v === 'rolling_back') return 'running';

    return (['idle', 'done', 'rolled_back', 'failed_rollback', 'failed'].includes(v) ? v : 'unknown') as View;
}

export function stepList(state: Pick<UpdateStatus['state'], 'status' | 'step' | 'steps'>): { name: string; state: StepState }[] {
    const logged = new Map(state.steps.map((s) => [s.name, s.ok]));
    const active = state.status === 'running' || state.status === 'rolling_back';

    return PIPELINE.map((name) => {
        const ok = logged.get(name);
        let st: StepState = 'pending';
        if (ok === false) st = 'failed';
        else if (state.status === 'done' || (ok === true && !(active && state.step === name))) st = 'done';
        else if (active && state.step === name) st = 'current';

        return { name, state: st };
    });
}

export function extraStep(state: Pick<UpdateStatus['state'], 'status' | 'step'>): string | null {
    const active = state.status === 'running' || state.status === 'rolling_back';

    return active && state.step !== null && !(PIPELINE as readonly string[]).includes(state.step) ? state.step : null;
}

export function percent(steps: { state: StepState }[]): number {
    return Math.round((steps.filter((s) => s.state === 'done').length / steps.length) * 100);
}

export function runStarted(before: string | null, now: Pick<UpdateStatus, 'state' | 'stale'>): boolean {
    return viewOf(now) === 'running' || (now.state.started_at !== null && now.state.started_at !== before);
}
