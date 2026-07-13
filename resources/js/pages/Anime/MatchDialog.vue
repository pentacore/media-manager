<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Loader2, SearchX } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    confirmMatch,
    findMatch,
} from '@/actions/App/Http/Controllers/Media/AnimeController';
import { Poster } from '@/components/mm';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { tmdbPosterUrl } from '@/lib/tmdb';
import { cn } from '@/lib/utils';

interface MatchCandidate {
    tmdbId: number;
    mediaType: string;
    title: string;
    year: string;
    posterPath: string | null;
}

interface EntryContext {
    title: string;
    anilistId: number | null;
    malId: number | null;
    format: string;
}

const props = defineProps<{
    open: boolean;
    entry: EntryContext | null;
    userId: number | null;
}>();

const emit = defineEmits<{
    (event: 'update:open', value: boolean): void;
    (event: 'confirmed', tmdbId: number): void;
}>();

const searching = ref(false);
const confirming = ref(false);
const selectedTmdbId = ref<number | null>(null);
const searched = ref(false);
const candidates = ref<MatchCandidate[]>([]);

// Inertia v3 delivers flash data through the `flash` router event, not
// through page props — mirror the pattern used in lib/flashToast.ts.
let stopFlashListener: (() => void) | null = null;

onMounted(() => {
    stopFlashListener = router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash as
            { matchCandidates?: MatchCandidate[] } | undefined;

        if (!flash || !('matchCandidates' in flash)) {
            return;
        }

        candidates.value = Array.isArray(flash.matchCandidates)
            ? flash.matchCandidates
            : [];
    });
});

onBeforeUnmount(() => {
    stopFlashListener?.();
    stopFlashListener = null;
});

function runSearch(): void {
    if (!props.entry) {
        return;
    }

    searching.value = true;
    searched.value = false;
    selectedTmdbId.value = null;
    candidates.value = [];

    router.post(
        findMatch.url(),
        { title: props.entry.title },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                searching.value = false;
                searched.value = true;
            },
        },
    );
}

// Kick off the fuzzy search whenever the dialog opens for a fresh entry.
watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            runSearch();
        } else {
            selectedTmdbId.value = null;
            searched.value = false;
        }
    },
);

function close(): void {
    if (confirming.value) {
        return;
    }

    emit('update:open', false);
}

function confirm(): void {
    const entry = props.entry;
    const tmdbId = selectedTmdbId.value;

    if (!entry || tmdbId === null) {
        return;
    }

    confirming.value = true;

    router.post(
        confirmMatch.url(),
        {
            anilistId: entry.anilistId,
            malId: entry.malId,
            tmdbId,
            format: entry.format,
            userId: props.userId,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                emit('confirmed', tmdbId);
                emit('update:open', false);
            },
            onFinish: () => {
                confirming.value = false;
            },
        },
    );
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => !v && close()">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Find a match</DialogTitle>
                <DialogDescription v-if="entry">
                    Pick the TMDB title that matches
                    <span class="font-medium text-foreground">{{
                        entry.title
                    }}</span
                    >. We'll remember the mapping and file the request.
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="searching"
                class="flex items-center gap-2 py-8 text-sm text-muted-foreground"
            >
                <Loader2 class="size-4 animate-spin" />
                Searching TMDB…
            </div>

            <div
                v-else-if="searched && candidates.length === 0"
                class="flex flex-col items-center gap-2 py-8 text-center text-sm text-muted-foreground"
            >
                <SearchX class="size-6 opacity-60" />
                No matches found. Try requesting it manually from Search.
            </div>

            <div v-else class="flex flex-col gap-2">
                <button
                    v-for="candidate in candidates"
                    :key="`${candidate.mediaType}-${candidate.tmdbId}`"
                    type="button"
                    :class="
                        cn(
                            'flex items-center gap-3 rounded-lg border p-2.5 text-left transition-colors',
                            selectedTmdbId === candidate.tmdbId
                                ? 'border-accent bg-accent/10'
                                : 'border-border hover:bg-bg-hover',
                        )
                    "
                    @click="selectedTmdbId = candidate.tmdbId"
                >
                    <Poster
                        :hint="candidate.title.toLowerCase().slice(0, 12)"
                        :src="tmdbPosterUrl(candidate.posterPath)"
                        size="md"
                    />
                    <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span
                            class="text-sm leading-tight font-medium text-pretty"
                            >{{ candidate.title }}</span
                        >
                        <span class="text-xs text-muted-foreground">
                            {{
                                candidate.mediaType === 'movie'
                                    ? 'Movie'
                                    : 'Series'
                            }}
                            <template v-if="candidate.year">
                                · {{ candidate.year }}</template
                            >
                        </span>
                    </div>
                </button>
            </div>

            <DialogFooter>
                <Button variant="ghost" :disabled="confirming" @click="close">
                    Cancel
                </Button>
                <Button
                    :disabled="
                        confirming || searching || selectedTmdbId === null
                    "
                    @click="confirm"
                >
                    <Loader2
                        v-if="confirming"
                        class="mr-1.5 size-3.5 animate-spin"
                    />
                    Confirm &amp; request
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
