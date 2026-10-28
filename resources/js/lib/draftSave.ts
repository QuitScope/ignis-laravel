/**
 * Saving an editor draft (POST {content: JSON string, title, lock_version, autosave} -> {lock_version, warnings}).
 * Kept apart from the editor chunk: the page needs it even when leaving, after the editor is gone.
 */

export const KEEPALIVE_LIMIT = 64 * 1024;

export type DraftPayload = { content: string; title: string; lock_version: number; autosave: boolean };

export function xsrfToken(cookie: string = document.cookie): string {
    return decodeURIComponent(cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '');
}

export function draftRequest(payload: DraftPayload, keepalive = false): RequestInit {
    return {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
        body: JSON.stringify(payload),
        keepalive,
    };
}

export function sendFinalSave(url: string, payload: DraftPayload, send: typeof fetch = fetch): boolean {
    const body = JSON.stringify(payload);
    const keepalive = new TextEncoder().encode(body).length <= KEEPALIVE_LIMIT;
    try {
        send(url, draftRequest(payload, keepalive)).catch(() => {});
    } catch {
        return false;
    }

    return keepalive;
}
