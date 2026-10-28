import options from './tacticalSymbolOptions.json';

// One source of truth with the server allowlist (App\Vehicles\TacticalSymbol reads the same JSON).
export type SymbolOption = { value: string; label: string };
export type SymbolKey = 'grundzeichen' | 'organisation' | 'fachaufgabe' | 'einheit' | 'symbol' | 'typ' | 'text';
export type SymbolConfig = Partial<Record<SymbolKey, string>>;

export const SYMBOL_KEYS: SymbolKey[] = ['grundzeichen', 'organisation', 'fachaufgabe', 'einheit', 'symbol', 'typ', 'text'];
export const SYMBOL_SELECTS =['grundzeichen', 'organisation', 'fachaufgabe', 'einheit', 'symbol'] as const;
export const SYMBOL_TEXTS = ['typ', 'text'] as const;
export const symbolOptions: Record<(typeof SYMBOL_SELECTS)[number], SymbolOption[]> = options;
