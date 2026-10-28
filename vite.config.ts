import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import i18n from 'laravel-vue-i18n/vite';
import { colorMixFallback, untypedPropertyFallback } from './resources/js/lib/cefCss';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        vue({
            template: { transformAssetUrls: { base: null, includeAbsolute: false } },
        }),
        tailwindcss(),
        i18n(),
        {
            // After Vite's CSS minify (core plugin): Chromium 103 fallbacks (color-mix(), untyped @property), see resources/js/lib/cefCss.ts.
            name: 'cef-color-mix-fallback',
            apply: 'build',
            enforce: 'post',
            generateBundle(_, bundle) {
                for (const file of Object.values(bundle)) {
                    if (file.type === 'asset' && file.fileName.endsWith('.css')) file.source = untypedPropertyFallback(colorMixFallback(String(file.source)));
                }
            },
        },
    ],
    // FiveM's CEF is Chromium 103: Vite's default (baseline-widely-available) with Chrome lowered to 103. lightningcss
    // (Vite's CSS minifier) then rewrites e.g. `@media (width>=40rem)` to `min-width`. See docs/dev/cef.md.
    build: {
        target: ['chrome103', 'edge111', 'firefox114', 'safari16.4'],
        cssTarget: ['chrome103', 'edge111', 'firefox114', 'safari16.4'],
    },
    resolve: {
        alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
