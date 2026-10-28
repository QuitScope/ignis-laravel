import { expect, it } from 'vitest';
import { loadoutDialogDefaults } from '@/lib/loadout';

const all = { title: 'A', compartment: 1, vehicle_type: null, sort_order: 4 };

it('pre-fills the filter for a new category only', () => {
    expect(loadoutDialogDefaults(null, 'RTW').vehicle_type).toBe('RTW');
    expect(loadoutDialogDefaults(null, '').vehicle_type).toBe('');
});

it('keeps an all-types category all-types while a filter is active', () => {
    expect(loadoutDialogDefaults(all, 'RTW')).toEqual({ title: 'A', compartment: 1, vehicle_type: '', sort_order: 4 });
    expect(loadoutDialogDefaults({ ...all, vehicle_type: 'NEF' }, 'RTW').vehicle_type).toBe('NEF');
});
