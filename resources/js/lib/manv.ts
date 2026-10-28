// MANV board helpers: lanes per triage, triage colours, patient state.

export const TRIAGES = ['SK1', 'SK2', 'SK3', 'SK4', 'SK5', 'SK6'] as const;

export type BoardPatient = {
    id: number;
    number: string;
    triage: string | null;
    age: number | null;
    sex: string;
    vehicle: string | null;
    destination: string | null;
    can_depart: boolean;
};

export type Lane<P> = { key: string; patients: P[] };

export function lanes<P extends { triage: string | null }>(patients: P[]): Lane<P>[] {
    const keys: string[] = [...TRIAGES, 'none'];
    const by = new Map<string, P[]>(keys.map((k) => [k, []]));
    for (const p of patients) by.get(p.triage !== null && by.has(p.triage) ? p.triage : 'none')!.push(p);

    return keys.map((key) => ({ key, patients: by.get(key)! })).filter((l) => l.patients.length > 0 || ['SK1', 'SK2', 'SK3'].includes(l.key));
}

const TONES: Record<string, string> = {
    SK1: 'bg-danger text-white',
    SK2: 'bg-warn text-black',
    SK3: 'bg-ok text-white',
    SK4: 'bg-info text-white',
    SK5: 'bg-text text-bg',
};

export function triageClass(triage: string | null): string {
    return (triage !== null && TONES[triage]) || 'bg-(--fill-3) text-text';
}

export function patientState(p: { destination: string | null }): 'ready' | 'waiting' {
    return p.destination ? 'ready' : 'waiting';
}
