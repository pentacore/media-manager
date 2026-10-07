<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Bookmark,
    BookmarkX,
    ExternalLink,
    Search,
    Trash2,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import WhisparrActionController from '@/actions/App/Http/Controllers/Whisparr/WhisparrActionController';
import WhisparrController from '@/actions/App/Http/Controllers/Whisparr/WhisparrController';
import { Pill, Poster, SvcChip } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { useWhisparrBlur } from '@/composables/useWhisparrBlur';
import { formatBytes } from '@/lib/format';
import { dashboard } from '@/routes';
import type {
    WhisparrConnection,
    WhisparrItemDetail,
    WhisparrQualityProfiles,
    WhisparrScenes,
} from '@/types';

const props = defineProps<{
    connection: WhisparrConnection;
    item: WhisparrItemDetail;
    scenes?: WhisparrScenes;
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

const busy = ref(false);
const deleteDialogOpen = ref(false);
const deleteFiles = ref(false);
const profileValue = ref<string | undefined>(
    props.item.quality_profile_id
        ? String(props.item.quality_profile_id)
        : undefined,
);

watch(
    () => props.item.quality_profile_id,
    (id) => {
        profileValue.value = id ? String(id) : undefined;
    },
);

const target = computed(() => ({
    service_connection_id: props.connection.id,
    item_id: props.item.id,
}));

function post(url: string, data: Record<string, unknown>): void {
    if (busy.value) {
        return;
    }

    busy.value = true;
    router.post(
        url,
        { ...target.value, ...data },
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = false;
                deleteDialogOpen.value = false;
                // The prop is the truth until the executor runs: a change
                // that was queued for approval, disabled or refused must not
                // leave the select showing a profile Whisparr doesn't have.
                profileValue.value = props.item.quality_profile_id
                    ? String(props.item.quality_profile_id)
                    : undefined;
            },
        },
    );
}

function toggleMonitored(): void {
    post(WhisparrActionController.monitor.url(), {
        monitored: !props.item.monitored,
    });
}

function changeProfile(value: unknown): void {
    const id = Number(value);

    if (!id || id === props.item.quality_profile_id) {
        return;
    }

    profileValue.value = String(id);
    post(WhisparrActionController.qualityProfile.url(), {
        quality_profile_id: id,
    });
}

function searchNow(): void {
    post(WhisparrActionController.search.url(), {});
}

function confirmDelete(): void {
    post(WhisparrActionController.delete.url(), {
        delete_files: deleteFiles.value,
    });
}

const profileName = computed(() => {
    if (props.item.quality_profile_id === null || !props.qualityProfiles) {
        return '—';
    }

    return (
        props.qualityProfiles.items.find(
            (profile) => profile.id === props.item.quality_profile_id,
        )?.name ?? '—'
    );
});
</script>

