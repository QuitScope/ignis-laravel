import { expect, it } from 'vitest';
import { diagLabel, diagSearch, insertAt } from '@/lib/enotfUi';

it('inserts a text block at the cursor (modes 0 and 2)', () => {
    expect(insertAt('ab', 1, 'X', 0)).toEqual({ value: 'aXb', cursor: 2 });
    expect(insertAt('Text', 4, 'ALLERGIEN:', 2)).toEqual({ value: 'Text\nALLERGIEN:\n', cursor: 16 });
    expect(insertAt('', 0, 'ALLERGIEN:', 2).value).toBe('ALLERGIEN:\n'); // no newline before an empty text
});

it('shows only the severity on the Trauma sheets', () => {
    expect(diagLabel(102, true)).toBe('mittel');
    expect(diagLabel(102)).toBe('Trauma Schädel-Hirn mittel');
    expect(diagLabel(201, true)).toBe('Verbrennung / Verbrühung');
});

it('finds a diagnosis with the step of its sheet', () => {
    expect(diagSearch('/ stemi')).toEqual([{ code: 12, label: 'ACS / STEMI', q: 2 }]);
    expect(diagSearch('thorax schwer')).toEqual([{ code: 133, label: 'Trauma Thorax schwer', q: 11 }]);
    expect(diagSearch(' ')).toEqual([]);
});
