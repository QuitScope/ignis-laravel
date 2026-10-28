import { expect, it, vi } from 'vitest';
import { createApp, h, nextTick, reactive, ref } from 'vue';
import { addressLine, ageOn, createClock, nowWall, pageStatus, parseDate, parseWizard, placeLine, printTitleKey, tileState, toggleExclusive, useFocusDraft } from '@/lib/enotfUi';
import { vitalPrint } from '@/lib/enotfVitals';

it('stripes a tile by the active rules', () => {
    const req = ['d_pupillenw_1', 'd_pupillenw_2'];
    expect(tileState(req, {})).toBe('red');
    expect(tileState(req, { d_pupillenw_1: 0 })).toBe('yellow'); // 0 is a valid code here (ZERO_IS_VALID)
    expect(tileState(req, { d_pupillenw_1: 1, d_pupillenw_2: 2 })).toBe('green');
    expect(tileState(['diagnose'], {})).toBeNull(); // no rule
});

it('page fill follows the transport target (override 4 drops the findings)', () => {
    expect(pageStatus({}).erstbefund).toBe('red');
    expect(pageStatus({ transportziel: 4 }).erstbefund).toBeNull();
    expect(pageStatus({}).rettdaten).toBe('red'); // patsex placed on rettdaten (legacy), counted there
});

it('keeps exclusive codes alone', () => {
    expect(toggleExclusive([2, 3], 1, [1, 98, 99])).toEqual([1]);
    expect(toggleExclusive([1], 4, [1, 98, 99])).toEqual([4]);
    expect(toggleExclusive([4], 4, [1])).toBeNull();
});

it('opens no step without q', () => {
    expect(parseWizard('?t=atemwege')).toEqual({ topic: 'atemwege', step: null });
    expect(parseWizard('?t=atemwege&q=2')).toEqual({ topic: 'atemwege', step: 2 });
});

it('parses the date mask like the legacy form', () => {
    const today = new Date('2026-10-23T12:00:00Z');
    expect(parseDate('2000-01-01', today)).toBe('2000-01-01');
    expect(parseDate('01012000', today)).toBe('2000-01-01');
    expect(parseDate('010190', today)).toBe('1990-01-01');
    expect(parseDate('010126', today)).toBe('2026-01-01'); // sliding cutoff: up to this year -> 20xx
    expect(parseDate('31022000', today)).toBeNull();
    expect(parseDate('abc', today)).toBeNull();
});

it('takes "now" from the server clock in the organisation zone', () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-07-01T09:00:00Z')); // tablet clock one hour behind
    const clock = createClock('2026-07-01T10:00:00.000Z');
    expect(nowWall(clock, 'Europe/Berlin')).toBe('2026-07-01T12:00'); // summer +2
    vi.setSystemTime(new Date('2026-01-15T09:00:00Z'));
    expect(nowWall(createClock('2026-01-15T10:00:00.000Z'), 'Europe/Berlin')).toBe('2026-01-15T11:00'); // winter +1
    vi.useRealTimers();
});

it('re-syncs the server clock from the status poll (stale `now` after a back/forward restore)', () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-07-01T10:20:00Z'));
    const clock = createClock('2026-07-01T10:00:00.000Z'); // restored page: `now` 20 min old
    clock.sync('2026-07-01T10:20:01+00:00'); // poll time (whole seconds)
    expect(clock.now().toISOString()).toBe('2026-07-01T10:20:01.000Z');
    clock.sync('2026-07-01T10:20:00+00:00'); // within 2 s: no jitter
    expect(clock.now().toISOString()).toBe('2026-07-01T10:20:01.000Z');
    clock.sync('garbage');
    expect(clock.now().toISOString()).toBe('2026-07-01T10:20:01.000Z');
    vi.useRealTimers();
});

