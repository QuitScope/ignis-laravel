import { h, type VNode } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';

/**
 * Signed-in accounts get the app layout, guests (the server sets `noindex` for them) the guest layout. No props are
 * passed on: both layouts read what they need with usePage(), and props on a layout would become DOM attributes.
 */
export const kbLayout = (render: typeof h, page: VNode) => render((page.props as Record<string, unknown> | null)?.noindex ? GuestLayout : AppLayout, null, () => page);
