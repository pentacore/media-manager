<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Tv, Plus } from 'lucide-vue-next';
import SeriesController from '@/actions/App/Http/Controllers/Media/SeriesController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { dashboard } from '@/routes';

interface QualityProfile {
    id: number;
    name: string;
}

interface SeriesImage {
    coverType: string;
    remoteUrl?: string;
    url?: string;
}

interface Series {
    id: number;
    title: string;
    year: number | null;
    status: string | null;
    monitored: boolean;
    quality_profile_id: number | null;
    season_count: number;
    size_on_disk: number;
    episode_file_count: number;
    episode_count: number;
    images: SeriesImage[];
}

const props = defineProps<{
    series: Series[];
    qualityProfiles: QualityProfile[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            { title: 'Series', href: SeriesController.index.url() },
        ],
    },
});

function formatSize(bytes: number): string {
    if (!bytes || bytes <= 0) {
        return '-';
    }

    const gb = bytes / 1024 ** 3;

    if (gb >= 1) {
        return `${gb.toFixed(1)} GB`;
    }

    const mb = bytes / 1024 ** 2;

    return `${mb.toFixed(0)} MB`;
}

function qualityName(id: number | null): string {
    if (id === null) {
        return '-';
    }

    const profile = props.qualityProfiles.find(
        (qualityProfile) => qualityProfile.id === id,
    );

    return profile?.name ?? '-';
}

function statusVariant(
    status: string | null,
): 'default' | 'secondary' | 'outline' {
    if (status === 'continuing') {
        return 'default';
    }

    if (status === 'ended') {
        return 'secondary';
    }

    return 'outline';
}

function monitoredCount(): number {
    return props.series.filter((s) => s.monitored).length;
}
</script>

<template>
    <Head title="Series" />

    <div class="space-y-6 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h2
                    class="flex items-center gap-2 text-2xl font-bold tracking-tight"
                >
                    <Tv class="size-6" />
                    Series
                </h2>
                <p class="text-muted-foreground">
                    {{ series.length }} series, {{ monitoredCount() }} monitored
                </p>
            </div>
            <Link :href="SeriesController.create.url()">
                <Button>
                    <Plus class="mr-2 size-4" />
                    Add Series
                </Button>
            </Link>
        </div>

        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Title</TableHead>
                    <TableHead>Year</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Quality</TableHead>
                    <TableHead>Seasons</TableHead>
                    <TableHead>Files</TableHead>
                    <TableHead>Size</TableHead>
                    <TableHead class="text-right">Actions</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow v-for="item in series" :key="item.id">
                    <TableCell class="font-medium">
                        {{ item.title }}
                        <Badge
                            v-if="item.monitored"
                            variant="outline"
                            class="ml-2"
                            >Monitored</Badge
                        >
                    </TableCell>
                    <TableCell class="text-muted-foreground">{{
                        item.year ?? '-'
                    }}</TableCell>
                    <TableCell>
                        <Badge
                            v-if="item.status"
                            :variant="statusVariant(item.status)"
                        >
                            {{ item.status }}
                        </Badge>
                        <span v-else class="text-muted-foreground">-</span>
                    </TableCell>
                    <TableCell class="text-muted-foreground">
                        {{ qualityName(item.quality_profile_id) }}
                    </TableCell>
                    <TableCell class="text-muted-foreground">{{
                        item.season_count
                    }}</TableCell>
                    <TableCell class="text-muted-foreground">
                        {{ item.episode_file_count }} / {{ item.episode_count }}
                    </TableCell>
                    <TableCell class="text-muted-foreground">{{
                        formatSize(item.size_on_disk)
                    }}</TableCell>
                    <TableCell class="text-right">
                        <Link :href="SeriesController.show.url(item.id)">
                            <Button variant="ghost" size="sm">View</Button>
                        </Link>
                    </TableCell>
                </TableRow>
                <TableRow v-if="series.length === 0">
                    <TableCell
                        :colspan="8"
                        class="py-8 text-center text-muted-foreground"
                    >
                        No series yet. Add one to get started.
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </div>
</template>
