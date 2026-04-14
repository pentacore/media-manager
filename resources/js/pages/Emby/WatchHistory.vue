<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { History } from 'lucide-vue-next'
import WatchHistoryController from '@/actions/App/Http/Controllers/Emby/WatchHistoryController'
import { dashboard } from '@/routes'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select'
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table'

interface Activity {
    id: number
    media_type: string | null
    media_title: string | null
    series_title: string | null
    action: string | null
    play_position: number | null
    duration_ticks: number | null
    emby_username: string | null
    created_at: string | null
}

interface PaginatorLink {
    url: string | null
    label: string
    active: boolean
}

interface PaginatorMeta {
    current_page: number
    last_page: number
    total: number
    per_page: number
}

const props = defineProps<{
    activities: { data: Activity[]; links: PaginatorLink[]; meta: PaginatorMeta }
    filters: { media_type: string }
}>()

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Watch History', href: WatchHistoryController().url },
        ],
    },
})

function formatTime(iso: string | null): string {
    if (!iso) {
        return '-'
    }
    const date = new Date(iso)
    const now = new Date()
    const diffMs = now.getTime() - date.getTime()
    const diffMins = Math.floor(diffMs / 60000)

    if (diffMins < 1) {
        return 'Just now'
    }
    if (diffMins < 60) {
        return `${diffMins}m ago`
    }
    const diffHours = Math.floor(diffMins / 60)
    if (diffHours < 24) {
        return `${diffHours}h ago`
    }
    const diffDays = Math.floor(diffHours / 24)
    return `${diffDays}d ago`
}

function actionBadgeVariant(action: string | null): 'default' | 'secondary' | 'outline' {
    if (action === 'played') {
        return 'default'
    }
    if (action === 'stopped') {
        return 'secondary'
    }
    return 'outline'
}

function onMediaTypeChange(value: unknown) {
    const v = typeof value === 'string' ? value : ''
    router.get(
        WatchHistoryController().url,
        v === 'all' ? {} : { media_type: v },
        { preserveState: true, preserveScroll: true, replace: true },
    )
}

function goToPage(url: string | null) {
    if (!url) {
        return
    }
    router.get(url, {}, { preserveState: true, preserveScroll: true })
}

function currentFilter(): string {
    return props.filters.media_type === '' ? 'all' : props.filters.media_type
}
</script>

<template>
    <Head title="Watch History" />

    <div class="space-y-6 p-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="flex items-center gap-2 text-2xl font-bold tracking-tight">
                    <History class="size-6" />
                    Watch History
                </h2>
                <p class="text-muted-foreground">
                    {{ activities.meta.total }} {{ activities.meta.total === 1 ? 'entry' : 'entries' }}
                </p>
            </div>

            <Select :default-value="currentFilter()" @update:model-value="onMediaTypeChange">
                <SelectTrigger class="w-40">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All types</SelectItem>
                    <SelectItem value="movie">Movie</SelectItem>
                    <SelectItem value="episode">Episode</SelectItem>
                </SelectContent>
            </Select>
        </div>

        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>When</TableHead>
                    <TableHead>User</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead>Title</TableHead>
                    <TableHead>Action</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow v-for="activity in activities.data" :key="activity.id">
                    <TableCell class="text-muted-foreground">{{ formatTime(activity.created_at) }}</TableCell>
                    <TableCell>{{ activity.emby_username ?? '-' }}</TableCell>
                    <TableCell>
                        <Badge v-if="activity.media_type" variant="outline">{{ activity.media_type }}</Badge>
                        <span v-else class="text-muted-foreground">-</span>
                    </TableCell>
                    <TableCell class="font-medium">
                        {{ activity.media_title ?? '-' }}
                        <span v-if="activity.series_title" class="block text-xs font-normal text-muted-foreground">
                            {{ activity.series_title }}
                        </span>
                    </TableCell>
                    <TableCell>
                        <Badge v-if="activity.action" :variant="actionBadgeVariant(activity.action)">
                            {{ activity.action }}
                        </Badge>
                        <span v-else class="text-muted-foreground">-</span>
                    </TableCell>
                </TableRow>
                <TableRow v-if="activities.data.length === 0">
                    <TableCell :colspan="5" class="py-8 text-center text-muted-foreground">
                        No activity yet.
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>

        <div v-if="activities.links.length > 3" class="flex flex-wrap items-center gap-2">
            <Button
                v-for="(link, index) in activities.links"
                :key="index"
                variant="outline"
                size="sm"
                :disabled="!link.url"
                :class="link.active ? 'bg-accent' : ''"
                @click="goToPage(link.url)"
            >
                <span v-html="link.label" />
            </Button>
        </div>
    </div>
</template>
