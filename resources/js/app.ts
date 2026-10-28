import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import type { DefineComponent } from 'vue';
import { i18nVue } from 'laravel-vue-i18n';
import { applyTheme } from '@/lib/theme';
import { applyPageSkin } from '@/lib/pageSkin';
import AppLayout from '@/Layouts/AppLayout.vue';

router.on('navigate', (e) => {
    applyPageSkin(e.detail.page.component);
    applyTheme(e.detail.page.props.theme);
});

createInertiaApp({
    resolve: async (name) => {
        const pages = import.meta.glob<DefineComponent>(['./Pages/**/*.vue', '!./Pages/Calendar/Index.vue'], { eager: true });
        const lazy = import.meta.glob<DefineComponent>('./Pages/Calendar/Index.vue');
        const key = `./Pages/${name}.vue`;
        const page = pages[key] ?? (await lazy[key]());
        page.default.layout ??= AppLayout;
        return page;
    },
    setup({ el, App, props, plugin }) {
        applyPageSkin(props.initialPage.component);
        applyTheme((props.initialPage.props as { theme?: string }).theme);
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(i18nVue, {
                lang: (props.initialPage.props as { locale?: string }).locale ?? 'de',
                fallbackLang: 'de',
                resolve: async (lang: string) => {
                    const langs = import.meta.glob<{ default: Record<string, string> }>('../../lang/*.json');
                    const modules = import.meta.glob<{ default: Record<string, string> }>('../../modules/*/lang/*.json');
                    const own = await langs[`../../lang/${lang}.json`]();
                    const extra = await Promise.all(Object.entries(modules).filter(([path]) => path.endsWith(`/lang/${lang}.json`)).map(([, load]) => load()));
                    return { default: Object.assign({}, own.default, ...extra.map((m) => m.default)) };
                },
            })
            .mount(el);
    },
});
