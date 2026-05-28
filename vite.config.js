import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/filament.css',
                'resources/js/bootstrap.ts',
                'resources/js/islands/novnc-viewer.ts',
                'resources/js/islands/xterm-console.ts',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
