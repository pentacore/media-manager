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
): Promise<boolean> {
    return new Promise((resolve) => {
        let failed = false;

        router.visit(url, {
            method,
            data: data as Record<string, never>,
            preserveScroll: true,
            onError: () => {
                failed = true;
            },
            onFinish: () => resolve(!failed),
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

        try {
            await navigator.serviceWorker.register(SERVICE_WORKER_URL, {
                scope: '/',
            });
            const registration = await navigator.serviceWorker.ready;

            permission.value = await Notification.requestPermission();

            if (permission.value !== 'granted') {
                return;
            }

            const key = urlBase64ToBytes(publicKey);
            let subscription = await registration.pushManager.getSubscription();

            // Rows made with a rotated server key never deliver; start over.
            if (
                subscription &&
                !sameKey(subscription.options.applicationServerKey, key)
            ) {
                await subscription.unsubscribe();
                subscription = null;
            }

            subscription ??= await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: key,
            });

            const json = subscription.toJSON();
            const saved = await visit('post', store().url, {
                endpoint: json.endpoint,
                keys: {
                    p256dh: json.keys?.p256dh,
                    auth: json.keys?.auth,
                },
            });

            if (saved) {
                await setEndpoint(subscription.endpoint);
            } else {
                await subscription.unsubscribe();
                await setEndpoint(null);
            }
        } catch {
            await setEndpoint(null);
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

            const removed = await visit('delete', destroy(deviceId).url);

            if (removed) {
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
        } finally {
            await setEndpoint(null);
        }
    }

    return {
        supported,
        permission,
        endpoint,
        endpointHash,
        busy,
        enable,
        disable,
    };
}
