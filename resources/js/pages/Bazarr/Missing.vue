<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import MissingController from '@/actions/App/Http/Controllers/Bazarr/MissingController';
import OverviewController from '@/actions/App/Http/Controllers/Bazarr/OverviewController';
import SubtitleItemDrawer from '@/components/bazarr/SubtitleItemDrawer.vue';
import SubtitleTabs from '@/components/bazarr/SubtitleTabs.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import type { SubtitleItemResource } from '@/typefinder/resources/SubtitleItemResource';

type SubtitleItem = SubtitleItemResource;

interface Inventory {
    data: SubtitleItem[];
    page: number;
    per_page: number;
    total: number;
    partial: boolean;
    errors: string[];
}

defineProps<{
    connections: { id: number; name: string }[];
    selected_connection_id: number | null;
    requires_connection_selection: boolean;
    missing: Inventory | null;
}>();

const selectedItem = ref<SubtitleItem | null>(null);

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Media', href: dashboard().url },
            { title: 'Subtitles', href: OverviewController.url() },
            { title: 'Missing', href: MissingController.url() },
        ],
    },
});
</script>

<template>
    <div class="space-y-6 p-4 sm:p-6">
        <Head title="Missing subtitles" />
        <SubtitleTabs
            active="missing"
            :connections="connections"
            :selected-connection-id="selected_connection_id"
        />

        <div
            v-if="requires_connection_selection"
            class="rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm"
        >
            Select a Bazarr connection to view wanted subtitles.
        </div>
        <div
            v-else-if="missing?.partial"
            class="rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm"
        >
            {{ missing.errors.join(' ') }}
        </div>

        <div
            v-if="missing && missing.data.length === 0"
            class="rounded-xl border border-border bg-card p-8 text-center"
        >
            <p class="font-medium">Nothing is currently missing</p>
            <p class="text-sm text-muted-foreground">
                Bazarr has no wanted subtitle entries for this selection.
            </p>
        </div>
        <div
            v-else-if="missing"
            class="overflow-hidden rounded-xl border border-border bg-card"
        >
            <div
                v-for="item in missing.data"
                :key="`${item.media_type}-${item.media_id}`"
                class="flex flex-col gap-2 border-b border-border p-4 last:border-b-0 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <p class="font-medium">{{ item.title }}</p>
                    <p class="text-xs text-muted-foreground capitalize">
                        {{ item.media_type }} · {{ item.scope ?? 'movie' }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span
                        v-for="language in item.missing_languages"
                        :key="language"
                        class="rounded-full bg-warning/15 px-2 py-1 text-xs font-medium text-warning-foreground"
                    >
                        {{ language }}
                    </span>
                    <Button
                        size="sm"
                        variant="outline"
                        :data-test="`subtitle-item-${item.media_type}-${item.media_id}`"
                        @click="selectedItem = item"
                    >
                        Inspect
                    </Button>
                </div>
            </div>
        </div>

        <SubtitleItemDrawer
            :open="selectedItem !== null"
            :item="selectedItem"
            :connection-id="selected_connection_id"
            @update:open="
                (open) => {
                    if (!open) selectedItem = null;
                }
            "
        />
    </div>
</template>
