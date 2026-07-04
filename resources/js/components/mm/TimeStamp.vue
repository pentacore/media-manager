<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { computed, onUnmounted, ref, watch } from 'vue';
import { useDateTime } from '@/composables/useDateTime';
import { cn } from '@/lib/utils';

type TimeStampMode = 'smart' | 'date' | 'time' | 'datetime';

const props = withDefaults(
    defineProps<{
        iso: string | null | undefined;
        mode?: TimeStampMode;
        live?: boolean;
        class?: HTMLAttributes['class'];
    }>(),
    {
        mode: 'smart',
        live: false,
    },
);

const { formatDate, formatTime, formatDateTime, formatSmart } = useDateTime();

// Reactive `now` invalidates relative-time labels without a websocket
// nudge. Only spins up a timer when the caller opts in via `live`.
const nowTick = ref(Date.now());
let nowTimer: ReturnType<typeof setInterval> | null = null;

function startTicker() {
    if (nowTimer !== null) {
        return;
    }

    nowTimer = setInterval(() => {
        nowTick.value = Date.now();
    }, 30_000);
}

function stopTicker() {
    if (nowTimer !== null) {
        clearInterval(nowTimer);
        nowTimer = null;
    }
}

watch(
    () => props.live,
    (live) => {
        if (live) {
            startTicker();
        } else {
            stopTicker();
        }
    },
    { immediate: true },
);

onUnmounted(stopTicker);

const display = computed(() => {
    // Reference the tick so Vue re-evaluates when the timer fires.
    void nowTick.value;

    switch (props.mode) {
        case 'date':
            return formatDate(props.iso);
        case 'time':
            return formatTime(props.iso);
        case 'datetime':
            return formatDateTime(props.iso);
        case 'smart':
        default:
            return formatSmart(props.iso);
    }
});

const title = computed(() => {
    if (!props.iso) {
        return undefined;
    }

    return formatDateTime(props.iso);
});
</script>

<template>
    <time :datetime="iso ?? undefined" :title="title" :class="cn(props.class)">
        {{ display }}
    </time>
</template>
