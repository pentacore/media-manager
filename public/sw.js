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
            // Without this, a notification that reuses a tag updates silently.
            renotify: Boolean(payload.tag),
            data: { url: payload.url || '/' },
            icon: '/icons/icon-192.png',
            badge: '/icons/badge-96.png',
        }),
    );
});

/**
 * The payload url comes from the server but is only ever followed within this
 * origin. APP_URL may differ from the origin the phone uses, so another
 * http(s) origin keeps its path on this one; anything unparsable or not
 * http(s) falls back to the app root.
 */
function resolveTargetUrl(raw) {
    if (typeof raw !== 'string') {
        return '/';
    }

    try {
        const url = new URL(raw, self.location.origin);

        if (url.protocol !== 'http:' && url.protocol !== 'https:') {
            return '/';
        }

        return url.origin === self.location.origin
            ? url.href
            : self.location.origin + url.pathname + url.search + url.hash;
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
