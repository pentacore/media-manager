<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, ExternalLink, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import SeriesController from '@/actions/App/Http/Controllers/Media/SeriesController';
import { MonitorButton, SearchButton } from '@/components/library-actions';
import { PageHeader } from '@/components/mm';
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
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { useCan } from '@/composables/useCan';
import { sonarrSeriesUrl } from './seriesHelpers';
import type { SeriesDetail } from './types';

const props = defineProps<{
    series: SeriesDetail;
    connectionId: number;
    /** The connection's external URL, for Open in Sonarr; null hides it. */
    connectionUrl: string | null;
}>();

const { can } = useCan();

const deleteFiles = ref(false);
const deleteDialogOpen = ref(false);
const deleting = ref(false);

function confirmDelete() {
    if (deleting.value) {
        return;
    }

    deleting.value = true;
    router.delete(SeriesController.destroy.url(props.series.id), {
        data: {
            delete_files: deleteFiles.value,
            service_connection_id: props.connectionId,
        },
        preserveScroll: true,
        onFinish: () => {
            deleting.value = false;
            deleteDialogOpen.value = false;
        },
    });
}
</script>

<template>
    <PageHeader align="center" primary-first>
        <template #title>
            <Button
                as-child
                variant="ghost"
                size="sm"
                class="h-8 text-xs sm:flex"
                data-series-back
            >
                <Link :href="SeriesController.index.url()">
                    <ArrowLeft class="size-3.5" />
                    Back to series
                </Link>
            </Button>
        </template>
        <template v-if="can('manage-library')" #primaryAction>
            <div class="flex items-center gap-2" data-series-actions>
                <MonitorButton
                    service="sonarr"
                    :connection-id="connectionId"
                    :item-id="series.id"
                    :monitored="series.monitored"
                />
                <SearchButton
                    service="sonarr"
                    :connection-id="connectionId"
                    command="series_search"
                    :series-id="series.id"
                    label="Search series"
                />
            </div>
        </template>
        <template v-if="can('manage-library')" #secondaryActions>
            <Button
                v-if="series.title_slug && connectionUrl"
                as-child
                variant="outline"
                size="sm"
                class="h-8 text-xs"
                data-series-open-sonarr
            >
                <a
                    :href="
                        sonarrSeriesUrl(connectionUrl, series.title_slug) ??
                        undefined
                    "
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    <ExternalLink class="size-3.5" />
                    Open in Sonarr
                </a>
            </Button>
            <Dialog v-model:open="deleteDialogOpen">
                <DialogTrigger as-child>
                    <Button
                        variant="destructive"
                        size="sm"
                        class="h-8 text-xs"
                        data-delete-trigger
                    >
                        <Trash2 class="size-3.5" />
                        Delete
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle> Delete {{ series.title }}? </DialogTitle>
                        <DialogDescription data-delete-description>
                            Removes the series from Sonarr. Cannot be undone.
                            Deletion may require approval in the Action Queue.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="flex items-center gap-2 py-2">
                        <Checkbox id="delete_files" v-model="deleteFiles" />
                        <Label for="delete_files"
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
                            data-delete-confirm
                            :disabled="deleting"
                            @click="confirmDelete"
                        >
                            Delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </template>
        <template v-if="can('manage-library')" #menu>
            <DropdownMenuItem
                v-if="series.title_slug && connectionUrl"
                as-child
                data-page-header-item="open-sonarr"
            >
                <a
                    :href="
                        sonarrSeriesUrl(connectionUrl, series.title_slug) ??
                        undefined
                    "
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    <ExternalLink class="size-3.5" />Open in Sonarr
                </a>
            </DropdownMenuItem>
            <DropdownMenuItem
                class="text-destructive focus:text-destructive"
                data-page-header-item="delete"
                @select="deleteDialogOpen = true"
            >
                <Trash2 class="size-3.5" />Delete
            </DropdownMenuItem>
        </template>
    </PageHeader>
</template>
