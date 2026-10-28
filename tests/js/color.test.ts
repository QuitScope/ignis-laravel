import { expect, it } from 'vitest';
import { colorWarnings, contrastRatio, hexToRgb, looksLikeDanger } from '@/lib/color';

it('parses #rrggbb only', () => {
    expect(hexToRgb('#F0500A')).toEqual([240, 80, 10]);
    expect(hexToRgb('f0500a')).toBeNull();
    expect(hexToRgb('#fff')).toBeNull();
    expect(hexToRgb('#gggggg')).toBeNull();
});

it('computes the WCAG contrast ratio', () => {
    expect(contrastRatio('#000000', '#ffffff')).toBeCloseTo(21, 5);
    expect(contrastRatio('#ffffff', '#ffffff')).toBeCloseTo(1, 5);
    expect(contrastRatio('#777777', '#ffffff')).toBeCloseTo(4.48, 1);
    expect(contrastRatio('nope', '#ffffff')).toBeNull();
});

it('flags reds like the legacy check and leaves other hues alone', () => {
    expect(looksLikeDanger('#dc2626')).toBe(true);
    expect(looksLikeDanger('#ef4444')).toBe(true);
    expect(looksLikeDanger('#f0500a')).toBe(false); // the stock orange
    expect(looksLikeDanger('#2563eb')).toBe(false);
    expect(looksLikeDanger('#808080')).toBe(false); // no chroma
    expect(looksLikeDanger('red')).toBe(false);
});

it('warns about low contrast to white and to the dark background', () => {
    expect(colorWarnings('#f0500a')).toEqual([]);
    expect(colorWarnings('#ffff00')).toEqual(['white']);
    expect(colorWarnings('#101010')).toEqual(['dark']);
    expect(colorWarnings('#dc2626')).toEqual(['danger']);
    expect(colorWarnings('#12')).toEqual([]);
});
