import { xsrfToken } from '@/lib/draftSave';

export async function rowRequest<T>(url: string, method: 'POST' | 'DELETE', body?: unknown): Promise<{ ok: true; data: T } | { ok: false; message: string }> {
    const res = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
        body: body === undefined ? undefined : JSON.stringify(body),
    }).catch(() => null);
    const data = (await res?.json().catch(() => null)) as (T & { message?: string; errors?: Record<string, string[]> }) | null;
    if (res?.ok && data) return { ok: true, data };
    const first = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
    return { ok: false, message: first ?? data?.message ?? '' };
}
