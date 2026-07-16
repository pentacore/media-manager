import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import typefinder from '@pentacore/vite-plugin-laravel-typefinder';
import { defineConfig } from 'vite';

// Set in containerized/CI builds where wayfinder + typefinder types are produced ahead of time
// and PHP/artisan aren't available to the Vite build itself. Locally unset, so dev/build keep
// regenerating types automatically.
const skipTypeGeneration = process.env.SKIP_TYPE_GENERATION === '1';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        inertia({
            ssr: {
                entry: 'resources/js/ssr.ts',
                host: '0.0.0.0',
                port: 13714,
            },
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
            // No skip flag in the wayfinder plugin — point its command at the shell no-op when
            // types are already on disk. Args still get appended; `:` ignores them.
            ...(skipTypeGeneration ? { command: ':' } : {}),
        }),
        typefinder(skipTypeGeneration ? { buildCommand: false } : {}),
    ],
});
