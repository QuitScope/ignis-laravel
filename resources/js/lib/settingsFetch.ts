import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { xsrfToken } from '@/lib/draftSave';

/**
 * JSON write of an admin page (pattern of KnowledgeBase/Taxonomy.vue). Returns the response body on success, or the
 * message to show in the dialog: session expiry (401/419), the first validation error, the server's own message.
 * `reload` names the props to refresh after a success.
 */
export async function settingsSend(
    method: 'POST' | 'PUT' | 'PATCH' | 'DELETE',
    url: string,
    body: unknown,
    messages: { session: string; save: string },
    reload: string[] = [],
): Promise<{ ok: true; data: Record<string, unknown> } | { ok: false; message: string }> {
    try {
        const res = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', ...(body === undefined ? {} : { 'Content-Type': 'application/json' }), 'X-XSRF-TOKEN': xsrfToken() },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        if (res.ok) {
            const data = (await res.json().catch(() => ({}))) as Record<string, unknown>;
            if (reload.length > 0) router.reload({ only: reload });

            return { ok: true, data };
        }
        if (res.status === 401 || res.status === 419) return { ok: false, message: trans(messages.session) };
        const json = await res.json().catch(() => null);
        const first = json?.errors ? (Object.values(json.errors)[0] as string[])[0] : null;

        return { ok: false, message: first ?? json?.message ?? trans(messages.save) };
    } catch {
        return { ok: false, message: trans(messages.save) };
    }
}
