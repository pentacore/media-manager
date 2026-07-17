<script setup lang="ts">
import { Deferred, Head, router } from '@inertiajs/vue3';
import {
    ChevronLeft,
    ChevronRight,
    ExternalLink,
    Loader2,
    Sprout,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Poster, StatusPill, SvcChip } from '@/components/mm';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import MatchDialog from './MatchDialog.vue';
import AnimeController from '@/actions/App/Http/Controllers/Media/AnimeController';
import { request as requestAnime } from '@/actions/App/Http/Controllers/Media/AnimeController';
import { dashboard } from '@/routes';
import type { AnimeAirStatus } from '@/typefinder/enums/AnimeAirStatus';
import type { AnimeFormat } from '@/typefinder/enums/AnimeFormat';
import type { AnimeSeason } from '@/typefinder/enums/AnimeSeason';

type EntryStatus = 'in_library' | 'requested' | 'requestable' | 'unmapped';

interface EntryMapping {
    tmdbId: number | null;
    mediaType: string;
    tvdbId: number | null;
    tmdbSeason: number | null;
    mapped: boolean;
}

interface SeasonEntry {
    key: string;
    anilistId: number | null;
    malId: number | null;
    title: string;
    format: AnimeFormat;
    airStatus: AnimeAirStatus;
    episodes: number | null;
    posterUrl: string | null;
    startDate: string | null;
    popularity: number;
    score: number | null;
    mapping: EntryMapping;
    status: EntryStatus;
}

interface RequestingUser {
    id: number;
    label: string;
}

const props = defineProps<{
    filters: { year: number; season: AnimeSeason; source: 'anilist' | 'jikan' };
    navigation: {
        current: { year: number; season: AnimeSeason; label: string };
        previous: { year: number; season: AnimeSeason };
        next: { year: number; season: AnimeSeason };
    };
    requestingUsers?: { users: RequestingUser[]; defaultId: number | null };
    entries?: SeasonEntry[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Media', href: dashboard().url },
            { title: 'Seasonal Anime', href: AnimeController.index.url() },
        ],
    },
});

// ── Season / source navigation ────────────────────────────────────────
function navigateTo(year: number, season: AnimeSeason): void {
    router.get(
        AnimeController.index.url({
            query: { year, season, source: props.filters.source },
        }),
        {},
        { preserveState: true, preserveScroll: true },
    );
}

function setSource(source: 'anilist' | 'jikan'): void {
    if (source === props.filters.source) {
        return;
    }

    router.get(
        AnimeController.index.url({
            query: {
                year: props.filters.year,
                season: props.filters.season,
                source,
            },
        }),
        {},
        { preserveState: true, preserveScroll: true },
    );
}

const SEASONS: { value: AnimeSeason; label: string }[] = [
    { value: 'winter', label: 'Winter' },
    { value: 'spring', label: 'Spring' },
    { value: 'summer', label: 'Summer' },
    { value: 'fall', label: 'Fall' },
];

const NOW_YEAR = new Date().getFullYear();
const YEARS = Array.from({ length: NOW_YEAR + 1 - 1960 + 1 }, (_, i) =>
    String(NOW_YEAR + 1 - i),
);

const yearModel = computed<string>({
    get: () => String(props.filters.year),
    set: (value) => navigateTo(Number(value), props.filters.season),
});

const seasonModel = computed<string>({
    get: () => props.filters.season,
    set: (value) => navigateTo(props.filters.year, value as AnimeSeason),
});

// ── Requesting user ───────────────────────────────────────────────────
const selectedUserId = ref<string>('');

const requestingReady = computed(() => Boolean(props.requestingUsers));

// Once the deferred users arrive, default to the email-matched id.
const resolvedUserId = computed<number | null>(() => {
    if (selectedUserId.value !== '') {
        return Number(selectedUserId.value);
    }

    return props.requestingUsers?.defaultId ?? null;
});

const userSelectValue = computed<string>({
    get: () =>
        selectedUserId.value !== ''
            ? selectedUserId.value
            : props.requestingUsers?.defaultId != null
              ? String(props.requestingUsers.defaultId)
              : '',
    set: (value) => {
        selectedUserId.value = value;
    },
});

