<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import MovieController from '@/actions/App/Http/Controllers/Media/MovieController'
import { dashboard } from '@/routes'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select'
import { Film, Search } from 'lucide-vue-next'

interface QualityProfile {
    id: number
    name: string
}

interface RootFolder {
    id: number
    path: string
    free_space: number | null
}

interface LookupImage {
    coverType: string
    remoteUrl?: string
    url?: string
}

interface LookupResult {
    tmdb_id: number | null
    title: string | null
    year: number | null
    overview: string | null
    remote_poster: string | null
    images: LookupImage[]
}

const props = defineProps<{
    qualityProfiles: QualityProfile[]
    rootFolders: RootFolder[]
    searchTerm: string
    searchResults: LookupResult[]
}>()

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Movies', href: MovieController.index.url() },
            { title: 'Add Movie', href: MovieController.create.url() },
        ],
    },
})

const query = ref(props.searchTerm)
const selectedTmdbId = ref<number | null>(null)

const form = useForm<{
    title: string
    tmdbId: number | null
    qualityProfileId: number | null
    rootFolderPath: string
    year: number | null
    monitored: boolean
    images: LookupImage[]
}>({
    title: '',
    tmdbId: null,
    qualityProfileId: null,
    rootFolderPath: '',
    year: null,
    monitored: true,
    images: [],
})

function runSearch() {
    router.get(
        MovieController.create.url(),
        { q: query.value },
        { preserveState: true, preserveScroll: true },
    )
}

function startAdd(result: LookupResult) {
    selectedTmdbId.value = result.tmdb_id
    form.title = result.title ?? ''
    form.tmdbId = result.tmdb_id
    form.year = result.year
    form.images = result.images
    form.qualityProfileId = props.qualityProfiles[0]?.id ?? null
    form.rootFolderPath = props.rootFolders[0]?.path ?? ''
}

function cancelAdd() {
    selectedTmdbId.value = null
}

function submitAdd() {
    form.post(MovieController.store.url())
}
</script>

<template>
    <Head title="Add Movie" />

    <div class="space-y-6 p-6">
        <div>
            <h2 class="text-2xl font-bold tracking-tight">Add Movie</h2>
            <p class="text-muted-foreground">Search for a movie to add to Radarr.</p>
        </div>

        <form class="flex gap-2" @submit.prevent="runSearch">
            <Input
                v-model="query"
                placeholder="Search by title..."
                class="max-w-md"
            />
            <Button type="submit">
                <Search class="mr-2 size-4" />
                Search
            </Button>
        </form>

        <div v-if="searchTerm === ''" class="rounded-md border border-dashed p-10 text-center text-muted-foreground">
            Search for a movie to add.
        </div>

        <div
            v-else-if="searchResults.length === 0"
            class="rounded-md border border-dashed p-10 text-center text-muted-foreground"
        >
            No results found for "{{ searchTerm }}".
        </div>

        <div v-else class="grid gap-4 grid-cols-1 md:grid-cols-2 lg:grid-cols-3">
            <Card v-for="result in searchResults" :key="result.tmdb_id ?? result.title ?? ''">
                <CardContent class="p-4">
                    <div class="flex gap-4">
                        <div class="w-24 shrink-0 overflow-hidden rounded border bg-muted">
                            <img
                                v-if="result.remote_poster"
                                :src="result.remote_poster"
                                :alt="result.title ?? ''"
                                class="aspect-[2/3] w-full object-cover"
                            />
                            <div
                                v-else
                                class="flex aspect-[2/3] w-full items-center justify-center text-muted-foreground"
                            >
                                <Film class="size-8" />
                            </div>
                        </div>
                        <div class="min-w-0 flex-1">
                            <h3 class="font-medium leading-tight">{{ result.title }}</h3>
                            <p v-if="result.year" class="text-xs text-muted-foreground">{{ result.year }}</p>
                            <p v-if="result.overview" class="mt-2 text-sm text-muted-foreground line-clamp-3">
                                {{ result.overview }}
                            </p>
                        </div>
                    </div>

                    <div
                        v-if="selectedTmdbId === result.tmdb_id"
                        class="mt-4 space-y-3 border-t pt-4"
                    >
                        <div class="space-y-2">
                            <Label>Quality Profile</Label>
                            <Select
                                :model-value="form.qualityProfileId !== null ? String(form.qualityProfileId) : ''"
                                @update:model-value="(value) => form.qualityProfileId = value ? Number(value) : null"
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select quality profile" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="profile in qualityProfiles"
                                        :key="profile.id"
                                        :value="String(profile.id)"
                                    >
                                        {{ profile.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="space-y-2">
                            <Label>Root Folder</Label>
                            <Select v-model="form.rootFolderPath">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select root folder" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="folder in rootFolders"
                                        :key="folder.id"
                                        :value="folder.path"
                                    >
                                        {{ folder.path }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="flex gap-2">
                            <Button
                                type="button"
                                :disabled="form.processing || !form.qualityProfileId || !form.rootFolderPath"
                                @click="submitAdd"
                            >
                                {{ form.processing ? 'Adding...' : 'Add' }}
                            </Button>
                            <Button type="button" variant="outline" @click="cancelAdd">Cancel</Button>
                        </div>
                    </div>
                    <div v-else class="mt-4">
                        <Button type="button" variant="outline" size="sm" @click="startAdd(result)">
                            Add
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
