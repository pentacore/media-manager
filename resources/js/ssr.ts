import { createInertiaApp } from '@inertiajs/vue3';
import { appName, resolveLayout } from '@/inertia';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: resolveLayout,
});