// ── Client-side sort / filter ─────────────────────────────────────────
type SortKey = 'popularity' | 'title' | 'startDate' | 'score';

const SORTS: { value: SortKey; label: string }[] = [
    { value: 'popularity', label: 'Popularity' },
    { value: 'title', label: 'Title' },
    { value: 'startDate', label: 'Start date' },
    { value: 'score', label: 'Score' },
];

const sortKey = ref<SortKey>('popularity');

const FORMATS: { value: AnimeFormat; label: string }[] = [
    { value: 'tv', label: 'TV' },
    { value: 'movie', label: 'Movie' },
    { value: 'ova', label: 'OVA' },
    { value: 'ona', label: 'ONA' },
    { value: 'special', label: 'Special' },
    { value: 'music', label: 'Music' },
];

const AIR_STATUSES: { value: AnimeAirStatus; label: string }[] = [
    { value: 'airing', label: 'Airing' },
    { value: 'upcoming', label: 'Upcoming' },
    { value: 'finished', label: 'Finished' },
];

const activeFormats = ref<Set<AnimeFormat>>(new Set());
const activeAirStatuses = ref<Set<AnimeAirStatus>>(new Set());
const hideInLibrary = ref(false);

function toggleFormat(format: AnimeFormat): void {
    const next = new Set(activeFormats.value);

    if (next.has(format)) {
        next.delete(format);
    } else {
        next.add(format);
    }

    activeFormats.value = next;
}

function toggleAirStatus(status: AnimeAirStatus): void {
    const next = new Set(activeAirStatuses.value);

    if (next.has(status)) {
        next.delete(status);
    } else {
        next.add(status);
    }

    activeAirStatuses.value = next;
}

// Cards flipped to "requested" once the backend confirms the request
// succeeded via the `requestOutcome` flash payload (see the listener below).
const requestedKeys = ref<Set<string>>(new Set());

function effectiveStatus(entry: SeasonEntry): EntryStatus {
    return requestedKeys.value.has(entry.key) ? 'requested' : entry.status;
}

interface RequestOutcome {
    ok: boolean;
    tmdbId: number;
    mediaType: string;
}

// Inertia v3 delivers flash data through the `flash` router event, not through
// page props — mirror the pattern used in lib/flashToast.ts. The controller
// turns a Seerr failure into a normal `back()` redirect (a *successful* Inertia
// visit), so we must only flip a card when `ok === true`.
let stopFlashListener: (() => void) | null = null;

onMounted(() => {
    stopFlashListener = router.on('flash', (event) => {
        const outcome = (event as CustomEvent).detail?.flash?.requestOutcome as
            RequestOutcome | undefined;

        if (!outcome || !outcome.ok) {
            return;
        }

        const flipped = new Set(requestedKeys.value);

        for (const entry of props.entries ?? []) {
            if (
                entry.mapping.tmdbId === outcome.tmdbId &&
                entry.mapping.mediaType === outcome.mediaType
            ) {
                flipped.add(entry.key);
            }
        }

        requestedKeys.value = flipped;
    });
});

onBeforeUnmount(() => {
    stopFlashListener?.();
    stopFlashListener = null;
});

const visibleEntries = computed<SeasonEntry[]>(() => {
    const entries = props.entries ?? [];

    const filtered = entries.filter((entry) => {
        if (
            activeFormats.value.size > 0 &&
            !activeFormats.value.has(entry.format)
        ) {
            return false;
        }

        if (
            activeAirStatuses.value.size > 0 &&
            !activeAirStatuses.value.has(entry.airStatus)
        ) {
            return false;
        }

        if (hideInLibrary.value && effectiveStatus(entry) === 'in_library') {
            return false;
        }

        return true;
    });

    const sorted = [...filtered];

    sorted.sort((a, b) => {
        switch (sortKey.value) {
            case 'title':
                return a.title.localeCompare(b.title);
            case 'startDate':
                return (a.startDate ?? '').localeCompare(b.startDate ?? '');
            case 'score':
                return (b.score ?? -1) - (a.score ?? -1);
            case 'popularity':
            default:
                return b.popularity - a.popularity;
        }
    });

    return sorted;
});

