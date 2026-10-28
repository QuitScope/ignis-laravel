import { describe, expect, it } from 'vitest';
import { naNachfShown, reaPanel, releaseBlock, toggleExclusive } from '@/lib/enotfUi';

describe('enotf Abschluss', () => {
    it('rea tree: durchgeführt opens the details, nicht durchgeführt the reasons, keine nothing', () => {
        expect([reaPanel(2), reaPanel('4'), reaPanel(6), reaPanel(1), reaPanel(null)]).toEqual(['details', 'nicht', 'nicht', null, null]);
    });

    it('Besonderheiten "keine" (1) stands alone; NA-Nachforderung hidden on a physician protocol', () => {
        expect(toggleExclusive([2, 5], 1, [1])).toEqual([1]);
        expect(toggleExclusive([1], 3, [1])).toEqual([3]);
        expect([naNachfShown(1), naNachfShown('1'), naNachfShown(0), naNachfShown(null)]).toEqual([false, false, true, true]);
    });

    it('release gating: read only, right, fresh server check, open entries', () => {
        const ok = { readonly: false, canRelease: true, open: 0 };
        expect(releaseBlock(ok)).toBeNull();
        expect(releaseBlock({ ...ok, open: 2 })).toBe('open');
        expect(releaseBlock({ ...ok, open: null })).toBe('unchecked');
        expect(releaseBlock({ ...ok, canRelease: false })).toBe('forbidden');
        expect(releaseBlock({ ...ok, readonly: true, open: 3 })).toBe('readonly');
    });
});
