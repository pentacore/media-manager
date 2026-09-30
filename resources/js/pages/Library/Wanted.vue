<script setup lang="ts">
import { Deferred, Head, Link, router } from '@inertiajs/vue3';
import { AlertCircle, ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed, ref } from 'vue';
import MediaActionController from '@/actions/App/Http/Controllers/Library/MediaActionController';
import WantedController from '@/actions/App/Http/Controllers/Library/WantedController';
import { SearchButton } from '@/components/library-actions';
import { SvcChip } from '@/components/mm';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { useDateTime } from '@/composables/useDateTime';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import type { MediaSearchCommand } from '@/types';

type Service = 'sonarr' | 'radarr';

interface WantedRow {
    id: number;
    series_id?: number;
    title: string;
    episode_title?: string | null;
    code?: string;
    year?: number | null;
    air_date_utc: string | null;
    library_url: string | null;
}

interface WantedSection {
    connected: boolean;
    service_connection_id: number | null;
    records: WantedRow[];
    meta: {
        current_page: number;
        last_page: number;
        total: number;
        per_page: number;
    };
    error: string | null;
}

const props = defineProps<{
    filters: {
        tab: 'missing' | 'cutoff';
        monitored: boolean;
        sonarr_page: number;
        radarr_page: number;
    };
    sonarr?: WantedSection;
    radarr?: WantedSection;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Wanted', href: WantedController.url() },
        ],
    },
});

const { formatDate } = useDateTime();
const confirming = ref<Service | null>(null);
const submitting = ref(false);

const SECTIONS: { service: Service; label: string }[] = [
    { service: 'sonarr', label: 'Episodes' },
    { service: 'radarr', label: 'Movies' },
];

const bulkCommand = computed<Record<Service, MediaSearchCommand>>(() =>
    props.filters.tab === 'missing'
        ? { sonarr: 'missing_episode_search', radarr: 'missing_movies_search' }
        : {
              sonarr: 'cutoff_unmet_episode_search',
              radarr: 'cutoff_unmet_movies_search',
          },
);

function section(service: Service): WantedSection | undefined {
    return service === 'sonarr' ? props.sonarr : props.radarr;
}

function visit(
    overrides: Record<string, string | number>,
    only?: Service[],
): void {
    router.get(
        WantedController.url(),
        {
            tab: props.filters.tab,
            monitored: props.filters.monitored ? 1 : 0,
            sonarr_page: props.filters.sonarr_page,
            radarr_page: props.filters.radarr_page,
            ...overrides,
        },
        { preserveScroll: true, only },
    );
}

// Paging one section reloads only that section's deferred prop — the other
// section's already-loaded list has no reason to refetch.
function goToPage(service: Service, page: number): void {
    visit({ [`${service}_page`]: page }, [service]);
}

function searchAll(): void {
    const service = confirming.value;
    const connectionId = service
        ? section(service)?.service_connection_id
        : null;

    if (!service || !connectionId || submitting.value) {
        return;
    }

    submitting.value = true;
    router.post(
        MediaActionController.search.url(),
        {
            service,
            service_connection_id: connectionId,
            command: bulkCommand.value[service],
        },
        {
            preserveScroll: true,
            onFinish: () => {
                submitting.value = false;
                confirming.value = null;
            },
        },
    );
}

function tabClass(active: boolean): string {
    return cn(
        'inline-flex h-7 items-center rounded-md px-2.5 text-xs font-medium transition-colors',
        active
            ? 'bg-accent text-accent-foreground'
            : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground',
    );
}
</script>

