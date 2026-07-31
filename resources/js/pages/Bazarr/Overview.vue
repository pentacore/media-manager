<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { AlertTriangle, Captions, CircleCheck, ListX } from '@lucide/vue';
import OverviewController from '@/actions/App/Http/Controllers/Bazarr/OverviewController';
import SubtitleTabs from '@/components/bazarr/SubtitleTabs.vue';
import { dashboard } from '@/routes';

interface Overview {
    missing: { episodes: number; movies: number; total: number };
    health_issue_count: number;
    partial: boolean;
    errors: string[];
}

defineProps<{
    connections: { id: number; name: string }[];
    selected_connection_id: number | null;
    requires_connection_selection: boolean;
    overview: Overview | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Media', href: dashboard().url },
            { title: 'Subtitles', href: OverviewController.url() },
        ],
    },
});
</script>

<template>
    <div class="space-y-6 p-4 sm:p-6">
        <Head title="Subtitle Center" />
        <SubtitleTabs
            active="overview"
            :connections="connections"
            :selected-connection-id="selected_connection_id"
        />

        <div
            v-if="requires_connection_selection"
            class="rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm"
        >
            Select a Bazarr connection to view its subtitle coverage.
        </div>
        <div
            v-else-if="connections.length === 0"
            class="rounded-lg border border-border bg-card p-6 text-center"
        >
            <Captions class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-3 font-medium">No active Bazarr connection</p>
            <p class="text-sm text-muted-foreground">
                Ask an administrator to configure Bazarr.
            </p>
        </div>
        <template v-else-if="overview">
            <div
                v-if="overview.partial"
                class="rounded-lg border border-warning/40 bg-warning/10 p-4"
            >
                <p class="flex items-center gap-2 font-medium">
                    <AlertTriangle class="size-4" />
                    Some Bazarr data is temporarily unavailable
                </p>
                <ul class="mt-2 list-disc pl-5 text-sm text-muted-foreground">
                    <li v-for="error in overview.errors" :key="error">
                        {{ error }}
                    </li>
                </ul>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-border bg-card p-5">
                    <ListX class="size-5 text-warning" />
                    <p class="mt-4 text-3xl font-semibold tabular-nums">
                        {{ overview.missing.total }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Missing subtitles
                    </p>
                </div>
                <div class="rounded-xl border border-border bg-card p-5">
                    <Captions class="size-5 text-info" />
                    <p class="mt-4 text-3xl font-semibold tabular-nums">
                        {{ overview.missing.episodes }}
                    </p>
                    <p class="text-sm text-muted-foreground">Episodes wanted</p>
                </div>
                <div class="rounded-xl border border-border bg-card p-5">
                    <Captions class="size-5 text-info" />
                    <p class="mt-4 text-3xl font-semibold tabular-nums">
                        {{ overview.missing.movies }}
                    </p>
                    <p class="text-sm text-muted-foreground">Movies wanted</p>
                </div>
                <div class="rounded-xl border border-border bg-card p-5">
                    <CircleCheck class="size-5 text-success" />
                    <p class="mt-4 text-3xl font-semibold tabular-nums">
                        {{ overview.health_issue_count }}
                    </p>
                    <p class="text-sm text-muted-foreground">Health issues</p>
                </div>
            </div>
        </template>
    </div>
</template>
