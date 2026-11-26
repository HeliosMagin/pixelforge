import vue from '@vitejs/plugin-vue';
import autoprefixer from 'autoprefixer';
import laravel from 'laravel-vite-plugin';
import path from 'path';
import tailwindcss from 'tailwindcss';
import { defineConfig } from 'vite';

const vitePort = Number(process.env.VITE_PORT ?? 5173);

export default defineConfig({
    // Inside Sail the dev server must listen on all interfaces; the browser reaches it via localhost.
    server: {
        host: '0.0.0.0',
        port: vitePort,
        strictPort: true,
        hmr: { host: 'localhost', port: vitePort },
    },
    plugins: [
        laravel({
            input: ['resources/js/app.ts'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
        },
    },
    css: {
        postcss: {
            plugins: [tailwindcss, autoprefixer],
        },
    },
});
