<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { onMounted } from 'vue'
import { Film, Monitor, Pause, Play, Radio, Tv } from 'lucide-vue-next'
import NowPlayingController from '@/actions/App/Http/Controllers/Emby/NowPlayingController'
import { dashboard } from '@/routes'
import { useEmbyActivity } from '@/composables/useEmbyActivity'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'

interface NowPlayingItem {
    id: string | null
    name: string | null
    type: string | null
    series_name: string | null
    run_time_ticks: number | null
}

interface PlayState {
    position_ticks: number | null
    is_paused: boolean
}

interface Session {
    id: string | null
    user_name: string | null
    client: string | null
    device_name: string | null
    now_playing: NowPlayingItem | null
    play_state: PlayState | null
}

defineProps<{ sessions: Session[] }>()

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Now Playing', href: NowPlayingController().url },
        ],
    },
})

const { subscribe } = useEmbyActivity()

onMounted(() => {
    // Subscribe so broadcast events come in while page is open.
    // Future work: merge incoming NowPlayingItem broadcast data with the initial
    // sessions list (broadcast events currently have a simpler shape).
    subscribe()
})

function progressPercent(position: number | null, total: number | null): number {
    if (!position || !total || total <= 0) {
        return 0
    }
    const pct = (position / total) * 100
    if (pct < 0) {
        return 0
    }
    if (pct > 100) {
        return 100
    }
    return pct
}

function mediaIcon(type: string | null) {
    if (type === 'Episode') {
        return Tv
    }
    if (type === 'Movie') {
        return Film
    }
    return Radio
}
</script>

<template>
    <Head title="Now Playing" />

    <div class="space-y-6 p-6">
        <div>
            <h2 class="flex items-center gap-2 text-2xl font-bold tracking-tight">
                <Radio class="size-6" />
                Now Playing
            </h2>
            <p class="text-muted-foreground">
                {{ sessions.length }} active {{ sessions.length === 1 ? 'session' : 'sessions' }}
            </p>
        </div>

        <div
            v-if="sessions.length === 0"
            class="flex flex-col items-center justify-center rounded-md border border-dashed py-16 text-center"
        >
            <Radio class="mb-3 size-10 text-muted-foreground/50" />
            <p class="text-sm text-muted-foreground">No active playback sessions</p>
        </div>

        <div v-else class="grid gap-4 grid-cols-1 md:grid-cols-2 lg:grid-cols-3">
            <Card v-for="session in sessions" :key="session.id ?? ''">
                <CardHeader>
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <CardTitle class="flex items-center gap-2 text-base">
                                <component :is="mediaIcon(session.now_playing?.type ?? null)" class="size-4 shrink-0" />
                                <span class="truncate">{{ session.now_playing?.name ?? 'Unknown' }}</span>
                            </CardTitle>
                            <p v-if="session.now_playing?.series_name" class="mt-1 truncate text-xs text-muted-foreground">
                                {{ session.now_playing.series_name }}
                            </p>
                        </div>
                        <component
                            :is="session.play_state?.is_paused ? Pause : Play"
                            class="size-4 shrink-0 text-muted-foreground"
                        />
                    </div>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div class="text-sm">
                        <p class="font-medium">{{ session.user_name ?? 'Unknown user' }}</p>
                        <p class="flex items-center gap-1 text-xs text-muted-foreground">
                            <Monitor class="size-3" />
                            {{ session.client ?? '-' }}<span v-if="session.device_name">&nbsp;&middot; {{ session.device_name }}</span>
                        </p>
                    </div>

                    <div class="space-y-1">
                        <div class="h-2 w-full overflow-hidden rounded-full bg-muted">
                            <div
                                class="h-full rounded-full bg-primary transition-all"
                                :style="{ width: `${progressPercent(session.play_state?.position_ticks ?? null, session.now_playing?.run_time_ticks ?? null)}%` }"
                            />
                        </div>
                        <p class="text-right text-xs text-muted-foreground tabular-nums">
                            {{ progressPercent(session.play_state?.position_ticks ?? null, session.now_playing?.run_time_ticks ?? null).toFixed(0) }}%
                        </p>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
