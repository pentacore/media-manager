import { createInertiaApp } from '@inertiajs/vue3';
import { appName, resolveLayout } from '@/inertia';

// The Inertia Vite plugin swaps this statement (void included) for its SSR server bootstrap.
void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: resolveLayout,
});
