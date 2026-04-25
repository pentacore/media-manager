<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Search, Plus, ArrowLeft } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import SeriesController from '@/actions/App/Http/Controllers/Media/SeriesController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { dashboard } from '@/routes';

interface QualityProfile {
    id: number;
    name: string;
}

interface RootFolder {
    id: number;
    path: string;
    free_space: number | null;
}

interface LookupResult {
    tvdb_id: number | null;
    title: string | null;
    year: number | null;
    overview: string | null;
    remote_poster: string | null;
    images: Array<{ coverType: string; remoteUrl?: string; url?: string }>;
}

const props = defineProps<{
    connection: { url: string };
    qualityProfiles?: QualityProfile[];
    rootFolders?: RootFolder[];
    searchTerm: string;
    searchResults?: LookupResult[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Series', href: SeriesController.index.url() },
            { title: 'Add Series', href: SeriesController.create.url() },
        ],
    },
});

const query = ref(props.searchTerm ?? '');
const selectedTvdbId = ref<number | null>(null);

const form = useForm({
    title: '' as string,
    tvdbId: null as number | null,
    qualityProfileId: null as number | null,
    rootFolderPath: '' as string,
    monitored: true,
});

const isSearchLoading = computed(
    () => props.searchTerm !== '' && props.searchResults === undefined,
);

const qualityProfilesLoading = computed(
    () => props.qualityProfiles === undefined,
);

const rootFoldersLoading = computed(() => props.rootFolders === undefined);

function search() {
    router.get(
        SeriesController.create.url(),
        { q: query.value },
        { preserveState: true, preserveScroll: true },
    );
}

function selectResult(result: LookupResult) {
    selectedTvdbId.value = result.tvdb_id;
    form.title = result.title ?? '';
    form.tvdbId = result.tvdb_id;
    form.qualityProfileId = props.qualityProfiles?.[0]?.id ?? null;
    form.rootFolderPath = props.rootFolders?.[0]?.path ?? '';
    form.monitored = true;
}

function cancelSelection() {
    selectedTvdbId.value = null;
    form.reset();
}

function submit() {
    form.post(SeriesController.store.url());
}

function formatFreeSpace(bytes: number | null): string {
    if (!bytes || bytes <= 0) {
        return '';
    }

    const gb = bytes / 1024 ** 3;

    return ` (${gb.toFixed(0)} GB free)`;
}
</script>