it('stripes Abschluss red while "Reanimation durchgeführt" lacks its details (rea_details)', () => {
    const req = ['rea_status', 'rea_details'];
    expect(tileState(req, { rea_status: 1 })).toBe('green');
    expect(tileState(req, { rea_status: 2 })).toBe('yellow');
    const details = { rea_ursache: 1, rea_kollaps: 1, rea_hdm_durch: 1, rea_hdm_zeit: '10:00', rea_rosc: 1, rea_kh_aufnahme: 1 };
    expect(tileState(req, { rea_status: 2, ...details })).toBe('green');
});

function mountDraft(form: { values: Record<string, unknown> }, open: { value: boolean }) {
    let api!: ReturnType<typeof useFocusDraft>;
    const draft = reactive({ date: null as unknown, time: null as unknown });
    const el = document.body.appendChild(document.createElement('div'));
    const app = createApp({
        setup() {
            api = useFocusDraft(() => (open.value ? 'symptome' : null), draft, () => ({ date: form.values.d ?? null, time: form.values.t ?? null }));
            return () => h('div');
        },
    });
    app.provide('enotfForm', { ...form, enr: 'E1' });
    app.mount(el);
    return { draft, api, unmount: () => app.unmount() };
}

it('keeps a focus draft across a PIN-lock redirect and drops it when the view is closed', async () => {
    sessionStorage.clear();
    const values = { d: null, t: null };
    const open = ref(true);
    const a = mountDraft({ values }, open);
    a.draft.time = '10:00';
    await nextTick();
    expect(JSON.parse(sessionStorage.getItem('enotf-focus:E1:symptome') ?? 'null')).toEqual({ time: '10:00' }); // edits only
    const b = mountDraft({ values }, ref(true));
    expect(b.draft.time).toBe('10:00');
    open.value = false;
    await nextTick();
    expect(sessionStorage.getItem('enotf-focus:E1:symptome')).toBeNull();
    a.unmount();
    b.unmount();
});

it('saves only fields changed against the opening snapshot, not against the current value', async () => {
    sessionStorage.clear();
    const values: Record<string, unknown> = { d: '2026-07-01', t: null };
    const { draft, api, unmount } = mountDraft({ values }, ref(true));
    values.t = '10:00'; // another device sets the time while the view is open
    draft.date = '2026-06-30';
    expect(api.changed('date')).toBe(true);
    expect(api.changed('time')).toBe(false); // the untouched null must not erase the other device's 10:00
    unmount();
});

it('computes the age on the reference day and the address summary', () => {
    expect(ageOn('1990-05-10', '2026-05-09')).toBe(35);
    expect(ageOn('1990-05-10', '2026-05-10')).toBe(36);
    expect(ageOn(null, '2026-05-10')).toBeNull();
    expect(addressLine({ strasse: 'Hauptstr.', hnr: '1', ort: 'Musterstadt', ortsteil: 'Nord' })).toBe('Hauptstr. 1, Musterstadt-Nord');
    expect(addressLine({ strasse: null, hnr: null, ort: 'Musterstadt', ortsteil: null })).toBe('Musterstadt');
});

it('formats the legacy print rules: title per type, place line, vitals with units and bz conversion', () => {
    expect(printTitleKey(0)).toBe('enotf.print.title.transport');
    expect(printTitleKey(1)).toBe('enotf.print.title.na');
    expect(placeLine('Klinikum', { strasse: 'Hauptstr.', hnr: '1', ort: 'Musterstadt' })).toBe('Klinikum, Hauptstr. 1, Musterstadt');
    expect(placeLine(null, null)).toBe('');
    expect(vitalPrint('bz', 100, 'mmol/l')).toBe('5,6 mmol/l');
    expect(vitalPrint('bz', 100, 'mg/dl')).toBe('100 mg/dl');
    expect(vitalPrint('temp', 36.5, 'mg/dl')).toBe('36,5 °C');
    expect(vitalPrint('spo2', 'ng', 'mg/dl')).toBe('ng');
});
