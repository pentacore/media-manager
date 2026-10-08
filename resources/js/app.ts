import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import { appName, resolveLayout } from '@/inertia';
import { initializeFlashToast } from '@/lib/flashToast';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: resolveLayout,
    progress: {
        color: '#4B5563',
    },
    // Inertia keeps a prefetched page 30 s by default and does not drop it
    // after a write, so a page hovered before an approve or delete could be
    // shown stale on click. 10 s still covers hover-then-click.
    defaults: {
        prefetch: {
            cacheFor: '10s',
        },
    },
});

initializeTheme();

initializeFlashToast();
