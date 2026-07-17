<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Bell, BellOff, CheckCheck, Trash2 } from '@lucide/vue';
import { onMounted } from 'vue';
import NotificationController from '@/actions/App/Http/Controllers/NotificationController';
import { Pill, SvcChip, TimeStamp } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { useRealtimeReload } from '@/composables/useRealtimeReload';

interface NotificationData {
    title?: string;
    message?: string;
    icon?: string;
    service?: string;
    link?: string;
    [key: string]: unknown;
}

interface NotificationItem {
    id: string;
    type: string;
    data: NotificationData;
    read_at: string | null;
    created_at: string | null;
}

defineProps<{
    notifications: NotificationItem[];
    unreadCount: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Notifications',
                href: NotificationController.index.url(),
            },
        ],
    },
});

// Laravel auto-broadcasts on the user's private channel as
// "BroadcastNotificationCreated" whenever a notification's via() includes
// 'broadcast'. The websocket payload doesn't carry created_at / read_at
// metadata, so refetch the rows server-side rather than try to upsert
// from the event.
const userId = usePage().props.auth.user?.id;
const { subscribe } = useRealtimeReload({
    channel: `App.Models.User.${userId}`,
    event: 'BroadcastNotificationCreated',
    only: ['notifications', 'unreadCount'],
});

onMounted(() => {
    if (userId) {
        subscribe();
    }
});

function markRead(id: string) {
    router.patch(
        NotificationController.markRead.url({ notification: id }),
        {},
        { preserveScroll: true },
    );
}

function destroy(id: string) {
    router.delete(NotificationController.destroy.url({ notification: id }), {
        preserveScroll: true,
    });
}

function markAllRead() {
    router.patch(
        NotificationController.markAllRead.url(),
        {},
        { preserveScroll: true },
    );
}

function shortType(type: string): string {
    const last = type.split('\\').pop() ?? type;

    return last.replace(/Notification$/, '');
}
</script>

<template>
    <Head title="Notifications" />

    <div class="flex flex-col gap-4 p-5">
        <div class="flex items-end justify-between gap-3">
            <div>
                <h1
                    class="text-[22px] leading-tight font-semibold tracking-tight"
                >
                    Notifications
                </h1>
                <p class="mt-1 text-[13px] text-muted-foreground">
                    {{ notifications.length }} total ·
                    <span class="font-mono-tabular">{{ unreadCount }}</span>
                    unread
                </p>
            </div>
            <Button
                v-if="unreadCount > 0"
                variant="outline"
                size="sm"
                class="h-7 gap-1.5 text-xs"
                @click="markAllRead"
            >
                <CheckCheck class="size-3.5" />Mark all read
            </Button>
        </div>

        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <div
                v-if="notifications.length === 0"
                class="flex flex-col items-center gap-3 py-12 text-center text-muted-foreground"
            >
                <BellOff class="size-7 text-fg-subtle" />
                <p class="text-[13px]">Nothing waiting for you.</p>
            </div>

            <div
                v-for="(n, i) in notifications"
                :key="n.id"
                :class="[
                    'flex items-start gap-3 px-4 py-3',
                    i < notifications.length - 1 && 'border-b border-border',
                    !n.read_at && 'bg-accent/5',
                ]"
            >
                <div class="mt-0.5">
                    <SvcChip v-if="n.data.service" :id="n.data.service" />
                    <Bell v-else class="size-4 text-muted-foreground" />
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span class="text-[13px] font-semibold">
                            {{ n.data.title ?? shortType(n.type) }}
                        </span>
                        <Pill v-if="!n.read_at" variant="info" dot>new</Pill>
                    </div>
                    <p
                        v-if="n.data.message"
                        class="mt-0.5 text-[12.5px] text-muted-foreground"
                    >
                        {{ n.data.message }}
                    </p>
                    <div
                        class="font-mono-tabular mt-1 flex items-center gap-3 text-[11px] text-fg-subtle"
                    >
                        <TimeStamp :iso="n.created_at" />
                        <span>{{ shortType(n.type) }}</span>
                    </div>
                </div>
                <div class="flex items-center gap-1">
                    <Button
                        v-if="!n.read_at"
                        variant="ghost"
                        size="sm"
                        class="h-7 px-2 text-[11px]"
                        @click="markRead(n.id)"
                    >
                        Mark read
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-7"
                        title="Dismiss"
                        @click="destroy(n.id)"
                    >
                        <Trash2 class="size-3.5" />
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
