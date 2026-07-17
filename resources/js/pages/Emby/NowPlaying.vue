<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { RefreshCcw } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import NowPlayingController from '@/actions/App/Http/Controllers/Emby/NowPlayingController';
import {
    InitialsAvatar,
    LiveDot,
    OpenInServiceButton,
    Pill,
    Poster,
    SvcChip,
} from '@/components/mm';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useConnectionState } from '@/composables/useConnectionState';
import { useEmbyActivity } from '@/composables/useEmbyActivity';
import { dashboard } from '@/routes';

interface NowPlayingItem {
    id: string | null;
    name: string | null;
    type: string | null;
    series_name: string | null;
    run_time_ticks: number | null;
}

interface PlayState {
    position_ticks: number | null;
    is_paused: boolean;
}

interface Session {
    id: string | null;
    user_name: string | null;
    client: string | null;
    device_name: string | null;
    now_playing: NowPlayingItem | null;
    play_state: PlayState | null;
}

const props = defineProps<{
    connection: { url: string };
    sessions?: Session[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Live', href: dashboard().url },
            { title: 'Now Playing', href: NowPlayingController().url },
        ],
    },
});

const refreshing = ref(false);

function manualRefresh(): void {
    if (refreshing.value) {
        return;
    }

    refreshing.value = true;
    router.reload({
        only: ['sessions'],
        onFinish: () => {
            refreshing.value = false;
        },
    });
}

const { subscribe, nowPlaying } = useEmbyActivity();

// Real socket state — the pill previously hardcoded "Reverb connected" even
// while the websocket was down.
const { state: connectionState } = useConnectionState();
const connectionPill = computed(() => {
    switch (connectionState.value) {
        case 'connected':
            return { variant: 'ok' as const, label: 'Reverb connected' };
        case 'connecting':
            return { variant: 'warn' as const, label: 'Reverb connecting…' };
        default:
            return {
                variant: 'danger' as const,
                label: 'Reverb disconnected',
            };
    }
});

let reloadTimer: ReturnType<typeof setTimeout> | null = null;
let reloading = false;

function scheduleReload(): void {
    if (reloadTimer || reloading) {
        return;
    }

    reloadTimer = setTimeout(() => {
        reloadTimer = null;
        reloading = true;
        router.reload({
            only: ['sessions'],
            onFinish: () => {
                reloading = false;
            },
        });
    }, 1500);
}

watch(nowPlaying, () => scheduleReload(), { deep: true });

onMounted(subscribe);
onBeforeUnmount(() => {
    if (reloadTimer) {
        clearTimeout(reloadTimer);
        reloadTimer = null;
    }
});

function formatTicks(ticks: number | null): string {
    if (!ticks) {
        return '0:00';
    }

    const total = Math.floor(ticks / 10_000_000);
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;

    if (h > 0) {
        return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
    }

    return `${m}:${String(s).padStart(2, '0')}`;
}

function progressPct(session: Session): number {
    const pos = session.play_state?.position_ticks ?? 0;
    const total = session.now_playing?.run_time_ticks ?? 0;

    if (!total) {
        return 0;
    }

    return Math.min(100, Math.max(0, (pos / total) * 100));
}

function remainingTicks(session: Session): number {
    const pos = session.play_state?.position_ticks ?? 0;
    const total = session.now_playing?.run_time_ticks ?? 0;

    return Math.max(0, total - pos);
}
</script>

