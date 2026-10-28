import { describe, expect, it, vi } from 'vitest';
import { erzeugeTaktischesZeichen } from 'taktische-zeichen-core';
import { cleanSymbol, renderSymbol } from '@/lib/tacticalSymbol';
import { SYMBOL_SELECTS, symbolOptions } from '@/lib/tacticalSymbolOptions';

describe('tactical symbol options', () => {
    for (const key of SYMBOL_SELECTS) {
        it(`${key}: every option renders`, () => {
            expect(symbolOptions[key].length).toBeGreaterThan(0);
            for (const { value } of symbolOptions[key]) {
                const spec = key === 'grundzeichen' ? { grundzeichen: value } : { grundzeichen: 'kraftfahrzeug-landgebunden', [key]: value };
                expect(() => erzeugeTaktischesZeichen(spec as never), `${key}=${value}`).not.toThrow();
            }
        });
    }

    it('rejects an unknown key', () => {
        expect(() => erzeugeTaktischesZeichen({ grundzeichen: 'gibt-es-nicht' as never })).toThrow();
    });
});

describe('rendering', () => {
    it('keeps only the known keys with text', () => {
        expect(cleanSymbol({ grundzeichen: ' fahrzeug ', organisation: '', evil: 'x', text: 5 })).toEqual({ grundzeichen: 'fahrzeug' });
        expect(cleanSymbol(null)).toEqual({});
        expect(cleanSymbol('fahrzeug')).toEqual({});
    });

    it('draws a symbol as an image data URL, never as markup', async () => {
        const url = await renderSymbol({ grundzeichen: 'kraftfahrzeug-landgebunden', organisation: 'feuerwehr', text: 'LF 20 Ä' }, 'Florian');
        expect(url).toMatch(/^data:image\/svg\+xml;charset=utf-8,/);
        expect(decodeURIComponent(url!.split(',')[1])).toContain('<svg');
    });

    it('does not log to the console while rendering', async () => {
        const spies = (['log', 'info', 'warn', 'debug'] as const).map((m) => vi.spyOn(console, m).mockImplementation(() => {}));
        await renderSymbol({ grundzeichen: 'kraftfahrzeug-landgebunden', text: 'LF' }, 'N');
        spies.forEach((s) => expect(s).not.toHaveBeenCalled());
        spies.forEach((s) => s.mockRestore());
    });

    it('has nothing to draw without a Grundzeichen or Symbol', async () => {
        expect(await renderSymbol(null)).toBeNull();
        expect(await renderSymbol({ organisation: 'thw' })).toBeNull();
    });

    it('rejects an unknown value so the preview can show its placeholder', async () => {
        await expect(renderSymbol({ grundzeichen: 'gibt-es-nicht' })).rejects.toThrow();
        await expect(renderSymbol({ grundzeichen: 'fahrzeug', einheit: 'verband' })).rejects.toThrow();
    });
});
