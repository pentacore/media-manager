<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { RefreshCcw, Search } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import WhisparrActionController from '@/actions/App/Http/Controllers/Whisparr/WhisparrActionController';
import WhisparrController from '@/actions/App/Http/Controllers/Whisparr/WhisparrController';
import {
    BulkActionBar,
    BulkCheckbox,
    BulkSelectAll,
    LibraryBulkActions,
} from '@/components/bulk';
import { OpenInServiceButton, Pill, Poster, SvcChip } from '@/components/mm';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { useBulkSelection } from '@/composables/useBulkSelection';
import { useWhisparrBlur } from '@/composables/useWhisparrBlur';
import { focusAfterBulk } from '@/lib/bulk';
import { formatBytes } from '@/lib/format';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import type {
    BulkSummary,
    WhisparrConnection,
    WhisparrItem,
    WhisparrLibrary,
    WhisparrQualityProfiles,
} from '@/types';

type LibraryFilter = 'all' | 'monitored' | 'unmonitored' | 'missing';
type LibrarySort = 'title' | 'year' | 'size';

const props = defineProps<{
    connection: WhisparrConnection | null;
    library?: WhisparrLibrary;
    qualityProfiles?: WhisparrQualityProfiles;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Media', href: dashboard().url },
            { title: 'Whisparr', href: WhisparrController.index.url() },
        ],
    },
});

const { blurPosters } = useWhisparrBlur();

const filter = ref<LibraryFilter>('all');
const sort = ref<LibrarySort>('title');
const query = ref('');
const syncing = ref(false);

const items = computed<WhisparrItem[]>(() => props.library?.items ?? []);

const counts = computed(() => ({
    all: items.value.length,
    monitored: items.value.filter((item) => item.monitored).length,
    unmonitored: items.value.filter((item) => !item.monitored).length,
    missing: items.value.filter((item) => item.missing).length,
}));

const FILTERS: { id: LibraryFilter; label: string }[] = [
    { id: 'all', label: 'All' },
    { id: 'monitored', label: 'Monitored' },
    { id: 'unmonitored', label: 'Unmonitored' },
    { id: 'missing', label: 'Missing' },
];

const SORTS: { id: LibrarySort; label: string }[] = [
    { id: 'title', label: 'Title' },
    { id: 'year', label: 'Year' },
    { id: 'size', label: 'Size' },
];

const visible = computed<WhisparrItem[]>(() => {
    const needle = query.value.trim().toLowerCase();

    const rows = items.value.filter((item) => {
        if (filter.value === 'monitored' && !item.monitored) {
            return false;
        }

        if (filter.value === 'unmonitored' && item.monitored) {
            return false;
        }

        if (filter.value === 'missing' && !item.missing) {
            return false;
        }

        return needle === '' || item.title.toLowerCase().includes(needle);
    });

    return [...rows].sort((a, b) => {
        if (sort.value === 'year') {
            return (
                (b.year ?? 0) - (a.year ?? 0) || a.title.localeCompare(b.title)
            );
        }

        if (sort.value === 'size') {
            return (
                b.size_bytes - a.size_bytes || a.title.localeCompare(b.title)
            );
        }

        return a.title.localeCompare(b.title);
    });
});

const {
    ids: selectedIds,
    count: selectedCount,
    isSelected,
    toggle: toggleSelected,
    setAll: setAllSelected,
    clear: clearSelection,
    retain: retainSelection,
} = useBulkSelection<number>([filter, query]);

const visibleIds = computed<number[]>(() =>
    visible.value.map((item) => item.id),
);

const selectedOnPage = computed(
    () => visibleIds.value.filter((id) => isSelected(id)).length,
);

const bulkBusy = ref(false);

// Retain against the rows on screen, not every loaded row: a reload that
// moves a selected title out of the active filter drops it, so a bulk
// request never names a title the user can no longer see.
watch(visibleIds, (ids) => retainSelection(ids));

// A blurred poster sits inside the card link with overlays (checkbox, pill)
// as its siblings: the card reveals it while the link has focus or the
// pointer is anywhere over the poster area, overlays included.
const focusedCard = ref<number | null>(null);
const hoveredCard = ref<number | null>(null);

