/* Web Push service worker. Registered only from the notification settings
   page once a user enables push on a device. It has no fetch handler, so it
   never intercepts or caches page loads. */

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', (event) => {
    let payload = {};

    try {
        payload = event.data ? event.data.json() : {};
    } catch {
        payload = { title: 'MediaManager', body: event.data ? event.data.text() : '' };
    }

    const title = payload.title || 'MediaManager';

    event.waitUntil(
        self.registration.showNotification(title, {
            body: payload.body || '',
            tag: payload.tag || undefined,
            data: { url: payload.url || '/' },
            icon: '/icons/icon-192.png',
            badge: '/icons/icon-192.png',
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = (event.notification.data && event.notification.data.url) || '/';

    event.waitUntil(
        (async () => {
            const windows = await self.clients.matchAll({
                type: 'window',
                includeUncontrolled: true,
            });
            const sameOrigin = windows.find(
                (client) => new URL(client.url).origin === self.location.origin,
            );

            if (sameOrigin) {
                await sameOrigin.focus();
                await sameOrigin.navigate(url);

                return;
            }

            await self.clients.openWindow(url);
        })(),
    );
});
