<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { useDateTime } from '@/composables/useDateTime';
import { usePushSubscription } from '@/composables/usePushSubscription';
import {
    destroy,
    test as testRoute,
} from '@/routes/settings/push-subscriptions';

interface PushDevice {
    id: number;
    endpoint_hash: string;
    label: string | null;
    created_at: string | null;
    last_used_at: string | null;
}

const props = defineProps<{
    available: boolean;
    publicKey: string | null;
    devices: PushDevice[];
}>();

const { formatDateTime, formatSmart } = useDateTime();
const { supported, permission, endpointHash, busy, error, enable, disable } =
    usePushSubscription(props.publicKey);

const testing = ref(false);
const removingId = ref<number | null>(null);

const thisDevice = computed(
    () =>
        props.devices.find(
            (device) => device.endpoint_hash === endpointHash.value,
        ) ?? null,
);

function sendTest(): void {
    testing.value = true;
    router.post(
        testRoute().url,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                testing.value = false;
            },
        },
    );
}

function remove(device: PushDevice): void {
    if (device.id === thisDevice.value?.id) {
        void disable(device.id);

        return;
    }

    removingId.value = device.id;
    router.delete(destroy(device.id).url, {
        preserveScroll: true,
        onFinish: () => {
            removingId.value = null;
        },
    });
}
</script>

<template>
    <div data-push-card class="rounded-xl border border-border bg-card p-4">
        <div class="text-[14px] font-semibold">Push on this device</div>

        <p
            v-if="!available"
            data-push-state="unconfigured"
            class="mt-1 text-[12.5px] text-muted-foreground"
        >
            Web push is not configured on this server. An administrator needs to
            set the WEBPUSH_PUBLIC_KEY and WEBPUSH_PRIVATE_KEY keys.
        </p>

        <p
            v-else-if="!supported"
            data-push-state="unsupported"
            class="mt-1 text-[12.5px] text-muted-foreground"
        >
            This browser cannot receive web push. On iPhone and iPad, add
            MediaManager to the Home Screen first and open it from there.
        </p>

        <template v-else>
            <p
                v-if="permission === 'denied'"
                data-push-state="denied"
                class="mt-1 text-[12.5px] text-muted-foreground"
            >
                Notifications are blocked for this site. In Chrome, open the
                lock icon beside the address bar, choose Site settings, set
                Notifications to Allow, then reload this page.
            </p>
            <p
                v-else-if="thisDevice"
                data-push-state="enabled"
                class="mt-1 text-[12.5px] text-muted-foreground"
            >
                This device receives web push notifications for the
                notifications you switch on under Web push below.
            </p>
            <p
                v-else
                data-push-state="disabled"
                class="mt-1 text-[12.5px] text-muted-foreground"
            >
                Get notifications on this device even when MediaManager is
                closed. Your browser asks for permission when you enable it.
            </p>

            <div class="mt-3 flex flex-wrap gap-2">
                <Button
                    v-if="thisDevice"
                    type="button"
                    variant="outline"
                    data-push-disable
                    :disabled="busy"
                    @click="disable(thisDevice.id)"
                >
                    Disable on this device
                </Button>
                <Button
                    v-else-if="permission !== 'denied'"
                    type="button"
                    data-push-enable
                    :disabled="busy"
                    @click="enable()"
                >
                    {{ busy ? 'Enabling…' : 'Enable on this device' }}
                </Button>
                <Button
                    v-if="devices.length > 0"
                    type="button"
                    variant="outline"
                    data-push-test
                    :disabled="testing"
                    @click="sendTest()"
                >
                    {{ testing ? 'Sending…' : 'Send a test push' }}
                </Button>
            </div>
            <InputError
                class="mt-2"
                data-push-error
                :message="
                    error ??
                    $page.props.errors.endpoint ??
                    $page.props.errors['keys.p256dh'] ??
                    $page.props.errors['keys.auth']
                "
            />
            <InputError class="mt-2" :message="$page.props.errors.test_push" />
        </template>

        <div v-if="devices.length > 0" class="mt-4">
            <div
                class="text-[11px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
            >
                Your devices
            </div>
            <ul class="mt-2 divide-y divide-border">
                <li
                    v-for="device in devices"
                    :key="device.id"
                    :data-push-device="device.id"
                    class="flex items-center justify-between gap-3 py-2 text-[12.5px]"
                >
                    <div class="min-w-0">
                        <div class="truncate font-medium">
                            {{ device.label ?? 'Unknown device' }}
                            <span
                                v-if="device.id === thisDevice?.id"
                                class="ml-1 rounded bg-accent/15 px-1.5 py-0.5 text-[11px] font-medium text-accent"
                                >This device</span
                            >
                        </div>
                        <div class="text-muted-foreground">
                            Added {{ formatDateTime(device.created_at) }} · Last
                            used
                            {{
                                device.last_used_at
                                    ? formatSmart(device.last_used_at)
                                    : 'never'
                            }}
                        </div>
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        data-push-remove
                        :disabled="busy || removingId === device.id"
                        @click="remove(device)"
                    >
                        Remove
                    </Button>
                </li>
            </ul>
        </div>
    </div>
</template>
