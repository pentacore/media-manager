<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import MissingController from '@/actions/App/Http/Controllers/Bazarr/MissingController';
import OverviewController from '@/actions/App/Http/Controllers/Bazarr/OverviewController';
import InventoryPager from '@/components/bazarr/InventoryPager.vue';
import SubtitleItemDrawer from '@/components/bazarr/SubtitleItemDrawer.vue';
import SubtitleTabs from '@/components/bazarr/SubtitleTabs.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import type { SubtitleItemResource } from '@/typefinder/resources/SubtitleItemResource';

type SubtitleItem = SubtitleItemResource;

interface Inventory {
    data: SubtitleItem[];
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
    missing: Inventory | null;
    filters: {
        page: number;
        per_page: number;
        media_type?: string | null;
        scope?: string | null;
    };
}>();

const selectedItem = ref<SubtitleItem | null>(null);

function navigate(overrides: Record<string, string | number | null>): void {
    const query: Record<string, string | number> = {};
    const next = {
        connection: props.selected_connection_id,
        media_type: props.filters.media_type ?? null,
        scope: props.filters.scope ?? null,
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

    router.get(MissingController.url(), query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function updateFilter(key: 'media_type' | 'scope', event: Event): void {
    // Any filter change invalidates the current offset.
    navigate({ [key]: (event.target as HTMLSelectElement).value, page: 1 });
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Media', href: dashboard().url },
            { title: 'Subtitles', href: OverviewController.url() },
            { title: 'Missing', href: MissingController.url() },
        ],
    },
});
</script>

<template>
    <div class="space-y-6 p-4 sm:p-6">
        <Head title="Missing subtitles" />
        <SubtitleTabs
            active="missing"
            :connections="connections"
            :selected-connection-id="selected_connection_id"
        />

        <section
            v-if="missing"
            class="flex flex-wrap items-end gap-4 rounded-xl border border-border bg-card p-4"
            data-test="missing-filters"
        >
            <label class="text-sm font-medium">
                Media type
                <select
                    class="mt-1 h-9 min-w-40 rounded-md border border-input bg-background px-3 capitalize"
                    data-test="missing-media-type-filter"
                    :value="filters.media_type ?? ''"
                    @change="updateFilter('media_type', $event)"
                >
                    <option value="">All media</option>
                    <option value="episode">Episode</option>
                    <option value="movie">Movie</option>
                </select>
            </label>
            <label class="text-sm font-medium">
                Scope
                <select
                    class="mt-1 h-9 min-w-40 rounded-md border border-input bg-background px-3 capitalize"
                    data-test="missing-scope-filter"
                    :value="filters.scope ?? ''"
                    @change="updateFilter('scope', $event)"
                >
                    <option value="">All scopes</option>
                    <option value="anime">Anime</option>
                    <option value="tv">TV</option>
                    <option value="movie">Movie</option>
                </select>
            </label>
        </section>

        <div
            v-if="requires_connection_selection"
            class="rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm"
        >
            Select a Bazarr connection to view wanted subtitles.
        </div>

        <div
            v-else-if="missing?.partial"
            class="rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm"
        >
            {{ missing.errors.join(' ') }}
        </div>

        <div
            v-if="missing && missing.data.length === 0"
            class="rounded-xl border border-border bg-card p-8 text-center"
        >
            <p class="font-medium">Nothing is currently missing</p>
            <p class="text-sm text-muted-foreground">
                Bazarr has no wanted subtitle entries for this selection.
            </p>
        </div>
        <div
            v-else-if="missing"
            class="overflow-hidden rounded-xl border border-border bg-card"
        >
            <div
                v-for="item in missing.data"
                :key="`${item.media_type}-${item.media_id}`"
                class="flex flex-col gap-2 border-b border-border p-4 last:border-b-0 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <p class="font-medium">{{ item.title }}</p>
                    <p class="text-xs text-muted-foreground capitalize">
                        {{ item.media_type }} · {{ item.scope ?? 'movie' }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span
                        v-for="language in item.missing_languages"
                        :key="language"
                        class="rounded-full bg-warning/15 px-2 py-1 text-xs font-medium text-warning-foreground"
                    >
                        {{ language }}
                    </span>
                    <Button
                        size="sm"
                        variant="outline"
                        :data-test="`subtitle-item-${item.media_type}-${item.media_id}`"
                        @click="selectedItem = item"
                    >
                        Inspect
                    </Button>
                </div>
            </div>
        </div>

        <InventoryPager
            v-if="missing"
            :page="missing.page"
            :per-page="missing.per_page"
            :total="missing.total"
            @change="(page) => navigate({ page })"
        />

        <SubtitleItemDrawer
            :open="selectedItem !== null"
            :item="selectedItem"
            :connection-id="selected_connection_id"
            @update:open="
                (open) => {
                    if (!open) selectedItem = null;
                }
            "
        />
    </div>
</template>
