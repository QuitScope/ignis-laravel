import { expect, it } from 'vitest';
import { duiKeyAction, isDuiKey } from '@/lib/duiKeys';

const input = { tagName: 'INPUT' };
const key = (k: string, mods: { ctrl?: boolean; shift?: boolean } = {}) => ({
    type: 'efKey' as const,
    key: k,
    ...mods,
});

it('accepts only efKey messages with a string key', () => {
    expect(isDuiKey(key('a'))).toBe(true);
    expect(isDuiKey({ type: 'efKey', key: 1 })).toBe(false);
    expect(isDuiKey({ type: 'other', key: 'a' })).toBe(false);
    expect(isDuiKey(null)).toBe(false);
});

it('maps keys like the legacy dui-keys helper', () => {
    expect(duiKeyAction(key('Tab'), null)).toEqual({ do: 'focus', step: 1 });
    expect(duiKeyAction(key('Tab', { shift: true }), input)).toEqual({
        do: 'focus',
        step: -1,
    });
    expect(duiKeyAction(key('ArrowDown'), { tagName: 'SELECT' })).toEqual({
        do: 'option',
        step: 1,
    });
    expect(duiKeyAction(key('Enter'), { tagName: 'BUTTON' })).toEqual({
        do: 'click',
    });
    expect(duiKeyAction(key('x'), { tagName: 'DIV' })).toBeNull();
    expect(duiKeyAction(key('x'), input)).toEqual({
        do: 'exec',
        command: 'insertText',
        text: 'x',
    });
    expect(duiKeyAction(key('A', { ctrl: true }), input)).toEqual({
        do: 'selectAll',
    });
    expect(duiKeyAction(key('v', { ctrl: true }), input)).toBeNull();
    expect(duiKeyAction(key('Backspace'), input)).toEqual({
        do: 'exec',
        command: 'delete',
    });
    expect(duiKeyAction(key('Delete'), input)).toEqual({
        do: 'exec',
        command: 'forwardDelete',
    });
    expect(duiKeyAction(key('Enter'), input)).toEqual({ do: 'submit' });
    expect(duiKeyAction(key('Enter'), { tagName: 'TEXTAREA' })).toEqual({
        do: 'exec',
        command: 'insertText',
        text: '\n',
    });
    expect(duiKeyAction(key('Enter'), { tagName: 'DIV', isContentEditable: true })).toEqual({ do: 'exec', command: 'insertText', text: '\n' });
    expect(duiKeyAction(key('ArrowLeft'), input)).toEqual({
        do: 'caret',
        step: -1,
    });
});
