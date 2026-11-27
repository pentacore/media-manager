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

/**
 * The payload url comes from the server but is only ever followed within this
 * origin; anything unparsable or cross-origin falls back to the app root.
 */
function resolveTargetUrl(raw) {
    if (typeof raw !== 'string') {
        return '/';
    }

    try {
        const url = new URL(raw, self.location.origin);

        return url.origin === self.location.origin ? url.href : '/';
    } catch {
        return '/';
    }
}

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const target = resolveTargetUrl(
        event.notification.data && event.notification.data.url,
    );

    event.waitUntil(
        (async () => {
            const windows = await self.clients.matchAll({
                type: 'window',
                includeUncontrolled: true,
            });
            const sameOrigin = windows.find((client) => {
                try {
                    return new URL(client.url).origin === self.location.origin;
                } catch {
                    return false;
                }
            });

            if (sameOrigin) {
                try {
                    await sameOrigin.focus();
                    await sameOrigin.navigate(target);

                    return;
                } catch {
                    // navigate() rejects for uncontrolled windows; open instead.
                }
            }

            await self.clients.openWindow(target);
        })(),
    );
});
