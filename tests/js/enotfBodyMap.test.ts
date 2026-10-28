import { createApp, h, nextTick, reactive, ref } from 'vue';
import { expect, it, vi } from 'vitest';
import BodyMap from '@/components/enotf/ui/BodyMap.vue';
import { gcsSum, severityWrites } from '@/lib/enotfUi';

it('sums the GCS from the stored distances, null while a part is missing', () => {
    expect(gcsSum(0, 0, 0)).toBe(15);
    expect(gcsSum(3, 4, 5)).toBe(3);
    expect(gcsSum(0, null, 0)).toBeNull();
    expect(severityWrites('v_muster_t', 3, 1)).toEqual({ v_muster_t: 1, v_muster_t1: null }); // "Keine" clears the wound type
    expect(severityWrites('v_muster_t', 3, 3)).toEqual({ v_muster_t: null, v_muster_t1: null }); // tapped again = cleared
    expect(severityWrites('v_muster_t', null, 2)).toEqual({ v_muster_t: 2 });
});

it('writes nothing when opened; wound type only for an injury', async () => {
    const set = vi.fn();
    const form = reactive({ values: { v_muster_t: 3, v_muster_t1: 1 } as Record<string, unknown>, set, readonly: false, invalid: [], conflicts: [] });
    const wizard = { topic: ref('erweitern'), step: ref(1), open: vi.fn() };
    const el = document.body.appendChild(document.createElement('div'));
    const app = createApp({ provide: { enotfForm: form, enotfWizard: wizard }, render: () => h(BodyMap) });
    app.mount(el);
    await nextTick();
    expect(set).not.toHaveBeenCalled();
    expect(el.querySelector('rect[data-severity="3"]')?.getAttribute('aria-label')).toBe('enotf.ui.eb.region.v_muster_t');

    (el.querySelector('rect[data-severity="3"]') as SVGRectElement).dispatchEvent(new MouseEvent('click'));
    await nextTick();
    expect(set).not.toHaveBeenCalled();
    expect(el.querySelectorAll('[role="radiogroup"]')).toHaveLength(2); // severity + wound type (3 = Mittel)

    (el.querySelector('[role="radio"][data-code="1"]') as HTMLButtonElement).click();
    expect(set.mock.calls).toEqual([['v_muster_t', 1], ['v_muster_t1', null]]);
    app.unmount();
    el.remove();
});
