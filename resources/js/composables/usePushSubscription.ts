import { router } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { destroy, store } from '@/routes/settings/push-subscriptions';

export type PushPermission = NotificationPermission | 'unsupported';

export type UsePushSubscriptionReturn = {
    supported: Ref<boolean>;
    permission: Ref<PushPermission>;
    /** This browser's push endpoint, or null when it is not subscribed. */
    endpoint: Ref<string | null>;
    /** Hex SHA-256 of `endpoint`, matched against the server's device list. */
    endpointHash: Ref<string | null>;
    busy: Ref<boolean>;
    /** Why the last enable() failed, or null. */
    error: Ref<string | null>;
    enable: () => Promise<void>;
    disable: (deviceId: number | null) => Promise<void>;
};

const SERVICE_WORKER_URL = '/sw.js';

function urlBase64ToBytes(value: string): Uint8Array<ArrayBuffer> {
    const padded = value + '='.repeat((4 - (value.length % 4)) % 4);
    const raw = atob(padded.replace(/-/g, '+').replace(/_/g, '/'));
    const bytes = new Uint8Array(new ArrayBuffer(raw.length));

    for (let i = 0; i < raw.length; i++) {
        bytes[i] = raw.charCodeAt(i);
    }

    return bytes;
}

function sameKey(existing: ArrayBuffer | null, expected: Uint8Array): boolean {
    // A subscription that does not expose its key cannot be compared; keep it.
    if (existing === null) {
        return true;
    }

    const bytes = new Uint8Array(existing);

    return (
        bytes.length === expected.length &&
        bytes.every((byte, index) => byte === expected[index])
    );
}

async function sha256Hex(value: string): Promise<string | null> {
    if (typeof crypto === 'undefined' || !crypto.subtle) {
        return null;
    }

    const digest = await crypto.subtle.digest(
        'SHA-256',
        new TextEncoder().encode(value),
    );

    return Array.from(new Uint8Array(digest), (byte) =>
        byte.toString(16).padStart(2, '0'),
    ).join('');
}

function visit(
    method: 'post' | 'delete',
    url: string,
    data: Record<string, unknown> = {},
): Promise<string | null> {
    return new Promise((resolve) => {
        let failure: string | null = null;

        router.visit(url, {
            method,
            data: data as Record<string, never>,
            // Keep the page (unsaved preference ticks, the error ref) alive.
            preserveState: true,
            preserveScroll: true,
            onError: (errors) => {
                failure =
                    Object.values(errors)[0] ?? 'The request was rejected.';
            },
            onFinish: () => resolve(failure),
        });
    });
}

/**
 * Browser side of Web Push: the service worker is registered only from
 * `enable()`, so users who never opt in never get one. Permission is
 * requested there too, on the user's tap.
 */
export function usePushSubscription(
    publicKey: string | null,
): UsePushSubscriptionReturn {
    const supported = ref(
        typeof window !== 'undefined' &&
            'serviceWorker' in navigator &&
            'PushManager' in window &&
            'Notification' in window,
    );
    const permission = ref<PushPermission>(
        supported.value ? Notification.permission : 'unsupported',
    );
    const endpoint = ref<string | null>(null);
    const endpointHash = ref<string | null>(null);
    const busy = ref(false);
    const error = ref<string | null>(null);

    async function setEndpoint(value: string | null): Promise<void> {
        endpoint.value = value;
        endpointHash.value = value === null ? null : await sha256Hex(value);
    }

    // Re-read after the user changes the site setting and returns to the tab.
    function refreshPermission(): void {
        if (supported.value) {
            permission.value = Notification.permission;
        }
    }

    onMounted(async () => {
        window.addEventListener('focus', refreshPermission);

        if (!supported.value || permission.value !== 'granted') {
            return;
        }

        try {
            const registration =
                await navigator.serviceWorker.getRegistration('/');
            const subscription =
                (await registration?.pushManager.getSubscription()) ?? null;

            await setEndpoint(subscription?.endpoint ?? null);
        } catch {
            await setEndpoint(null);
        }
    });

    onBeforeUnmount(() => {
        window.removeEventListener('focus', refreshPermission);
    });

    async function enable(): Promise<void> {
        if (!supported.value || publicKey === null || busy.value) {
            return;
        }

        busy.value = true;
        error.value = null;

        // Kept when a re-enable fails, so a working device stays working.
        let previous = endpoint.value;
        let created: PushSubscription | null = null;

        try {
            // First, while the tap's user activation is still live.
            permission.value = await Notification.requestPermission();

            if (permission.value !== 'granted') {
                return;
            }

            await navigator.serviceWorker.register(SERVICE_WORKER_URL, {
                scope: '/',
            });
            const registration = await navigator.serviceWorker.ready;

            const key = urlBase64ToBytes(publicKey);
            let subscription = await registration.pushManager.getSubscription();

            // Rows made with a rotated server key never deliver; start over.
            if (
                subscription &&
                !sameKey(subscription.options.applicationServerKey, key)
            ) {
                await subscription.unsubscribe();
                subscription = null;
                previous = null;
            }

            if (subscription === null) {
                subscription = created =
                    await registration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: key,
                    });
            }

            const json = subscription.toJSON();
            const failure = await visit('post', store().url, {
                endpoint: json.endpoint,
                keys: {
                    p256dh: json.keys?.p256dh,
                    auth: json.keys?.auth,
                },
            });

            if (failure === null) {
                await setEndpoint(subscription.endpoint);

                return;
            }

            error.value = failure;
            await created?.unsubscribe().catch(() => false);
            await setEndpoint(previous);
        } catch (caught) {
            error.value =
                caught instanceof Error && caught.message !== ''
                    ? caught.message
                    : 'Could not enable web push on this device.';
            await created?.unsubscribe().catch(() => false);
            await setEndpoint(previous);
        } finally {
            busy.value = false;
        }
    }

    async function disable(deviceId: number | null): Promise<void> {
        if (busy.value) {
            return;
        }

        busy.value = true;

        try {
            if (deviceId === null) {
                await unsubscribeLocally();

                return;
            }

            const failure = await visit('delete', destroy(deviceId).url);

            if (failure === null) {
                await unsubscribeLocally();
            }
        } finally {
            busy.value = false;
        }
    }

    async function unsubscribeLocally(): Promise<void> {
        try {
            const registration =
                await navigator.serviceWorker.getRegistration('/');

            await (
                await registration?.pushManager.getSubscription()
            )?.unsubscribe();
        } catch {
            // The server row is already gone; a stale local subscription is harmless.
        }

        await setEndpoint(null);
    }

    return {
        supported,
        permission,
        endpoint,
        endpointHash,
        busy,
        error,
        enable,
        disable,
    };
}
