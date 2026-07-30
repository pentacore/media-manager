<script setup lang="ts">
import { Deferred, Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import LibraryController from '@/actions/App/Http/Controllers/Bazarr/LibraryController';
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
    library?: Inventory | null;
    filters: {
        page: number;
        per_page: number;
        media_type?: string | null;
        scope?: string | null;
        missing_only?: boolean | null;
    };
}>();

const selectedItem = ref<SubtitleItem | null>(null);

function navigate(
    overrides: Record<string, string | number | boolean | null>,
): void {
    const query: Record<string, string | number | boolean> = {};
    const next = {
        connection: props.selected_connection_id,
        media_type: props.filters.media_type ?? null,
        scope: props.filters.scope ?? null,
        missing_only: props.filters.missing_only ? true : null,
        page: props.filters.page,
        ...overrides,
    };

    for (const [key, value] of Object.entries(next)) {
        if (
            value !== null &&
            value !== '' &&
            value !== false &&
            !(key === 'page' && value === 1)
        ) {
            query[key] = value;
        }
    }

    router.get(LibraryController.url(), query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function updateFilter(key: 'media_type' | 'scope', event: Event): void {
    // Any filter change invalidates the current offset.
    navigate({ [key]: (event.target as HTMLSelectElement).value, page: 1 });
}

function toggleMissingOnly(event: Event): void {
    navigate({
        missing_only: (event.target as HTMLInputElement).checked ? true : null,
        page: 1,
    });
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Media', href: dashboard().url },
            { title: 'Subtitles', href: OverviewController.url() },
            { title: 'Library', href: LibraryController.url() },
        ],
    },
});
</script>

<template>
    <div class="space-y-6 p-4 sm:p-6">
        <Head title="Subtitle library" />
        <SubtitleTabs
            active="library"
            :connections="connections"
            :selected-connection-id="selected_connection_id"
        />

        <section
            v-if="selected_connection_id"
            class="flex flex-wrap items-end gap-4 rounded-xl border border-border bg-card p-4"
            data-test="library-filters"
        >
            <label class="text-sm font-medium">
                Media type
                <select
                    class="mt-1 h-9 min-w-40 rounded-md border border-input bg-background px-3 capitalize"
                    data-test="library-media-type-filter"
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
                    data-test="library-scope-filter"
                    :value="filters.scope ?? ''"
                    @change="updateFilter('scope', $event)"
                >
                    <option value="">All scopes</option>
                    <option value="anime">Anime</option>
                    <option value="tv">TV</option>
                    <option value="movie">Movie</option>
                </select>
            </label>
            <label class="flex items-center gap-2 text-sm font-medium">
                <input
                    type="checkbox"
                    class="size-4 rounded border-input"
                    data-test="library-missing-only-filter"
                    :checked="filters.missing_only === true"
                    @change="toggleMissingOnly"
                />
                Missing subtitles only
            </label>
        </section>

        <div
            v-if="requires_connection_selection"
            class="rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm"
        >
            Select a Bazarr connection to load its library.
        </div>
        <Deferred v-else-if="selected_connection_id" data="library">
            <template #fallback>
                <div
                    data-test="subtitle-library-skeleton"
                    class="animate-pulse space-y-3"
                >
                    <div
                        v-for="index in 4"
                        :key="index"
                        class="h-20 rounded-xl bg-muted"
                    />
                </div>
            </template>

            <div
                v-if="library?.partial"
                class="mb-4 rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm"
            >
                {{ library.errors.join(' ') }}
            </div>
            <div
                v-if="library && library.data.length === 0"
                class="rounded-xl border border-border bg-card p-8 text-center"
            >
                <p class="font-medium">No subtitle inventory found</p>
                <p class="text-sm text-muted-foreground">
                    Check the mapped Sonarr and Radarr connections.
                </p>
            </div>
            <div v-else class="grid gap-4 md:grid-cols-2">
                <article
                    v-for="item in library?.data ?? []"
                    :key="`${item.media_type}-${item.media_id}`"
                    class="rounded-xl border border-border bg-card p-4"
                >
                    <p class="font-medium">{{ item.title }}</p>
                    <p class="text-xs text-muted-foreground capitalize">
                        {{ item.media_type }} · {{ item.scope ?? 'movie' }}
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <span
                            v-for="track in item.subtitle_tracks"
                            :key="`${track.language}-${track.forced}-${track.hearing_impaired}`"
                            class="rounded-full bg-muted px-2 py-1 text-xs"
                        >
                            {{ track.language }}
                            <template v-if="track.forced"> · forced</template>
                            <template v-if="track.hearing_impaired">
                                · HI
                            </template>
                        </span>
                        <span
                            v-if="item.subtitle_tracks.length === 0"
                            class="text-xs text-muted-foreground"
                        >
                            No subtitle tracks
                        </span>
                    </div>
                    <Button
                        class="mt-4"
                        size="sm"
                        variant="outline"
                        :data-test="`subtitle-item-${item.media_type}-${item.media_id}`"
                        @click="selectedItem = item"
                    >
                        Inspect
                    </Button>
                </article>
            </div>
        </Deferred>

        <InventoryPager
            v-if="library"
            :page="library.page"
            :per-page="library.per_page"
            :total="library.total"
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
