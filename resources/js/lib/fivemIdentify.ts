import { usePage } from '@inertiajs/vue3';
import { onMounted } from 'vue';

/** ef_bridge's NUI origin (FiveM serves a resource's ui_page at https://cfx-nui-<resource>); a renamed resource breaks identify. */
export const EF_BRIDGE_ORIGIN = 'https://cfx-nui-ef_bridge';

/**
 * Hands the page's one-time identify token (shared prop `fivem.identifyToken`, only signed in inside FiveM's CEF) to
 * ef_bridge's NUI (html/js/master.js), which redeems it with the character at api/character/identify. Same message as
 * the legacy pages: to the parent (NUI iframe) or to this window (DUI); the field is still called `session_id`, but it
 * is no session id. Once per page; ef_bridge skips a token it already linked. The target origin is
 * pinned: a frame parent that is not ef_bridge's NUI (another resource, a website) never gets the token.
 */
export function useFiveMIdentify(): void {
    const page = usePage<{ fivem?: { identifyToken?: string | null } }>();
    onMounted(() => {
        const token = page.props.fivem?.identifyToken;
        if (!token) return;
        const message = { type: 'ignis_session', session_id: token };
        if (window.parent !== window) window.parent.postMessage(message, EF_BRIDGE_ORIGIN);
        else window.postMessage(message, window.location.origin);
    });
}