// ── Presentation helpers ──────────────────────────────────────────────
const FORMAT_LABELS: Record<AnimeFormat, string> = {
    tv: 'TV',
    movie: 'Movie',
    ova: 'OVA',
    ona: 'ONA',
    special: 'Special',
    music: 'Music',
};

function formatLabel(format: AnimeFormat): string {
    return FORMAT_LABELS[format] ?? format;
}

function metaLine(entry: SeasonEntry): string {
    const parts = [formatLabel(entry.format)];

    if (entry.episodes) {
        parts.push(`${entry.episodes} ep`);
    }

    if (entry.score != null) {
        parts.push(`★ ${entry.score}`);
    }

    return parts.join(' · ');
}

const STATUS_META: Record<EntryStatus, { status: string; label: string }> = {
    in_library: { status: 'available', label: 'In library' },
    requested: { status: 'approved', label: 'Requested' },
    requestable: { status: 'ok', label: 'Available' },
    unmapped: { status: 'warn', label: 'Unmapped' },
};

function anilistUrl(entry: SeasonEntry): string | null {
    return entry.anilistId
        ? `https://anilist.co/anime/${entry.anilistId}`
        : null;
}

// ── Request action ────────────────────────────────────────────────────
const requestingKeys = ref<Set<string>>(new Set());

function isRequesting(entry: SeasonEntry): boolean {
    return requestingKeys.value.has(entry.key);
}

function requestEntry(entry: SeasonEntry): void {
    if (entry.mapping.tmdbId === null || isRequesting(entry)) {
        return;
    }

    const busy = new Set(requestingKeys.value);
    busy.add(entry.key);
    requestingKeys.value = busy;

    router.post(
        requestAnime.url(),
        {
            tmdbId: entry.mapping.tmdbId,
            mediaType: entry.mapping.mediaType,
            tmdbSeason: entry.mapping.tmdbSeason,
            startDate: entry.startDate,
            userId: resolvedUserId.value,
        },
        {
            preserveScroll: true,
            preserveState: true,
            // The card is only flipped to "requested" when the backend flashes a
            // successful `requestOutcome` (handled by the flash listener above),
            // never optimistically — a Seerr failure still resolves as a
            // successful Inertia visit.
            onFinish: () => {
                const next = new Set(requestingKeys.value);
                next.delete(entry.key);
                requestingKeys.value = next;
            },
        },
    );
}

// ── Match dialog ──────────────────────────────────────────────────────
const matchDialogOpen = ref(false);
const matchEntry = ref<SeasonEntry | null>(null);

function openMatch(entry: SeasonEntry): void {
    matchEntry.value = entry;
    matchDialogOpen.value = true;
}

const matchEntryContext = computed(() =>
    matchEntry.value
        ? {
              title: matchEntry.value.title,
              anilistId: matchEntry.value.anilistId,
              malId: matchEntry.value.malId,
              startDate: matchEntry.value.startDate,
          }
        : null,
);
</script>