<template>
    <Head :title="item.title" />

    <div class="flex flex-col gap-6 p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <Link :href="WhisparrController.index.url()">
                <Button variant="ghost" size="sm" class="h-8 text-xs">
                    <ArrowLeft class="size-3.5" />
                    Back to Whisparr
                </Button>
            </Link>
            <div
                class="flex flex-wrap items-center gap-2"
                data-whisparr-actions
            >
                <Button
                    variant="outline"
                    size="sm"
                    class="h-7 gap-1.5 text-xs"
                    :disabled="busy"
                    :aria-pressed="item.monitored"
                    data-monitor-toggle
                    @click="toggleMonitored"
                >
                    <Bookmark v-if="item.monitored" class="size-3.5" />
                    <BookmarkX v-else class="size-3.5" />
                    {{ item.monitored ? 'Monitored' : 'Unmonitored' }}
                </Button>
                <Skeleton
                    v-if="qualityProfiles === undefined"
                    class="h-7 w-44"
                />
                <span
                    v-else-if="qualityProfiles.error"
                    class="text-[12px] text-destructive"
                    data-quality-profile-error
                    >{{ qualityProfiles.error }}</span
                >
                <Select
                    v-else
                    :model-value="profileValue"
                    @update:model-value="changeProfile"
                >
                    <SelectTrigger
                        class="h-7 w-44 text-xs"
                        data-quality-profile-trigger
                    >
                        <SelectValue placeholder="Quality profile" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="profile in qualityProfiles.items"
                            :key="profile.id"
                            :value="String(profile.id)"
                            :data-quality-profile-option="profile.id"
                        >
                            {{ profile.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <Button
                    variant="outline"
                    size="sm"
                    class="h-7 gap-1.5 text-xs"
                    :disabled="busy"
                    data-search-now
                    @click="searchNow"
                >
                    <Search class="size-3.5" />
                    Search now
                </Button>
                <Dialog v-model:open="deleteDialogOpen">
                    <DialogTrigger as-child>
                        <Button
                            variant="destructive"
                            size="sm"
                            class="h-7 gap-1.5 text-xs"
                            data-delete-trigger
                        >
                            <Trash2 class="size-3.5" />
                            Delete
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Delete {{ item.title }}?</DialogTitle>
                            <DialogDescription>
                                Removes it from Whisparr. Cannot be undone.
                                Deletion may require approval in the Action
                                Queue.
                            </DialogDescription>
                        </DialogHeader>
                        <div class="flex items-center gap-2 py-2">
                            <Checkbox
                                id="whisparr_delete_files"
                                v-model="deleteFiles"
                                data-delete-files
                            />
                            <Label for="whisparr_delete_files"
                                >Also delete files on disk</Label
                            >
                        </div>
                        <DialogFooter>
                            <Button
                                variant="outline"
                                @click="deleteDialogOpen = false"
                            >
                                Cancel
                            </Button>
                            <Button
                                variant="destructive"
                                :disabled="busy"
                                data-delete-confirm
                                @click="confirmDelete"
                            >
                                Delete
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
                <a
                    :href="connection.url"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    <Button variant="outline" size="sm" class="h-7 text-xs">
                        <ExternalLink class="size-3.5" />
                        Open Whisparr
                    </Button>
                </a>
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card p-6">
            <div class="flex flex-col gap-6 md:flex-row">
                <div class="w-[180px] shrink-0">
                    <Poster
                        :hint="item.title"
                        :src="item.poster_url"
                        :blurred="blurPosters"
                        size="full"
                    />
                </div>

                <div class="flex-1 space-y-4">
                    <div>
                        <div class="mb-1.5 flex items-center gap-2">
                            <SvcChip id="whisparr" label="Whisparr" />
                            <Pill>{{
                                item.kind === 'site' ? 'Site' : 'Movie'
                            }}</Pill>
                            <Pill v-if="!item.monitored">Unmonitored</Pill>
                            <Pill v-else-if="item.missing" variant="warn"
                                >Missing</Pill
                            >
                        </div>
                        <h1
                            class="text-[22px] leading-tight font-semibold tracking-tight"
                            data-whisparr-show-title
                        >
                            {{ item.title }}
                            <span
                                v-if="item.year"
                                class="font-mono-tabular text-[15px] font-normal text-muted-foreground"
                                >({{ item.year }})</span
                            >
                        </h1>
                    </div>

                    <p
                        v-if="item.overview"
                        class="max-w-[720px] text-[13px] text-muted-foreground"
                    >
                        {{ item.overview }}
                    </p>

                    <dl class="grid gap-3 text-[13px] sm:grid-cols-3">
                        <div>
                            <dt class="text-[11.5px] text-muted-foreground">
                                Size on disk
                            </dt>
                            <dd class="font-mono-tabular">
                                {{ formatBytes(item.size_bytes) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[11.5px] text-muted-foreground">
                                Quality profile
                            </dt>
                            <dd>
                                <Skeleton
                                    v-if="qualityProfiles === undefined"
                                    class="h-4 w-24"
                                />
                                <template v-else>{{ profileName }}</template>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-[11.5px] text-muted-foreground">
                                Path
                            </dt>
                            <dd
                                class="font-mono-tabular text-[12px] break-all"
                                data-whisparr-path
                            >
                                {{ item.path ?? '—' }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>

        <section
            v-if="connection.version === 'v2'"
            class="rounded-xl border border-border bg-card"
        >
            <div
                class="border-b border-border px-4 py-3 text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
            >
                Scenes
            </div>
            <div v-if="scenes === undefined" class="space-y-2 p-4">
                <Skeleton v-for="n in 4" :key="n" class="h-8 w-full" />
            </div>
            <div
                v-else-if="scenes.error"
                class="p-4 text-sm text-destructive"
                data-whisparr-scenes-error
            >
                {{ scenes.error }}
            </div>
            <p
                v-else-if="scenes.groups.length === 0"
                class="p-4 text-sm text-fg-subtle"
            >
                No scenes yet.
            </p>
            <div v-else class="divide-y divide-border">
                <div
                    v-for="group in scenes.groups"
                    :key="group.year"
                    class="px-4 py-3"
                    :data-whisparr-scene-year="group.year"
                >
                    <div class="mb-2 text-[12px] font-semibold">
                        {{ group.year > 0 ? group.year : 'Unknown year' }}
                    </div>
                    <ul class="space-y-1.5">
                        <li
                            v-for="scene in group.scenes"
                            :key="scene.id"
                            class="flex items-center justify-between gap-3 text-[13px]"
                        >
                            <span>{{
                                scene.title ?? `Scene #${scene.id}`
                            }}</span>
                            <span class="flex items-center gap-2">
                                <span
                                    class="font-mono-tabular text-[11.5px] text-fg-subtle"
                                    >{{ scene.air_date ?? '—' }}</span
                                >
                                <Pill v-if="scene.has_file" variant="ok"
                                    >on disk</Pill
                                >
                                <Pill v-else-if="scene.monitored">missing</Pill>
                                <Pill v-else>unmonitored</Pill>
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </section>
    </div>
</template>
