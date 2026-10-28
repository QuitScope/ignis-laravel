import { trans } from 'laravel-vue-i18n';
import { xsrfToken } from '@/lib/draftSave';

export type ApiResult<T> = { ok: true; data: T } | { ok: false; status: number; message: string; errors: Record<string, string> };

export function fieldErrors(errors: unknown): Record<string, string> {
    const out: Record<string, string> = {};
    if (typeof errors !== 'object' || errors === null) return out;
    for (const [key, value] of Object.entries(errors)) {
        const field = key.replace(/\.\d+$/, '');
        const msg = Array.isArray(value) ? String(value[0] ?? '') : String(value);
        if (!(field in out)) out[field] = msg;
    }

    return out;
}

/**
 * JSON request of the calendar (fetch, so the XSRF header is added by hand). Failures come back as a message:
 * session expiry (401/419), the server's validation errors (per field) or a generic text. An aborted read throws
 * the AbortError so the caller can drop stale answers.
 */
export async function calendarRequest<T = Record<string, unknown>>(method: 'GET' | 'POST' | 'PUT' | 'DELETE', url: string, body?: unknown, signal?: AbortSignal): Promise<ApiResult<T>> {
    try {
        const res = await fetch(url, {
            method,
            signal,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', ...(body === undefined ? {} : { 'Content-Type': 'application/json' }), ...(method === 'GET' ? {} : { 'X-XSRF-TOKEN': xsrfToken() }) },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        if (res.ok) return { ok: true, data: (res.status === 204 ? {} : await res.json().catch(() => ({}))) as T };
        if (res.status === 401 || res.status === 419) return { ok: false, status: res.status, message: trans('calendar.error.session'), errors: {} };
        const json = await res.json().catch(() => null);
        const errors = fieldErrors(json?.errors);
        const message = Object.values(errors)[0] ?? (typeof json?.message === 'string' ? json.message : trans('calendar.error.generic'));

        return { ok: false, status: res.status, message, errors };
    } catch (e) {
        if (e instanceof DOMException && e.name === 'AbortError') throw e;

        return { ok: false, status: 0, message: trans('calendar.error.generic'), errors: {} };
    }
}