<template>
    <Head title="Seasonal Anime" />

    <div class="flex flex-col gap-4 p-5">
        <!-- Hero + season navigator -->
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <div class="mb-1.5 flex items-center gap-2">
                    <SvcChip id="seerr" />
                    <span class="text-fg-subtle">/</span>
                    <span class="text-[13px] text-muted-foreground"
                        >Seasonal Anime</span
                    >
                </div>
                <div class="flex items-center gap-3">
                    <Button
                        variant="outline"
                        size="icon"
                        class="size-8"
                        title="Previous season"
                        @click="
                            navigateTo(
                                navigation.previous.year,
                                navigation.previous.season,
                            )
                        "
                    >
                        <ChevronLeft class="size-4" />
                    </Button>
                    <h1
                        class="flex items-center gap-2 text-[22px] leading-tight font-semibold tracking-tight"
                    >
                        <Sprout class="size-5 text-accent" />
                        {{ navigation.current.label }}
                    </h1>
                    <Button
                        variant="outline"
                        size="icon"
                        class="size-8"
                        title="Next season"
                        @click="
                            navigateTo(
                                navigation.next.year,
                                navigation.next.season,
                            )
                        "
                    >
                        <ChevronRight class="size-4" />
                    </Button>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Select v-model="seasonModel">
                    <SelectTrigger class="h-7 w-28 text-xs">
                        <SelectValue placeholder="Season" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="season in SEASONS"
                            :key="season.value"
                            :value="season.value"
                        >
                            {{ season.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <Select v-model="yearModel">
                    <SelectTrigger class="h-7 w-24 text-xs">
                        <SelectValue placeholder="Year" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="year in YEARS"
                            :key="year"
                            :value="year"
                        >
                            {{ year }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
        </div>

        <!-- Controls row -->
        <div class="flex flex-wrap items-center justify-between gap-3">
            <!-- Source toggle -->
            <div class="flex flex-col gap-1">
                <div
                    class="inline-flex items-center gap-0.5 rounded-md border border-border bg-card p-0.5"
                >
                    <button
                        type="button"
                        :class="
                            cn(
                                'inline-flex h-6 items-center rounded px-2.5 text-xs font-medium transition-colors',
                                filters.source === 'anilist'
                                    ? 'bg-accent text-accent-foreground'
                                    : 'text-muted-foreground hover:text-foreground',
                            )
                        "
                        @click="setSource('anilist')"
                    >
                        AniList
                    </button>
                    <button
                        type="button"
                        :class="
                            cn(
                                'inline-flex h-6 items-center rounded px-2.5 text-xs font-medium transition-colors',
                                filters.source === 'jikan'
                                    ? 'bg-accent text-accent-foreground'
                                    : 'text-muted-foreground hover:text-foreground',
                            )
                        "
                        @click="setSource('jikan')"
                    >
                        Jikan
                    </button>
                </div>
                <p class="text-[11px] text-fg-subtle">
                    {{
                        filters.source === 'jikan'
                            ? 'Jikan lists in default season order.'
                            : 'AniList sorts by popularity.'
                    }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <!-- Requesting as -->
                <div class="flex items-center gap-2">
                    <span class="text-xs text-muted-foreground"
                        >Requesting as</span
                    >
                    <Skeleton v-if="!requestingReady" class="h-7 w-40" />
                    <Select v-else v-model="userSelectValue">
                        <SelectTrigger class="h-7 w-40 text-xs">
                            <SelectValue placeholder="Select user" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="user in requestingUsers?.users ?? []"
                                :key="user.id"
                                :value="String(user.id)"
                            >
                                {{ user.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <!-- Sort -->
                <Select v-model="sortKey">
                    <SelectTrigger class="h-7 w-36 text-xs">
                        <SelectValue placeholder="Sort" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="sort in SORTS"
                            :key="sort.value"
                            :value="sort.value"
                        >
                            Sort: {{ sort.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
        </div>

        <!-- Filter chips -->
        <div class="flex flex-wrap items-center gap-2">
            <button
                v-for="format in FORMATS"
                :key="`fmt-${format.value}`"
                type="button"
                :class="
                    cn(
                        'inline-flex h-7 items-center rounded-md px-2.5 text-xs font-medium transition-colors',
                        activeFormats.has(format.value)
                            ? 'bg-accent text-accent-foreground'
                            : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground',
                    )
                "
                @click="toggleFormat(format.value)"
            >
                {{ format.label }}
            </button>

            <span class="mx-1 h-4 w-px bg-border" aria-hidden="true" />

            <button
                v-for="air in AIR_STATUSES"
                :key="`air-${air.value}`"
                type="button"
                :class="
                    cn(
                        'inline-flex h-7 items-center rounded-md px-2.5 text-xs font-medium transition-colors',
                        activeAirStatuses.has(air.value)
                            ? 'bg-accent text-accent-foreground'
                            : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground',
                    )
                "
                @click="toggleAirStatus(air.value)"
            >
                {{ air.label }}
            </button>

            <span class="mx-1 h-4 w-px bg-border" aria-hidden="true" />

            <button
                type="button"
                :class="
                    cn(
                        'inline-flex h-7 items-center rounded-md px-2.5 text-xs font-medium transition-colors',
                        hideInLibrary
                            ? 'bg-accent text-accent-foreground'
                            : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground',
                    )
                "
                @click="hideInLibrary = !hideInLibrary"
            >
                Hide in library
            </button>
        </div>

        <!-- Grid -->
        <Deferred data="entries">
            <template #fallback>
                <div
                    class="grid gap-4"
                    style="
                        grid-template-columns: repeat(
                            auto-fill,
                            minmax(180px, 1fr)
                        );
                    "
                >
                    <div
                        v-for="n in 12"
                        :key="`skel-${n}`"
                        class="flex flex-col gap-2"
                    >
                        <Skeleton class="aspect-[2/3] w-full rounded-md" />
                        <Skeleton class="h-4 w-3/4" />
                        <Skeleton class="h-3 w-1/2" />
                    </div>
                </div>
            </template>

            <div
                v-if="visibleEntries.length > 0"
                class="grid gap-4"
                style="
                    grid-template-columns: repeat(
                        auto-fill,
                        minmax(180px, 1fr)
                    );
                "
            >
                <div
                    v-for="entry in visibleEntries"
                    :key="entry.key"
                    :class="
                        cn(
                            'flex flex-col gap-2 rounded-xl border border-border bg-card p-2.5',
                            effectiveStatus(entry) === 'unmapped' &&
                                'opacity-60',
                        )
                    "
                >
                    <Poster
                        :hint="entry.title.toLowerCase().slice(0, 12)"
                        :src="entry.posterUrl"
                        size="full"
                    />

                    <div class="flex items-start justify-between gap-2">
                        <h3
                            class="line-clamp-2 text-[13px] leading-tight font-semibold text-pretty"
                            :title="entry.title"
                        >
                            {{ entry.title }}
                        </h3>
                        <StatusPill
                            :status="STATUS_META[effectiveStatus(entry)].status"
                            :label="STATUS_META[effectiveStatus(entry)].label"
                        />
                    </div>

                    <p class="text-[11px] text-muted-foreground">
                        {{ metaLine(entry) }}
                    </p>

                    <div class="mt-auto flex items-center gap-1.5 pt-1">
                        <template
                            v-if="effectiveStatus(entry) === 'requestable'"
                        >
                            <Button
                                size="sm"
                                class="h-7 flex-1 text-xs"
                                :disabled="isRequesting(entry)"
                                @click="requestEntry(entry)"
                            >
                                <Loader2
                                    v-if="isRequesting(entry)"
                                    class="mr-1 size-3.5 animate-spin"
                                />
                                Request
                            </Button>
                        </template>

                        <template
                            v-else-if="
                                effectiveStatus(entry) === 'in_library' ||
                                effectiveStatus(entry) === 'requested'
                            "
                        >
                            <span
                                class="inline-flex h-7 flex-1 items-center justify-center rounded-md border border-border bg-bg-elev text-xs text-muted-foreground"
                            >
                                {{ STATUS_META[effectiveStatus(entry)].label }}
                            </span>
                        </template>

                        <template v-else>
                            <Button
                                size="sm"
                                variant="outline"
                                class="h-7 flex-1 text-xs"
                                @click="openMatch(entry)"
                            >
                                Find match
                            </Button>
                            <a
                                v-if="anilistUrl(entry)"
                                :href="anilistUrl(entry)!"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-bg-hover"
                                title="Open on AniList"
                            >
                                <ExternalLink class="size-3.5" />
                            </a>
                        </template>
                    </div>
                </div>
            </div>

            <div
                v-else
                class="rounded-xl border border-border bg-card p-12 text-center text-sm text-fg-subtle"
            >
                No anime found for this season and filters.
            </div>
        </Deferred>

        <MatchDialog
            v-model:open="matchDialogOpen"
            :entry="matchEntryContext"
            :user-id="resolvedUserId"
        />
    </div>
</template>
