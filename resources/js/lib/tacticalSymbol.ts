import { SYMBOL_KEYS, type SymbolConfig, type SymbolKey } from './tacticalSymbolOptions';

export type SymbolValues = Record<SymbolKey, string>;
export type SymbolTemplate = { id: number; name: string; symbol: Record<string, unknown> };

/** Only the known keys with non-empty text; whatever else a stored symbol holds never reaches the library. */
export function cleanSymbol(symbol: unknown): SymbolConfig {
    const out: SymbolConfig = {};
    if (symbol === null || typeof symbol !== 'object') return out;
    for (const key of SYMBOL_KEYS) {
        const value = (symbol as Record<string, unknown>)[key];
        if (typeof value === 'string' && value.trim() !== '') out[key] = value.trim();
    }
    return out;
}

export async function renderSymbol(symbol: unknown, name?: string | null): Promise<string | null> {
    const config = cleanSymbol(symbol);
    if (!config.grundzeichen && !config.symbol) return null;
    const { erzeugeTaktischesZeichen } = await import('taktische-zeichen-core');
    const svg = erzeugeTaktischesZeichen({ ...config, ...(name ? { name } : {}) } as never).toString();
    return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
}

export const emptySymbol = (symbol: unknown = null): SymbolValues => ({
    grundzeichen: '', organisation: '', fachaufgabe: '', einheit: '', symbol: '', typ: '', text: '', ...cleanSymbol(symbol),
});
