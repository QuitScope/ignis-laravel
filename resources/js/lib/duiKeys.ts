import { onBeforeUnmount, onMounted } from 'vue';

/**
 * Keyboard for the tablets in FiveM's DUI (port of legacy assets/js/dui-keys.js). A DUI gets only mouse input from the
 * game; ef_bridge sends keys with SendDuiMessage as {type: 'efKey', key, ctrl, shift} (client/dui.lua). execCommand so
 * input events fire as with real typing (v-model follows). Top window only (the NUI iframe has real keys) and only
 * messages from this window: an opener or frame must not inject keys.
 */
export type DuiKey = {
    type: 'efKey';
    key: string;
    ctrl?: boolean;
    shift?: boolean;
};

export type DuiAction =
    | { do: 'focus'; step: 1 | -1 }
    | { do: 'option'; step: 1 | -1 }
    | { do: 'click' }
    | { do: 'selectAll' }
    | {
          do: 'exec';
          command: 'insertText' | 'delete' | 'forwardDelete';
          text?: string;
      }
    | { do: 'submit' }
    | { do: 'caret'; step: 1 | -1 };

export function isDuiKey(m: unknown): m is DuiKey {
    return typeof m === 'object' && m !== null && (m as DuiKey).type === 'efKey' && typeof (m as DuiKey).key === 'string';
}

type Target = { tagName: string; isContentEditable?: boolean } | null;

export function duiKeyAction(m: DuiKey, el: Target): DuiAction | null {
    const tag = el?.tagName ?? '';
    if (m.key === 'Tab') return { do: 'focus', step: m.shift ? -1 : 1 };
    if (tag === 'SELECT' && (m.key === 'ArrowDown' || m.key === 'ArrowUp')) return { do: 'option', step: m.key === 'ArrowDown' ? 1 : -1 };

    const multiline = tag === 'TEXTAREA' || el?.isContentEditable === true;
    if (!multiline && tag !== 'INPUT') {
        return m.key === 'Enter' && (tag === 'BUTTON' || tag === 'A') ? { do: 'click' } : null;
    }
    if (m.ctrl) return m.key.toLowerCase() === 'a' ? { do: 'selectAll' } : null;
    if (m.key.length === 1) return { do: 'exec', command: 'insertText', text: m.key };
    if (m.key === 'Backspace') return { do: 'exec', command: 'delete' };
    if (m.key === 'Delete') return { do: 'exec', command: 'forwardDelete' };
    if (m.key === 'Enter') return multiline ? { do: 'exec', command: 'insertText', text: '\n' } : { do: 'submit' };
    if (m.key === 'ArrowLeft' || m.key === 'ArrowRight') return { do: 'caret', step: m.key === 'ArrowLeft' ? -1 : 1 };

    return null;
}

const FOCUSABLE = 'input, select, textarea, button, a[href], [tabindex]:not([tabindex="-1"])';

function apply(action: DuiAction, el: HTMLElement | null): void {
    switch (action.do) {
        case 'focus': {
            const all = [...document.querySelectorAll<HTMLElement>(FOCUSABLE)].filter(
                (e) => !(e as HTMLInputElement).disabled && e.offsetParent !== null,
            );
            const i = el ? all.indexOf(el) : -1;
            all[(i + action.step + all.length) % all.length]?.focus();
            return;
        }
        case 'option': {
            const s = el as HTMLSelectElement;
            s.selectedIndex = Math.max(0, Math.min(s.options.length - 1, s.selectedIndex + action.step));
            s.dispatchEvent(new Event('change', { bubbles: true }));
            return;
        }
        case 'click':
            el?.click();
            return;
        case 'selectAll':
            if (el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement) el.select();
            else document.execCommand('selectAll');
            return;
        case 'exec':
            document.execCommand(action.command, false, action.text);
            return;
        case 'submit':
            (el as HTMLInputElement).form?.requestSubmit();
            return;
        case 'caret':
            try {
                const input = el as HTMLInputElement;
                const p = Math.max(0, (input.selectionStart ?? 0) + action.step);
                input.setSelectionRange(p, p);
            } catch {
            }
    }
}

function onMessage(e: MessageEvent): void {
    if (!isDuiKey(e.data) || (e.source !== null && e.source !== window)) return;
    const el = document.activeElement as HTMLElement | null;
    const action = duiKeyAction(e.data, el);
    if (action) apply(action, el);
}

export function useDuiKeys(): void {
    onMounted(() => {
        if (window.top === window) window.addEventListener('message', onMessage);
    });
    onBeforeUnmount(() => window.removeEventListener('message', onMessage));
}
