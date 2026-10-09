<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Antenna } from '@lucide/vue';
import { reactive } from 'vue';
import ProwlarrTestIndexerController from '@/actions/App/Http/Controllers/Admin/ProwlarrTestIndexerController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { ProwlarrIndexer } from './types';

const props = defineProps<{
    connectionId: number;
    indexers?: ProwlarrIndexer[];
}>();

const testing = reactive<Record<number, boolean>>({});

function testIndexer(indexerId: number): void {
    testing[indexerId] = true;

    router.post(
        ProwlarrTestIndexerController([props.connectionId, indexerId]).url,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                testing[indexerId] = false;
            },
        },
    );
}
</script>

<template>
    <Card class="mt-6" data-prowlarr-indexers>
        <CardHeader>
            <CardTitle class="flex items-center gap-2">
                <Antenna class="size-5" />
                Configured Indexers
            </CardTitle>
            <CardDescription>
                Read-only — manage indexer config in Prowlarr's own UI.
            </CardDescription>
        </CardHeader>
        <CardContent>
            <p
                v-if="!indexers"
                class="flex items-center gap-2 text-sm text-muted-foreground"
            >
                <Antenna class="size-4" />
                Loading indexers…
            </p>
            <p
                v-else-if="indexers.length === 0"
                class="text-sm text-muted-foreground"
            >
                No indexers loaded. Either Prowlarr isn't reachable right now,
                or none are configured yet.
            </p>
            <Table v-else>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Implementation</TableHead>
                        <TableHead class="text-right">Priority</TableHead>
                        <TableHead class="text-center">Enabled</TableHead>
                        <TableHead class="text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="indexer in indexers" :key="indexer.id">
                        <TableCell class="font-medium">{{
                            indexer.name
                        }}</TableCell>
                        <TableCell class="text-muted-foreground">{{
                            indexer.implementation ?? '-'
                        }}</TableCell>
                        <TableCell class="text-right">{{
                            indexer.priority
                        }}</TableCell>
                        <TableCell class="text-center">
                            <Badge
                                :variant="
                                    indexer.enable ? 'default' : 'outline'
                                "
                            >
                                {{ indexer.enable ? 'Enabled' : 'Disabled' }}
                            </Badge>
                        </TableCell>
                        <TableCell class="text-right">
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                :disabled="testing[indexer.id]"
                                :data-prowlarr-indexer-test="indexer.id"
                                @click="testIndexer(indexer.id)"
                            >
                                {{
                                    testing[indexer.id] ? 'Testing...' : 'Test'
                                }}
                            </Button>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </CardContent>
    </Card>
</template>
