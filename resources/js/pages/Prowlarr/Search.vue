<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Antenna, ExternalLink, Search } from 'lucide-vue-next';
import { ref } from 'vue';
import ServiceConnectionController from '@/actions/App/Http/Controllers/Admin/ServiceConnectionController';
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
import { dashboard } from '@/routes';

interface IndexerRelease {
    title: string;
    indexer: string;
    size: number;
    seeders: number | null;
    age: number;
    downloadUrl: string | null;
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

const queryInput = ref(props.query);

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

function formatBytes(bytes: number): string {
    if (bytes < 1_000_000) {
        return `${(bytes / 1_000).toFixed(0)} KB`;
    }

    if (bytes < 1_000_000_000) {
        return `${(bytes / 1_000_000).toFixed(0)} MB`;
    }

    return `${(bytes / 1_000_000_000).toFixed(2)} GB`;
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
            class="rounded-md border border-amber-300/50 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-950 dark:text-amber-100"
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

        <Table v-if="results.length > 0">
            <TableHeader>
                <TableRow>
                    <TableHead>Title</TableHead>
                    <TableHead>Indexer</TableHead>
                    <TableHead class="text-right">Size</TableHead>
                    <TableHead class="text-right">Seeders</TableHead>
                    <TableHead class="text-right">Age</TableHead>
                    <TableHead class="text-right">Open</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow
                    v-for="release in results"
                    :key="`${release.indexer}-${release.title}-${release.size}`"
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
                    <TableCell class="text-right">
                        <a
                            v-if="release.downloadUrl"
                            :href="release.downloadUrl"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex items-center gap-1 text-xs underline"
                        >
                            <ExternalLink class="size-3" />
                            Download
                        </a>
                        <span v-else class="text-xs text-muted-foreground"
                            >-</span
                        >
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </div>
</template>
