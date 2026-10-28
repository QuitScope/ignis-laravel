import { expect, it } from 'vitest';
import { initialValue, linesToList, payload, type ConfigField } from '@/lib/settingFields';

const fields: ConfigField[] = [
    { key: 'a.name', type: 'string', value: 'Wache' },
    { key: 'a.on', type: 'bool', value: true },
    { key: 'a.count', type: 'int', value: 5 },
    { key: 'a.tags', type: 'list', value: ['x', 'y'] },
    { key: 'a.secret', type: 'string', secret: true, is_set: true },
    { key: 'a.discord', type: 'string', value: '123', deletable_only: true },
];

it('starts with the stored values, secrets empty and clear-only unticked', () => {
    expect(Object.fromEntries(fields.map((f) => [f.key, initialValue(f)]))).toEqual({
        'a.name': 'Wache',
        'a.on': true,
        'a.count': 5,
        'a.tags': ['x', 'y'],
        'a.secret': '',
        'a.discord': false,
    });
    expect(initialValue({ key: 'k', type: 'int', value: null })).toBe('');
});

it('sends no blank secret and no unticked clear-only field', () => {
    const values = Object.fromEntries(fields.map((f) => [f.key, initialValue(f)]));
    expect(payload(fields, values)).toEqual({});

    expect(payload(fields, { ...values, 'a.secret': 'new', 'a.discord': true })).toEqual({ 'a.secret': 'new', 'a.discord': '' });
});

it('sends only the fields changed against their initial values', () => {
    const values = Object.fromEntries(fields.map((f) => [f.key, initialValue(f)]));

    expect(payload(fields, { ...values, 'a.name': 'Neu', 'a.tags': ['x', 'y', 'z'] })).toEqual({ 'a.name': 'Neu', 'a.tags': ['x', 'y', 'z'] });
    expect(payload(fields, { ...values, 'a.tags': ['x', 'y'] })).toEqual({});
});

it('splits a list text into trimmed lines', () => {
    expect(linesToList('a\r\n  b \n\n c')).toEqual(['a', 'b', 'c']);
    expect(linesToList('')).toEqual([]);
});

it('turns text typed into a list field into items only when the payload is built', () => {
    const list: ConfigField[] = [{ key: 'a.tags', type: 'list', value: [] }];
    let raw = '';
    for (const ch of 'a\nb') raw += ch;
    expect(payload(list, { 'a.tags': linesToList(raw) })).toEqual({ 'a.tags': ['a', 'b'] });

    expect(linesToList('foo bar')).toEqual(['foo bar']);
    expect(linesToList('foo ')).toEqual(['foo']);
    expect(linesToList('a\n')).toEqual(['a']);
});
