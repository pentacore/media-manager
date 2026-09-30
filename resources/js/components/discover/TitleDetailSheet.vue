<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Loader2 } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import DiscoverController from '@/actions/App/Http/Controllers/Media/DiscoverController';
import { Poster, StatusPill } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { jsonRequest } from '@/composables/useAiChat';
import { titleStatusPill } from '@/lib/seerr';
import { tmdbBackdropUrl, tmdbPosterUrl } from '@/lib/tmdb';
import type {
    DiscoverTitle,
    DiscoverTitleDetail,
    RequestingContext,
} from '@/types';

interface RequestOutcome {
    ok: boolean;
    tmdbId: number;
    mediaType: string;
}

const props = defineProps<{
    open: boolean;
    item: DiscoverTitle | null;
    requesting: RequestingContext | null | undefined;
}>();

const emit = defineEmits<{ 'update:open': [open: boolean] }>();

const detail = ref<DiscoverTitleDetail | null>(null);
const loading = ref(false);
const loadError = ref<string | null>(null);
const selectedSeasons = ref<number[]>([]);
const chosenUserId = ref<string | null>(null);
const submitting = ref(false);
let requestSeq = 0;

const pill = computed(() =>
    detail.value ? titleStatusPill(detail.value.status) : null,
);
const noAccount = computed(
    () =>
        props.requesting !== null &&
        props.requesting !== undefined &&
        !props.requesting.canChooseUser &&
        props.requesting.userId === null &&
        props.requesting.error === null,
);
// A chooser (member/admin) with no own Seerr match gets `userId: null` and
// `canChooseUser: true` (SeerrUserResolver::pickerOptions() no longer falls
// back to an arbitrary Seerr user). The server refuses a POST with no
// userId and no own match, so the button must stay disabled until the
// chooser actually picks someone — mirrors Anime/Season.vue's
// `resolvedUserId === null` guard.
const chosenUserMissing = computed(
    () =>
        props.requesting?.canChooseUser === true && chosenUserId.value === null,
);
const canRequest = computed(() => {
    if (
        !detail.value ||
        !props.requesting ||
        submitting.value ||
        chosenUserMissing.value
    ) {
        return false;
    }

    if (detail.value.media_type === 'tv') {
        return selectedSeasons.value.length > 0;
    }

    return detail.value.status === 'none';
});
const facts = computed(() => {
    const d = detail.value;

    if (!d) {
        return [] as string[];
    }

    return [
        d.year ? String(d.year) : null,
        d.media_type === 'tv' ? 'TV' : 'Movie',
        d.rating !== null ? `★ ${d.rating}` : null,
        d.runtime !== null ? `${d.runtime} min` : null,
        d.season_count !== null ? `${d.season_count} seasons` : null,
    ].filter((fact): fact is string => fact !== null);
});

async function load(item: DiscoverTitle): Promise<void> {
    const seq = ++requestSeq;
    loading.value = true;
    loadError.value = null;
    detail.value = null;

    try {
        const data = await jsonRequest<DiscoverTitleDetail>(
            'get',
            DiscoverController.title.url({
                mediaType: item.media_type,
                tmdbId: item.tmdb_id,
            }),
        );

        if (seq !== requestSeq) {
            return;
        }

        detail.value = data;
        selectedSeasons.value = data.seasons
            .filter((season) => season.requestable)
            .map((season) => season.season_number);
    } catch (error) {
        if (seq === requestSeq) {
            loadError.value =
                error instanceof Error
                    ? error.message
                    : 'Could not load this title.';
        }
    } finally {
        if (seq === requestSeq) {
            loading.value = false;
        }
    }
}

function toggleSeason(seasonNumber: number, checked: boolean): void {
    selectedSeasons.value = checked
        ? [...new Set([...selectedSeasons.value, seasonNumber])]
        : selectedSeasons.value.filter((n) => n !== seasonNumber);
}

