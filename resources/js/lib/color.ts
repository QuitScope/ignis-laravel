// Accent colour checks for the system configuration (legacy Theme::accentLooksLikeDanger plus a contrast hint).

export type Rgb = [number, number, number];

export const DARK_BACKGROUND = '#0b0b0b';
export const WHITE = '#ffffff';

export function hexToRgb(hex: string): Rgb | null {
    const m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex.trim());

    return m ? [parseInt(m[1], 16), parseInt(m[2], 16), parseInt(m[3], 16)] : null;
}

const linear = (channel: number): number => {
    const c = channel / 255;

    return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
};

export function luminance([r, g, b]: Rgb): number {
    return 0.2126 * linear(r) + 0.7152 * linear(g) + 0.0722 * linear(b);
}

export function contrastRatio(a: string, b: string): number | null {
    const x = hexToRgb(a);
    const y = hexToRgb(b);
    if (!x || !y) return null;
    const [hi, lo] = [luminance(x), luminance(y)].sort((p, q) => q - p);

    return (hi + 0.05) / (lo + 0.05);
}

export function looksLikeDanger(hex: string): boolean {
    const rgb = hexToRgb(hex);
    if (!rgb) return false;
    const [r, g, b] = rgb.map(linear);
    const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
    const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
    const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);
    const a = 1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s;
    const bb = 0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s;
    const hue = ((Math.atan2(bb, a) * 180) / Math.PI + 360) % 360;

    return Math.hypot(a, bb) >= 0.1 && Math.abs(hue - 22) <= 12;
}

export const MIN_CONTRAST = 3;

export function colorWarnings(hex: string): ('danger' | 'white' | 'dark')[] {
    if (!hexToRgb(hex)) return [];
    const warnings: ('danger' | 'white' | 'dark')[] = [];
    if (looksLikeDanger(hex)) warnings.push('danger');
    if ((contrastRatio(hex, WHITE) ?? 21) < MIN_CONTRAST) warnings.push('white');
    if ((contrastRatio(hex, DARK_BACKGROUND) ?? 21) < MIN_CONTRAST) warnings.push('dark');

    return warnings;
}