<template>
    <Head title="Now Playing" />

    <div class="flex flex-col gap-4 p-5">
        <!-- Hero -->
        <div class="flex items-end justify-between gap-3">
            <div>
                <div class="mb-1.5 flex items-center gap-2">
                    <SvcChip id="emby" />
                    <span class="text-fg-subtle">/</span>
                    <span class="text-[13px] text-muted-foreground"
                        >Live sessions</span
                    >
                </div>
                <h1
                    class="text-[22px] leading-tight font-semibold tracking-tight"
                >
                    Now playing
                </h1>
                <p
                    v-if="props.sessions"
                    class="mt-1 text-[13px] text-muted-foreground"
                >
                    {{ props.sessions.length }} active
                    {{ props.sessions.length === 1 ? 'session' : 'sessions' }}
                </p>
                <p v-else class="mt-1 text-[13px] text-muted-foreground">
                    Loading sessions…
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Pill :variant="connectionPill.variant" dot>{{
                    connectionPill.label
                }}</Pill>
                <OpenInServiceButton
                    :href="props.connection.url"
                    label="Open Emby"
                />
                <Button
                    variant="outline"
                    size="sm"
                    class="h-7 gap-1.5 text-xs"
                    :disabled="refreshing"
                    @click="manualRefresh"
                >
                    <RefreshCcw
                        class="size-3.5"
                        :class="{ 'animate-spin': refreshing }"
                    />Refresh
                </Button>
            </div>
        </div>

        <!-- Session cards -->
        <div
            v-if="!props.sessions"
            class="grid gap-4"
            style="grid-template-columns: repeat(auto-fill, minmax(420px, 1fr))"
        >
            <Skeleton
                v-for="i in 3"
                :key="`np-skel-${i}`"
                class="h-[200px] w-full rounded-xl"
            />
        </div>

        <div
            v-else-if="props.sessions.length === 0"
            class="flex flex-col items-center justify-center rounded-xl border border-dashed border-border py-16 text-center text-fg-subtle"
        >
            <span class="text-sm">No active playback sessions</span>
        </div>

        <div
            v-else
            class="grid gap-4"
            style="grid-template-columns: repeat(auto-fill, minmax(420px, 1fr))"
        >
            <div
                v-for="(session, i) in props.sessions"
                :key="session.id ?? i"
                class="overflow-hidden rounded-xl border border-border bg-card"
            >
                <div class="flex gap-4 p-4">
                    <Poster
                        :hint="
                            (session.now_playing?.name ?? 'media')
                                .toLowerCase()
                                .slice(0, 12)
                        "
                        size="xl"
                    />
                    <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                        <div class="flex items-center justify-between">
                            <Pill variant="ok" dot>
                                <LiveDot class="text-success" />
                                {{
                                    session.play_state?.is_paused
                                        ? 'Paused'
                                        : 'Streaming'
                                }}
                            </Pill>
                            <span
                                class="font-mono-tabular text-[11px] text-muted-foreground"
                                >session #{{ session.id?.slice(-6) ?? i }}</span
                            >
                        </div>
                        <div class="text-[17px] leading-tight font-semibold">
                            {{ session.now_playing?.name ?? 'Unknown' }}
                        </div>
                        <div
                            v-if="session.now_playing?.series_name"
                            class="text-[13px] text-muted-foreground"
                        >
                            {{ session.now_playing.series_name }}
                        </div>
                        <div class="mt-1 flex items-center gap-2.5">
                            <InitialsAvatar
                                :name="session.user_name ?? '?'"
                                :size="22"
                            />
                            <span class="text-[13px]">{{
                                session.user_name ?? 'Unknown user'
                            }}</span>
                            <span class="text-[12px] text-fg-subtle">·</span>
                            <span class="text-[12px] text-fg-subtle">
                                {{ session.client ?? '—'
                                }}<span v-if="session.device_name">
                                    · {{ session.device_name }}</span
                                >
                            </span>
                        </div>
                        <div class="mt-auto pt-2.5">
                            <div
                                class="h-1 overflow-hidden rounded-full bg-bg-elev"
                            >
                                <div
                                    class="h-full rounded-full bg-accent"
                                    :style="{
                                        width: `${progressPct(session)}%`,
                                    }"
                                />
                            </div>
                            <div
                                class="font-mono-tabular mt-1 flex justify-between text-[11px] text-fg-subtle"
                            >
                                <span>{{
                                    formatTicks(
                                        session.play_state?.position_ticks ??
                                            null,
                                    )
                                }}</span>
                                <span
                                    >−{{
                                        formatTicks(remainingTicks(session))
                                    }}
                                    remaining</span
                                >
                                <span>{{
                                    formatTicks(
                                        session.now_playing?.run_time_ticks ??
                                            null,
                                    )
                                }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
