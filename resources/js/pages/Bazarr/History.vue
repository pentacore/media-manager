<script setup lang="ts">
import { Deferred, Head, router } from '@inertiajs/vue3';
import HistoryController from '@/actions/App/Http/Controllers/Bazarr/HistoryController';
import OverviewController from '@/actions/App/Http/Controllers/Bazarr/OverviewController';
import InventoryPager from '@/components/bazarr/InventoryPager.vue';
import SubtitleTabs from '@/components/bazarr/SubtitleTabs.vue';
import { dashboard } from '@/routes';

interface HistoryItem {
    media_type: 'episode' | 'movie';
    media_id: number;
    title: string;
    language?: string;
    provider?: string;
    action?: string;
    score?: number;
    occurred_at?: string;
}

interface HistoryInventory {
    data: HistoryItem[];
    page: number;
    per_page: number;
    total: number;
    partial: boolean;
    errors: string[];
}

const props = defineProps<{
    connections: { id: number; name: string }[];
    selected_connection_id: number | null;
    requires_connection_selection: boolean;
    history?: HistoryInventory | null;
    filters: {
        page: number;
        per_page: number;
        media_type?: string | null;
        provider?: string | null;
    };
}>();

function navigate(overrides: Record<string, string | number | null>): void {
    const query: Record<string, string | number> = {};
    const next = {
        connection: props.selected_connection_id,
        media_type: props.filters.media_type ?? null,
        provider: props.filters.provider ?? null,
        page: props.filters.page,
        ...overrides,
    };

    for (const [key, value] of Object.entries(next)) {
        if (
            value !== null &&
            value !== '' &&
            !(key === 'page' && value === 1)
        ) {
            query[key] = value;
        }
    }

    router.get(HistoryController.url(), query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function updateMediaType(event: Event): void {
    // Any filter change invalidates the current offset.
    navigate({
        media_type: (event.target as HTMLSelectElement).value,
        page: 1,
    });
}

function updateProvider(event: Event): void {
    navigate({ provider: (event.target as HTMLInputElement).value, page: 1 });
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Media', href: dashboard().url },
            { title: 'Subtitles', href: OverviewController.url() },
            { title: 'History', href: HistoryController.url() },
        ],
    },
});
</script>

<template>
    <div class="space-y-6 p-4 sm:p-6">
        <Head title="Subtitle history" />
        <SubtitleTabs
            active="history"
            :connections="connections"
            :selected-connection-id="selected_connection_id"
        />

        <section
            v-if="selected_connection_id"
            class="flex flex-wrap items-end gap-4 rounded-xl border border-border bg-card p-4"
            data-test="history-filters"
        >
            <label class="text-sm font-medium">
                Media type
                <select
                    class="mt-1 h-9 min-w-40 rounded-md border border-input bg-background px-3 capitalize"
                    data-test="history-media-type-filter"
                    :value="filters.media_type ?? ''"
                    @change="updateMediaType"
                >
                    <option value="">All media</option>
                    <option value="episode">Episode</option>
                    <option value="movie">Movie</option>
                </select>
            </label>
            <label class="text-sm font-medium">
                Provider
                <input
                    type="search"
                    class="mt-1 h-9 min-w-48 rounded-md border border-input bg-background px-3"
                    data-test="history-provider-filter"
                    placeholder="Any provider"
                    :value="filters.provider ?? ''"
                    @change="updateProvider"
                />
            </label>
        </section>

        <div
            v-if="requires_connection_selection"
            class="rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm"
        >
            Select a Bazarr connection to load its history.
        </div>
        <Deferred v-else-if="selected_connection_id" data="history">
            <template #fallback>
                <div
                    data-test="subtitle-history-skeleton"
                    class="animate-pulse space-y-3"
                >
                    <div
                        v-for="index in 5"
                        :key="index"
                        class="h-14 rounded-xl bg-muted"
                    />
                </div>
            </template>

            <div
                v-if="history?.partial"
                class="mb-4 rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm"
            >
                {{ history.errors.join(' ') }}
            </div>
            <div
                v-if="history && history.data.length === 0"
                class="rounded-xl border border-border bg-card p-8 text-center"
            >
                <p class="font-medium">No subtitle history found</p>
                <p class="text-sm text-muted-foreground">
                    Bazarr activity will appear here.
                </p>
            </div>
            <div
                v-else
                class="overflow-hidden rounded-xl border border-border bg-card"
            >
                <div
                    v-for="item in history?.data ?? []"
                    :key="`${item.media_type}-${item.media_id}-${item.occurred_at}`"
                    class="grid gap-1 border-b border-border p-4 last:border-b-0 sm:grid-cols-[1fr_auto] sm:items-center"
                >
                    <div>
                        <p class="font-medium">{{ item.title }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ item.provider ?? 'Unknown provider' }} ·
                            {{ item.language ?? 'Unknown language' }}
                        </p>
                    </div>
                    <div class="text-sm sm:text-right">
                        <p class="capitalize">{{ item.action ?? 'updated' }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ item.occurred_at ?? 'Time unavailable' }}
                        </p>
                    </div>
                </div>
            </div>
        </Deferred>

        <InventoryPager
            v-if="history"
            :page="history.page"
            :per-page="history.per_page"
            :total="history.total"
            @change="(page) => navigate({ page })"
        />
    </div>
</template>