<template>
    <Head title="Wanted" />

    <div class="flex flex-col gap-4 p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-[22px] leading-tight font-semibold tracking-tight">
                Wanted
            </h1>
            <div class="flex flex-wrap items-center gap-1.5">
                <button
                    type="button"
                    :class="tabClass(filters.tab === 'missing')"
                    data-wanted-tab="missing"
                    @click="
                        visit({
                            tab: 'missing',
                            sonarr_page: 1,
                            radarr_page: 1,
                        })
                    "
                >
                    Missing
                </button>
                <button
                    type="button"
                    :class="tabClass(filters.tab === 'cutoff')"
                    data-wanted-tab="cutoff"
                    @click="
                        visit({ tab: 'cutoff', sonarr_page: 1, radarr_page: 1 })
                    "
                >
                    Cutoff unmet
                </button>
                <span class="mx-1 h-4 w-px bg-border" />
                <button
                    type="button"
                    :class="tabClass(filters.monitored)"
                    :aria-pressed="filters.monitored"
                    data-wanted-monitored
                    @click="
                        visit({
                            monitored: filters.monitored ? 0 : 1,
                            sonarr_page: 1,
                            radarr_page: 1,
                        })
                    "
                >
                    {{ filters.monitored ? 'Monitored' : 'Unmonitored' }}
                </button>
            </div>
        </div>

        <section
            v-for="entry in SECTIONS"
            :key="entry.service"
            class="overflow-hidden rounded-xl border border-border bg-card"
            :data-wanted-section="entry.service"
        >
            <div
                class="flex items-center justify-between border-b border-border px-4 py-3"
            >
                <div class="flex items-center gap-2">
                    <SvcChip :id="entry.service" />
                    <span
                        class="text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                        >{{ entry.label }}</span
                    >
                    <span
                        v-if="section(entry.service)"
                        class="font-mono-tabular text-[12px] text-muted-foreground"
                        >{{ section(entry.service)?.meta.total }}</span
                    >
                </div>
                <Button
                    v-if="
                        filters.monitored &&
                        section(entry.service)?.service_connection_id &&
                        !section(entry.service)?.error
                    "
                    variant="outline"
                    size="sm"
                    class="h-7 text-xs"
                    data-wanted-search-all
                    @click="confirming = entry.service"
                >
                    {{
                        filters.tab === 'missing'
                            ? 'Search all missing'
                            : 'Search all cutoff unmet'
                    }}
                </Button>
            </div>

            <Deferred :data="entry.service">
                <template #fallback>
                    <div class="space-y-2 p-4">
                        <Skeleton
                            v-for="n in 4"
                            :key="n"
                            class="h-10 w-full rounded-md"
                        />
                    </div>
                </template>

                <Alert
                    v-if="section(entry.service)?.error"
                    variant="destructive"
                    class="m-4 w-auto"
                >
                    <AlertCircle class="size-4" />
                    <AlertTitle
                        >{{
                            entry.service === 'sonarr' ? 'Sonarr' : 'Radarr'
                        }}
                        unavailable</AlertTitle
                    >
                    <AlertDescription>{{
                        section(entry.service)?.error
                    }}</AlertDescription>
                </Alert>
                <p
                    v-else-if="!section(entry.service)?.connected"
                    class="px-4 py-6 text-[13px] text-muted-foreground"
                >
                    No active
                    {{ entry.service === 'sonarr' ? 'Sonarr' : 'Radarr' }}
                    connection.
                </p>
                <p
                    v-else-if="section(entry.service)?.records.length === 0"
                    class="px-4 py-6 text-[13px] text-muted-foreground"
                >
                    Nothing wanted here.
                </p>
                <div v-else>
                    <div
                        v-for="(row, i) in section(entry.service)?.records ??
                        []"
                        :key="row.id"
                        :class="[
                            'flex items-center gap-3 px-4 py-2.5',
                            i > 0 && 'border-t border-border',
                        ]"
                        :data-wanted-row="`${entry.service}-${row.id}`"
                    >
                        <div class="min-w-0 flex-1">
                            <component
                                :is="row.library_url ? Link : 'span'"
                                :href="row.library_url ?? undefined"
                                class="block truncate text-[13px] font-medium"
                            >
                                {{ row.title
                                }}<template v-if="row.year">
                                    ({{ row.year }})</template
                                >
                            </component>
                            <div
                                class="truncate text-[11.5px] text-muted-foreground"
                            >
                                <template v-if="row.code"
                                    ><span class="font-mono-tabular">{{
                                        row.code
                                    }}</span
                                    ><template v-if="row.episode_title">
                                        · {{ row.episode_title }}</template
                                    >
                                    ·
                                </template>
                                {{
                                    row.air_date_utc
                                        ? formatDate(row.air_date_utc)
                                        : 'No date'
                                }}
                            </div>
                        </div>
                        <SearchButton
                            v-if="entry.service === 'sonarr'"
                            service="sonarr"
                            :connection-id="
                                section('sonarr')!.service_connection_id!
                            "
                            command="episode_search"
                            :series-id="row.series_id ?? null"
                            :episode-ids="[row.id]"
                        />
                        <SearchButton
                            v-else
                            service="radarr"
                            :connection-id="
                                section('radarr')!.service_connection_id!
                            "
                            command="movies_search"
                            :movie-ids="[row.id]"
                        />
                    </div>
                </div>

                <div
                    v-if="(section(entry.service)?.meta.last_page ?? 1) > 1"
                    class="flex items-center justify-between border-t border-border px-4 py-2"
                >
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="
                            section(entry.service)!.meta.current_page <= 1
                        "
                        :data-wanted-prev="entry.service"
                        @click="
                            goToPage(
                                entry.service,
                                section(entry.service)!.meta.current_page - 1,
                            )
                        "
                    >
                        <ChevronLeft class="size-4" /> Previous
                    </Button>
                    <span class="text-[12px] text-muted-foreground"
                        >Page {{ section(entry.service)?.meta.current_page }} of
                        {{ section(entry.service)?.meta.last_page }}</span
                    >
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="
                            section(entry.service)!.meta.current_page >=
                            section(entry.service)!.meta.last_page
                        "
                        :data-wanted-next="entry.service"
                        @click="
                            goToPage(
                                entry.service,
                                section(entry.service)!.meta.current_page + 1,
                            )
                        "
                    >
                        Next <ChevronRight class="size-4" />
                    </Button>
                </div>
            </Deferred>
        </section>
    </div>

    <Dialog
        :open="confirming !== null"
        @update:open="
            (open) => {
                if (!open) confirming = null;
            }
        "
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>
                    {{
                        filters.tab === 'missing'
                            ? 'Search for everything missing'
                            : 'Search for everything below cutoff'
                    }}
                    in {{ confirming === 'sonarr' ? 'Sonarr' : 'Radarr' }}?
                </DialogTitle>
                <DialogDescription>
                    This asks every indexer for every monitored item at once and
                    can take a while. It goes through the Action Queue.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="confirming = null"
                    >Cancel</Button
                >
                <Button
                    :disabled="submitting"
                    data-wanted-search-all-confirm
                    @click="searchAll"
                    >Start search</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
