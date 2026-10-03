import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import bitrix24UIPlugin from '@bitrix24/b24ui-nuxt/vite';

export default defineConfig({
    base: '/build/',
    envDir: false,
    publicDir: false,
    plugins: [vue(), bitrix24UIPlugin({ router: false })],
    build: {
        outDir: 'public/build',
        manifest: true,
        rollupOptions: { input: 'assets/main.js' },
    },
});
