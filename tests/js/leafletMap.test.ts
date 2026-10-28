import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { diffById, inMap, latLngToPercent, loadView, percentToLatLng, saveView } from '@/lib/leafletMap';

describe('coordinates', () => {
    it('maps percent to CRS.Simple units and back (legacy: lng 0..256, lat 0..-256)', () => {
        expect(percentToLatLng({ x: 0, y: 0 })).toEqual({ lat: -0, lng: 0 });
        expect(percentToLatLng({ x: 100, y: 100 })).toEqual({ lat: -256, lng: 256 });
        expect(percentToLatLng({ x: 25, y: 50 })).toEqual({ lat: -128, lng: 64 });
        expect(latLngToPercent({ lat: -128, lng: 64 })).toEqual({ x: 25, y: 50 });
        const p = { x: 12.34, y: 56.78 };
        expect(latLngToPercent(percentToLatLng(p))).toEqual(p);
    });

    it('checks the map bounds', () => {
        expect(inMap({ x: 0, y: 100 })).toBe(true);
        expect(inMap({ x: 100.01, y: 5 })).toBe(false);
        expect(inMap({ x: NaN, y: 5 })).toBe(false);
    });
});

describe('diffById', () => {
    it('only touches added, changed and removed items', () => {
        const a = { id: 1, v: 'a' };
        const b = { id: 2, v: 'b' };
        const prev = new Map([[1, JSON.stringify(a)], [2, JSON.stringify(b)], [3, 'gone']]);
        const d = diffById(prev, [a, { id: 2, v: 'changed' }, { id: 4, v: 'new' }]);
        expect(d.add.map((t) => t.id)).toEqual([4]);
        expect(d.update.map((t) => t.id)).toEqual([2]);
        expect(d.remove).toEqual([3]);
    });
});

describe('saved view', () => {
    it('stores per incident and map type, survives blocked storage', () => {
        const mem = new Map<string, string>();
        const store = { getItem: (k: string) => mem.get(k) ?? null, setItem: (k: string, v: string) => void mem.set(k, v) };
        saveView(7, 'hybrid', { lat: -10, lng: 20, zoom: 3 }, store);
        expect(loadView(7, 'hybrid', store)).toEqual({ lat: -10, lng: 20, zoom: 3 });
        expect(loadView(7, 'satellite', store)).toBeNull();
        expect(loadView(8, 'hybrid', store)).toBeNull();
        mem.set('lagekarte.view.7.roadmap', '{broken');
        expect(loadView(7, 'roadmap', store)).toBeNull();
        expect(() => saveView(1, 'x', { lat: 0, lng: 0, zoom: 1 }, { setItem: () => { throw new Error('blocked'); } })).not.toThrow();
        expect(loadView(1, 'x', null)).toBeNull();
    });
});

describe('no raw HTML sinks in the map code', () => {
    const files = [
        ...readdirSync('resources/js/components/firetab/map').map((f) => join('resources/js/components/firetab/map', f)),
        'resources/js/lib/leafletMap.ts',
        'resources/js/lib/tacticalSymbol.ts',
        'resources/js/Pages/FireTab/Map.vue',
    ];
    it.each(files)('%s', (file) => {
        const code = readFileSync(file, 'utf8');
        expect(code).not.toMatch(/innerHTML|outerHTML|insertAdjacentHTML|v-html|document\.write/);
    });
});