<template>
    <Head title="Add Series" />

    <div class="space-y-6 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-bold tracking-tight">Add Series</h2>
                <p class="text-muted-foreground">
                    Search for a series on TVDB and add it to Sonarr.
                </p>
            </div>
            <Link :href="SeriesController.index.url()">
                <Button variant="ghost" size="sm">
                    <ArrowLeft class="mr-2 size-4" />
                    Back
                </Button>
            </Link>
        </div>

        <form class="flex gap-2" @submit.prevent="search">
            <Input
                v-model="query"
                placeholder="Search for a series..."
                class="max-w-md"
            />
            <Button type="submit">
                <Search class="mr-2 size-4" />
                Search
            </Button>
        </form>

        <div
            v-if="!searchTerm && (searchResults?.length ?? 0) === 0"
            class="rounded-md border bg-muted/30 p-8 text-center text-muted-foreground"
        >
            Search for a series to add.
        </div>

        <div
            v-else-if="isSearchLoading"
            class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3"
        >
            <Card v-for="n in 6" :key="`skeleton-${n}`" class="overflow-hidden">
                <div class="flex gap-3 p-4">
                    <Skeleton class="h-32 w-20 shrink-0 rounded-sm" />
                    <div class="min-w-0 flex-1 space-y-2">
                        <Skeleton class="h-5 w-3/4" />
                        <Skeleton class="h-4 w-12" />
                        <Skeleton class="h-3 w-full" />
                        <Skeleton class="h-3 w-11/12" />
                        <Skeleton class="h-3 w-2/3" />
                    </div>
                </div>
                <CardContent class="pt-0">
                    <Skeleton class="h-9 w-full" />
                </CardContent>
            </Card>
        </div>

        <div
            v-else-if="(searchResults?.length ?? 0) === 0"
            class="rounded-md border bg-muted/30 p-8 text-center text-muted-foreground"
        >
            No results found for "{{ searchTerm }}".
        </div>

        <div
            v-else
            class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3"
        >
            <Card
                v-for="result in searchResults"
                :key="result.tvdb_id ?? result.title ?? ''"
                class="overflow-hidden"
            >
                <div class="flex gap-3 p-4">
                    <img
                        v-if="result.remote_poster"
                        :src="result.remote_poster"
                        :alt="result.title ?? ''"
                        class="h-32 w-20 shrink-0 rounded-sm border bg-muted object-cover"
                    />
                    <div
                        v-else
                        class="flex h-32 w-20 shrink-0 items-center justify-center rounded-sm border bg-muted text-xs text-muted-foreground"
                    >
                        No image
                    </div>
                    <div class="min-w-0 flex-1">
                        <h3
                            class="truncate font-semibold"
                            :title="result.title ?? ''"
                        >
                            {{ result.title ?? 'Unknown' }}
                        </h3>
                        <p
                            v-if="result.year"
                            class="text-sm text-muted-foreground"
                        >
                            {{ result.year }}
                        </p>
                        <p
                            v-if="result.overview"
                            class="mt-1 line-clamp-3 text-xs text-muted-foreground"
                        >
                            {{ result.overview }}
                        </p>
                    </div>
                </div>

                <CardContent
                    v-if="selectedTvdbId !== result.tvdb_id"
                    class="pt-0"
                >
                    <Button
                        class="w-full"
                        size="sm"
                        :disabled="!result.tvdb_id"
                        @click="selectResult(result)"
                    >
                        <Plus class="mr-2 size-4" />
                        Add
                    </Button>
                </CardContent>

                <CardContent v-else class="space-y-3 pt-0">
                    <div class="space-y-1">
                        <Label :for="`quality-${result.tvdb_id}`"
                            >Quality Profile</Label
                        >
                        <Select
                            v-model="form.qualityProfileId"
                            :disabled="qualityProfilesLoading"
                        >
                            <SelectTrigger :id="`quality-${result.tvdb_id}`">
                                <SelectValue
                                    :placeholder="
                                        qualityProfilesLoading
                                            ? 'Loading options…'
                                            : 'Select quality profile'
                                    "
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="profile in qualityProfiles ?? []"
                                    :key="profile.id"
                                    :value="profile.id"
                                >
                                    {{ profile.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.qualityProfileId" />
                    </div>

                    <div class="space-y-1">
                        <Label :for="`root-${result.tvdb_id}`"
                            >Root Folder</Label
                        >
                        <Select
                            v-model="form.rootFolderPath"
                            :disabled="rootFoldersLoading"
                        >
                            <SelectTrigger :id="`root-${result.tvdb_id}`">
                                <SelectValue
                                    :placeholder="
                                        rootFoldersLoading
                                            ? 'Loading options…'
                                            : 'Select root folder'
                                    "
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="folder in rootFolders ?? []"
                                    :key="folder.id"
                                    :value="folder.path"
                                >
                                    {{ folder.path
                                    }}{{ formatFreeSpace(folder.free_space) }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.rootFolderPath" />
                    </div>

                    <div class="flex gap-2">
                        <Button
                            class="flex-1"
                            size="sm"
                            :disabled="
                                form.processing ||
                                !form.qualityProfileId ||
                                !form.rootFolderPath
                            "
                            @click="submit"
                        >
                            {{ form.processing ? 'Adding...' : 'Add Series' }}
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="form.processing"
                            @click="cancelSelection"
                        >
                            Cancel
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
