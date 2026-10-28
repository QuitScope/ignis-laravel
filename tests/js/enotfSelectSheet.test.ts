import { createApp, h, nextTick, reactive } from 'vue';
import { expect, it, vi } from 'vitest';
import SelectSheet from '@/components/enotf/ui/SelectSheet.vue';
import { catalog } from '@/lib/enotfUi';

it('writes the catalog code through set and filters with the search field', async () => {
    const set = vi.fn();
    const form = reactive({ values: {}, set, readonly: false, invalid: [], conflicts: [] });
    const el = document.body.appendChild(document.createElement('div'));
    const app = createApp({ provide: { enotfForm: form }, render: () => h(SelectSheet, { field: 'transportziel', options: catalog('transportziel'), label: 'Versorgung' }) });
    app.mount(el);

    (el.querySelector('button') as HTMLButtonElement).click();
    await nextTick();
    const search = document.body.querySelector('.enotf-panel input') as HTMLInputElement; // 9 options -> search field
    search.value = 'rth';
    search.dispatchEvent(new Event('input'));
    await nextTick();
    const options = [...document.body.querySelectorAll('.enotf-panel [role="option"]')].slice(1); // first = "none"
    expect(options.map((o) => o.textContent?.trim())).toEqual(['Transport mit NA (RTH)']);
    (options[0] as HTMLButtonElement).click();
    expect(set).toHaveBeenCalledWith('transportziel', 22);
    await nextTick();
    expect(document.body.querySelector('.enotf-panel')).toBeNull();
    app.unmount();
    el.remove();
});