function submit(): void {
    if (!detail.value || !canRequest.value) {
        return;
    }

    submitting.value = true;
    router.post(
        DiscoverController.request.url(),
        {
            tmdbId: detail.value.tmdb_id,
            mediaType: detail.value.media_type,
            seasons:
                detail.value.media_type === 'tv' ? selectedSeasons.value : [],
            userId:
                props.requesting?.canChooseUser && chosenUserId.value
                    ? Number(chosenUserId.value)
                    : null,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}

// Once a chooser picks a Seerr user by hand, later reloads of the
// page-level (shared-across-titles) `requesting` prop — e.g. the deferred
// refetch after submitting a request — must never overwrite that pick.
// Only opening the sheet for a (possibly different) title clears the flag
// and re-applies the context's own default.
const userPickedManually = ref(false);

function syncChosenUserFromContext(): void {
    const userId = props.requesting?.userId ?? null;
    chosenUserId.value = userId !== null ? String(userId) : null;
}

function chooseUser(next: unknown): void {
    chosenUserId.value = typeof next === 'string' ? next : null;
    userPickedManually.value = true;
}

watch(
    () => [props.open, props.item] as const,
    ([isOpen, item]) => {
        if (isOpen && item) {
            void load(item);
            userPickedManually.value = false;
            syncChosenUserFromContext();
        }
    },
    { immediate: true },
);

watch(
    () => props.requesting?.userId,
    () => {
        if (userPickedManually.value) {
            return;
        }

        syncChosenUserFromContext();
    },
);

// Seerr failures still redirect back (a successful Inertia visit), so the
// sheet only flips to "Requested" when the server flashes ok === true.
let stopFlashListener: (() => void) | null = null;

onMounted(() => {
    stopFlashListener = router.on('flash', (event) => {
        const outcome = (event as CustomEvent).detail?.flash?.requestOutcome as
            RequestOutcome | undefined;
        const current = detail.value;

        if (
            !outcome?.ok ||
            !current ||
            outcome.tmdbId !== current.tmdb_id ||
            outcome.mediaType !== current.media_type
        ) {
            return;
        }

        detail.value = {
            ...current,
            status: current.status === 'none' ? 'requested' : current.status,
            seasons: current.seasons.map((season) =>
                selectedSeasons.value.includes(season.season_number)
                    ? { ...season, status: 'requested', requestable: false }
                    : season,
            ),
        };
        selectedSeasons.value = [];
    });
});

onBeforeUnmount(() => {
    stopFlashListener?.();
    stopFlashListener = null;
});
</script>

<template>
    <Sheet :open="open" @update:open="(value) => emit('update:open', value)">
        <SheetContent
            side="right"
            class="w-full overflow-y-auto sm:max-w-lg"
            data-title-sheet
        >
            <SheetHeader>
                <SheetTitle>{{
                    detail?.title ?? item?.title ?? 'Title'
                }}</SheetTitle>
                <SheetDescription>{{
                    facts.join(' · ') || ' '
                }}</SheetDescription>
            </SheetHeader>

            <div class="space-y-4 px-4 pb-6">
                <div v-if="loading" class="space-y-3">
                    <Skeleton class="aspect-video w-full rounded-md" />
                    <Skeleton class="h-4 w-3/4" />
                    <Skeleton class="h-4 w-1/2" />
                </div>

                <p v-else-if="loadError" class="text-[13px] text-destructive">
                    {{ loadError }}
                </p>

                <template v-else-if="detail">
                    <img
                        v-if="detail.backdrop_path"
                        :src="tmdbBackdropUrl(detail.backdrop_path) ?? ''"
                        :alt="detail.title"
                        class="aspect-video w-full rounded-md border border-border object-cover"
                    />
                    <div class="flex gap-4">
                        <Poster
                            :hint="detail.title"
                            size="lg"
                            :src="tmdbPosterUrl(detail.poster_path)"
                        />
                        <div class="min-w-0 space-y-2">
                            <StatusPill
                                v-if="pill"
                                :status="pill.status"
                                :label="pill.label"
                            />
                            <p
                                v-if="detail.overview"
                                class="text-[13px] leading-relaxed text-muted-foreground"
                            >
                                {{ detail.overview }}
                            </p>
                        </div>
                    </div>

                    <fieldset
                        v-if="detail.media_type === 'tv'"
                        class="space-y-2"
                    >
                        <legend
                            class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                        >
                            Seasons
                        </legend>
                        <div
                            v-for="season in detail.seasons"
                            :key="season.season_number"
                            class="flex items-center justify-between gap-3"
                            :data-season-option="season.season_number"
                        >
                            <div class="flex items-center gap-2">
                                <Checkbox
                                    :id="`season-${season.season_number}`"
                                    :disabled="!season.requestable"
                                    :model-value="
                                        selectedSeasons.includes(
                                            season.season_number,
                                        )
                                    "
                                    @update:model-value="
                                        (value) =>
                                            toggleSeason(
                                                season.season_number,
                                                value === true,
                                            )
                                    "
                                />
                                <Label :for="`season-${season.season_number}`">
                                    {{ season.name }}
                                    <span class="text-muted-foreground"
                                        >·
                                        {{ season.episode_count }}
                                        episodes</span
                                    >
                                </Label>
                            </div>
                            <StatusPill
                                v-if="titleStatusPill(season.status)"
                                :status="titleStatusPill(season.status)!.status"
                                :label="titleStatusPill(season.status)!.label"
                            />
                        </div>
                    </fieldset>

                    <div
                        v-if="requesting?.canChooseUser"
                        class="flex items-center gap-2"
                    >
                        <span class="text-xs text-muted-foreground"
                            >Requesting as</span
                        >
                        <Select
                            :model-value="chosenUserId"
                            @update:model-value="chooseUser"
                        >
                            <SelectTrigger class="h-8 w-48 text-xs">
                                <SelectValue placeholder="Select user" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="user in requesting.users"
                                    :key="user.id"
                                    :value="String(user.id)"
                                >
                                    {{ user.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <p
                        v-if="chosenUserMissing"
                        class="text-[13px] text-muted-foreground"
                        data-no-user-chosen
                    >
                        Choose which Seerr user to request as.
                    </p>

                    <p
                        v-if="requesting?.error"
                        class="text-[13px] text-destructive"
                    >
                        {{ requesting.error }}
                    </p>

                    <p
                        v-if="noAccount"
                        class="text-[13px] text-muted-foreground"
                        data-no-seerr-account
                    >
                        No Seerr account is linked to you — ask an admin.
                    </p>
                    <Button
                        v-else-if="detail.status !== 'available'"
                        class="w-full"
                        :disabled="!canRequest"
                        data-request-submit
                        @click="submit"
                    >
                        <Loader2
                            v-if="submitting"
                            class="size-4 animate-spin"
                        />
                        Request
                    </Button>
                </template>
            </div>
        </SheetContent>
    </Sheet>
</template>
