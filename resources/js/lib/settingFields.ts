// The form side of the settings registry (SystemConfigController::field): what a field starts with and what a save sends.

export type ConfigField = {
    key: string;
    type: 'string' | 'bool' | 'int' | 'enum' | 'color' | 'url' | 'list';
    secret?: boolean;
    is_set?: boolean;
    value?: unknown;
    options?: string[];
    deletable_only?: boolean;
};

export type FieldValue = string | number | boolean | string[];

export function initialValue(f: ConfigField): FieldValue {
    if (f.secret) return '';
    if (f.deletable_only) return false;
    if (f.type === 'bool') return f.value === true;
    if (f.type === 'list') return Array.isArray(f.value) ? (f.value as string[]) : [];
    if (f.type === 'int') return typeof f.value === 'number' ? f.value : '';

    return typeof f.value === 'string' ? f.value : '';
}

export function payload(fields: ConfigField[], values: Record<string, FieldValue>): Record<string, unknown> {
    const body: Record<string, unknown> = {};
    for (const f of fields) {
        const v = values[f.key];
        if (!f.secret && !f.deletable_only && JSON.stringify(v) === JSON.stringify(initialValue(f))) continue;
        if (f.secret) {
            if (v !== '') body[f.key] = v;
        } else if (f.deletable_only) {
            if (v === true) body[f.key] = '';
        } else {
            body[f.key] = v;
        }
    }

    return body;
}

export const linesToList = (text: string): string[] => text.split(/\r?\n/).map((l) => l.trim()).filter((l) => l !== '');
