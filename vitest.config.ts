import { fileURLToPath, URL } from 'node:url';
import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

// Frontend unit tests under tests/js (editor schema, calendar page smoke); the app build uses vite.config.ts.
export default defineConfig({
    plugins: [vue()], // the calendar page smoke test mounts a .vue file
    resolve: {
        alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) },
    },
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.ts'],
        testTimeout: 20000, // the first mount of a page imports and transforms it; 5 s flakes on a busy machine
    },
});