function onCardPointerEnter(event: PointerEvent, id: number): void {
    if (event.pointerType !== 'touch') {
        hoveredCard.value = id;
    }
}

function bulkDone(summary: BulkSummary): void {
    clearSelection();
    focusAfterBulk();

    if (summary.started + summary.queued > 0) {
        router.reload({ only: ['library'] });
    }
}

function sync(): void {
    if (syncing.value) {
        return;
    }

    syncing.value = true;
    router.reload({
        only: ['library', 'qualityProfiles'],
        onFinish: () => {
            syncing.value = false;
        },
    });
}
</script>

<template>
    <Head title="Whisparr" />

    <div class="flex flex-col gap-4 p-5">
        <div class="flex items-end justify-between gap-3">
            <div>
                <div class="mb-1.5 flex items-center gap-2">
                    <SvcChip id="whisparr" label="Whisparr" />
                    <span class="text-fg-subtle">/</span>
                    <span class="text-[13px] text-muted-foreground"
                        >Library</span
                    >
                </div>
                <h1 class="text-[22px] font-semibold tracking-tight">
                    Whisparr
                </h1>
                <p
                    v-if="connection && library && !library.error"
                    class="mt-1 text-[13px] text-muted-foreground"
                >
                    {{ counts.all }}
                    {{ connection.version === 'v2' ? 'sites' : 'titles' }} ·
                    {{ counts.monitored }} monitored ·
                    {{ counts.missing }} missing
                </p>
                <Skeleton
                    v-else-if="connection && !library"
                    class="mt-1 h-4 w-48"
                />
            </div>
            <div v-if="connection" class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    class="h-7 gap-1.5 text-xs"
                    :disabled="syncing"
                    @click="sync"
                >
                    <RefreshCcw
                        class="size-3.5"
                        :class="{ 'animate-spin': syncing }"
                    />Sync
                </Button>
                <OpenInServiceButton
                    :href="connection.url"
                    label="Open Whisparr"
                />
            </div>
        </div>

        <div
            v-if="!connection"
            class="rounded-xl border border-border bg-card p-8 text-center text-sm text-muted-foreground"
            data-whisparr-empty
        >
            No active Whisparr connection. Add one in Admin → Connections to
            browse it here.
        </div>

        <template v-else>
            <div
                class="flex flex-wrap items-center gap-3 rounded-xl border border-border bg-card p-3"
                data-whisparr-toolbar
            >
                <div
                    class="flex h-8 min-w-[240px] flex-1 items-center gap-2 rounded-md border border-border bg-bg-elev px-3"
                >
                    <Search class="size-3.5 text-fg-subtle" />
                    <input
                        v-model="query"
                        :placeholder="`Search ${counts.all} titles…`"
                        class="flex-1 bg-transparent text-[13px] outline-none placeholder:text-fg-subtle"
                        data-whisparr-search
                    />
                </div>

                <div
                    class="flex items-center gap-1 rounded-md border border-border bg-bg-elev p-0.5"
                >
                    <button
                        v-for="option in FILTERS"
                        :key="option.id"
                        type="button"
                        :data-whisparr-filter="option.id"
                        :class="
                            cn(
                                'inline-flex h-6 items-center rounded px-2 text-xs font-medium transition-colors',
                                filter === option.id
                                    ? 'bg-accent text-accent-foreground'
                                    : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground',
                            )
                        "
                        @click="filter = option.id"
                    >
                        {{ option.label }}
                        <span
                            class="font-mono-tabular ml-1 text-[10.5px] opacity-70"
                            >{{ counts[option.id] }}</span
                        >
                    </button>
                </div>

                <Select v-model="sort">
                    <SelectTrigger
                        class="h-7 w-28 text-xs"
                        data-whisparr-sort-trigger
                    >
                        <SelectValue placeholder="Sort" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in SORTS"
                            :key="option.id"
                            :value="option.id"
                            :data-whisparr-sort-option="option.id"
                        >
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>

                <BulkSelectAll
                    v-if="library && !library.error"
                    :selected-count="selectedOnPage"
                    :page-count="visibleIds.length"
                    :disabled="bulkBusy"
                    @toggle="(value) => setAllSelected(visibleIds, value)"
                />
            </div>

            <div
                v-if="library?.error"
                class="rounded-xl border border-destructive/40 bg-destructive/10 p-4 text-sm text-destructive"
                data-whisparr-error
            >
                {{ library.error }}
            </div>

            <div
                v-else-if="library"
                class="grid gap-[18px]"
                style="
                    grid-template-columns: repeat(
                        auto-fill,
                        minmax(150px, 1fr)
                    );
                "
                data-whisparr-grid
            >
                <Link
                    v-for="item in visible"
                    :key="item.id"
                    :href="WhisparrController.show.url(item.id)"
                    prefetch
                    class="group flex flex-col gap-2"
                    :data-whisparr-card="item.id"
                    @focusin="focusedCard = item.id"
                    @focusout="focusedCard = null"
                >
                    <div
                        class="relative"
                        @pointerenter="
                            (event) => onCardPointerEnter(event, item.id)
                        "
                        @pointerleave="hoveredCard = null"
                    >
                        <Poster
                            :hint="item.title.toLowerCase().slice(0, 12)"
                            :src="item.poster_url"
                            :blurred="blurPosters"
                            :focusable="false"
                            :revealed="
                                focusedCard === item.id ||
                                hoveredCard === item.id
                            "
                            size="full"
                        />
                        <Pill
                            v-if="!item.monitored"
                            class="absolute bottom-2 left-2 border-transparent bg-black/55 text-white/70"
                        >
                            Unmonitored
                        </Pill>
                        <Pill
                            v-else-if="item.missing"
                            class="absolute bottom-2 left-2 border-transparent bg-black/55 text-white/70"
                        >
                            missing
                        </Pill>
                        <BulkCheckbox
                            class="absolute right-2 bottom-2 rounded bg-black/55 p-1"
                            :checked="isSelected(item.id)"
                            :disabled="bulkBusy"
                            :label="`Select ${item.title}`"
                            :data-bulk-select="item.id"
                            @update:checked="
                                (value) => toggleSelected(item.id, value)
                            "
                        />
                    </div>
                    <div>
                        <div
                            class="text-[13px] leading-tight font-medium text-pretty group-hover:text-accent"
                            data-whisparr-title
                        >
                            {{ item.title }}
                        </div>
                        <div
                            class="font-mono-tabular mt-0.5 flex justify-between text-[10.5px] text-fg-subtle"
                        >
                            <span>{{ item.year ?? '—' }}</span>
                            <span>{{ formatBytes(item.size_bytes) }}</span>
                        </div>
                    </div>
                </Link>
                <div
                    v-if="visible.length === 0"
                    class="col-span-full py-8 text-center text-sm text-fg-subtle"
                    data-whisparr-no-match
                >
                    {{
                        items.length === 0
                            ? 'Whisparr has no titles yet.'
                            : 'Nothing matches these filters.'
                    }}
                </div>
            </div>

            <div
                v-else
                class="grid gap-[18px]"
                style="
                    grid-template-columns: repeat(
                        auto-fill,
                        minmax(150px, 1fr)
                    );
                "
            >
                <div
                    v-for="n in 8"
                    :key="`skel-${n}`"
                    class="flex flex-col gap-2"
                >
                    <Skeleton class="aspect-[2/3] w-full rounded-md" />
                    <Skeleton class="h-3 w-3/4" />
                    <Skeleton class="h-2 w-1/2" />
                </div>
            </div>

            <BulkActionBar
                :count="selectedCount"
                :busy="bulkBusy"
                @clear="clearSelection()"
            >
                <template #default="{ disabled }">
                    <LibraryBulkActions
                        v-model:busy="bulkBusy"
                        :endpoint="WhisparrActionController.bulk.url()"
                        :target="{ service_connection_id: connection.id }"
                        :ids="selectedIds"
                        :disabled="disabled"
                        :quality-profiles="qualityProfiles?.items"
                        :quality-profiles-error="qualityProfiles?.error"
                        noun="titles"
                        @done="bulkDone"
                    />
                </template>
            </BulkActionBar>
        </template>
    </div>
</template>
