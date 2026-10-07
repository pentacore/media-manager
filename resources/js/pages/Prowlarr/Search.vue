<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Antenna, Download, Loader2, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ServiceConnectionController from '@/actions/App/Http/Controllers/Admin/ServiceConnectionController';
import GrabReleaseController from '@/actions/App/Http/Controllers/Prowlarr/GrabReleaseController';
import SearchIndexersController from '@/actions/App/Http/Controllers/Prowlarr/SearchIndexersController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { jsonRequest } from '@/composables/useAiChat';
import { useCan } from '@/composables/useCan';
import { formatBytes } from '@/lib/format';
import { dashboard } from '@/routes';

interface IndexerRelease {
    /** sha256 of the release guid; null when Prowlarr gave no guid. */
    key: string | null;
    indexer_id: number | null;
    title: string;
    indexer: string;
    size: number;
    seeders: number | null;
    age: number;
    publishDate: string | null;
}

const props = defineProps<{
    query: string;
    results: IndexerRelease[];
    hasConnection: boolean;
    error: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Indexer Search', href: SearchIndexersController().url },
        ],
    },
});

const { can } = useCan();
const isAdmin = computed(() => can('admin'));

const queryInput = ref(props.query);
const grabbing = ref<string | null>(null);

function submit(): void {
    const trimmed = queryInput.value.trim();

    if (trimmed === '') {
        return;
    }

    router.get(
        SearchIndexersController().url,
        { q: trimmed },
        { preserveState: false },
    );
}

// The same guid can come back from several indexers, so a row is identified
// by indexer id and release key together.
function releaseRowKey(release: IndexerRelease): string | null {
    return release.key === null || release.indexer_id === null
        ? null
        : `${release.indexer_id}:${release.key}`;
}

async function grab(release: IndexerRelease): Promise<void> {
    if (
        release.key === null ||
        release.indexer_id === null ||
        grabbing.value !== null
    ) {
        return;
    }

    grabbing.value = releaseRowKey(release);

    try {
        const data = await jsonRequest<{ message: string }>(
            'post',
            GrabReleaseController.url(),
            { release_key: release.key, indexer_id: release.indexer_id },
        );
        toast.success(data.message);
    } catch (error) {
        toast.error(
            error instanceof Error
                ? error.message
                : 'Could not grab this release.',
        );
    } finally {
        grabbing.value = null;
    }
}

function formatAge(days: number): string {
    if (days < 1) {
        return 'Today';
    }

    if (days < 30) {
        return `${Math.round(days)}d`;
    }

    if (days < 365) {
        return `${Math.round(days / 30)}mo`;
    }

    return `${(days / 365).toFixed(1)}y`;
}
</script>

<template>
    <Head title="Indexer Search" />

    <div class="space-y-6 p-6">
        <div>
            <h2
                class="flex items-center gap-2 text-2xl font-bold tracking-tight"
            >
                <Antenna class="size-6" />
                Indexer Search
            </h2>
            <p class="text-muted-foreground">
                Search across every indexer configured in Prowlarr.
            </p>
        </div>

        <div
            v-if="!hasConnection"
            class="rounded-md border border-warning/40 bg-warning/10 px-4 py-3 text-sm text-warning"
        >
            No active Prowlarr connection. Add one from
            <Link
                :href="ServiceConnectionController.index.url()"
                class="underline"
                >Admin → Connections</Link
            >.
        </div>

        <form v-else class="flex items-center gap-2" @submit.prevent="submit">
            <Input
                v-model="queryInput"
                type="search"
                placeholder="Search releases (title, year)…"
                class="flex-1"
            />
            <Button type="submit">
                <Search class="size-4" />
                Search
            </Button>
        </form>

        <div
            v-if="error"
            class="rounded-md border border-destructive/50 bg-destructive/10 px-3 py-2 text-sm text-destructive"
        >
            {{ error }}
        </div>

        <div
            v-if="
                hasConnection && query !== '' && results.length === 0 && !error
            "
            class="text-center text-sm text-muted-foreground"
        >
            No releases found for "{{ query }}".
        </div>

        <Table v-if="results.length > 0" data-prowlarr-results>
            <TableHeader>
                <TableRow>
                    <TableHead>Title</TableHead>
                    <TableHead>Indexer</TableHead>
                    <TableHead class="text-right">Size</TableHead>
                    <TableHead class="text-right">Seeders</TableHead>
                    <TableHead class="text-right">Age</TableHead>
                    <TableHead v-if="isAdmin" class="text-right" />
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow
                    v-for="(release, index) in results"
                    :key="releaseRowKey(release) ?? `${index}-${release.title}`"
                    :data-prowlarr-release="releaseRowKey(release) ?? undefined"
                >
                    <TableCell class="font-medium">{{
                        release.title
                    }}</TableCell>
                    <TableCell
                        ><Badge variant="outline">{{
                            release.indexer
                        }}</Badge></TableCell
                    >
                    <TableCell class="text-right">{{
                        formatBytes(release.size)
                    }}</TableCell>
                    <TableCell class="text-right">{{
                        release.seeders ?? '-'
                    }}</TableCell>
                    <TableCell class="text-right text-muted-foreground">{{
                        formatAge(release.age)
                    }}</TableCell>
                    <TableCell v-if="isAdmin" class="text-right">
                        <Button
                            v-if="release.key !== null"
                            size="sm"
                            variant="outline"
                            class="h-7 gap-1.5 text-xs"
                            :disabled="grabbing !== null"
                            data-prowlarr-grab
                            @click="grab(release)"
                        >
                            <Loader2
                                v-if="
                                    grabbing !== null &&
                                    grabbing === releaseRowKey(release)
                                "
                                class="size-3.5 animate-spin"
                            />
                            <Download v-else class="size-3.5" />Grab
                        </Button>
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </div>
</template>
