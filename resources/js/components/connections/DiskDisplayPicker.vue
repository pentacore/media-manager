<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { Label } from '@/components/ui/label';
import type { DiskMetric, DiskPath, EditableConnection } from './types';

/**
 * Service Health disk-display settings for a Sonarr/Radarr connection: the
 * mode, the picked root paths and a free/used/both metric per path (plus the
 * sum row), submitted as hidden disk_mode / disk_paths[] / disk_display[]
 * fields of the surrounding form.
 */
const props = defineProps<{
    disk: EditableConnection['disk'];
    availableDiskPaths?: DiskPath[];
}>();

const diskMode = ref<'all' | 'selected' | 'sum'>(props.disk?.mode ?? 'all');
const selectedDiskPaths = ref<string[]>([...(props.disk?.paths ?? [])]);
const diskDisplay = reactive<Record<string, DiskMetric>>({
    ...(props.disk?.display ?? {}),
});

function toggleDiskPath(path: string): void {
    const idx = selectedDiskPaths.value.indexOf(path);

    if (idx === -1) {
        selectedDiskPaths.value.push(path);

        if (!diskDisplay[path]) {
            diskDisplay[path] = 'both';
        }
    } else {
        selectedDiskPaths.value.splice(idx, 1);
    }
}

function diskDisplayFor(key: string): DiskMetric {
    return diskDisplay[key] ?? 'both';
}

function setDiskDisplay(key: string, value: DiskMetric): void {
    diskDisplay[key] = value;
}

const diskDisplayEntries = computed<Array<[string, DiskMetric]>>(() => {
    const entries: Array<[string, DiskMetric]> = [];

    for (const path of selectedDiskPaths.value) {
        entries.push([path, diskDisplayFor(path)]);
    }

    if (diskMode.value === 'sum') {
        entries.push(['sum', diskDisplayFor('sum')]);
    }

    return entries;
});
</script>

<template>
    <div class="space-y-3 pt-2" data-disk-display>
        <div>
            <Label>Service Health · disk display</Label>
            <p class="text-sm text-muted-foreground">
                Choose which root paths show up on the Service Health page.
                "Sum" collapses the selected paths into a single total row.
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <label
                v-for="opt in [
                    ['all', 'Show all'],
                    ['selected', 'Show selected'],
                    ['sum', 'Sum selected'],
                ] as const"
                :key="opt[0]"
                :class="[
                    'inline-flex cursor-pointer items-center gap-2 rounded-md border border-border px-3 py-1.5 text-sm transition-colors',
                    diskMode === opt[0]
                        ? 'border-accent/50 bg-accent/10 text-accent'
                        : 'hover:bg-bg-hover',
                ]"
            >
                <input
                    type="radio"
                    :value="opt[0]"
                    v-model="diskMode"
                    class="sr-only"
                />
                {{ opt[1] }}
            </label>
        </div>

        <div
            v-if="diskMode !== 'all'"
            class="rounded-md border border-border bg-bg-elev p-3"
        >
            <div
                v-if="!availableDiskPaths"
                class="text-sm text-muted-foreground"
            >
                Loading disk paths from the service…
            </div>
            <div
                v-else-if="availableDiskPaths.length === 0"
                class="text-sm text-fg-subtle"
            >
                No disk paths reported. Save with the URL + API key first, then
                revisit to pick paths.
            </div>
            <div v-else class="flex flex-col gap-2">
                <div
                    v-for="entry in availableDiskPaths"
                    :key="entry.path"
                    class="flex flex-wrap items-center gap-3 text-sm"
                    :data-disk-path="entry.path"
                >
                    <label class="flex cursor-pointer items-center gap-2">
                        <input
                            type="checkbox"
                            :value="entry.path"
                            :checked="selectedDiskPaths.includes(entry.path)"
                            class="size-4 rounded border-border"
                            @change="toggleDiskPath(entry.path)"
                        />
                        <span class="font-mono-tabular">{{ entry.path }}</span>
                        <span
                            v-if="entry.label"
                            class="text-xs text-muted-foreground"
                            >({{ entry.label }})</span
                        >
                    </label>
                    <div
                        v-if="
                            diskMode === 'selected' &&
                            selectedDiskPaths.includes(entry.path)
                        "
                        class="ml-auto flex items-center gap-1 rounded-md border border-border bg-card p-0.5 text-xs"
                    >
                        <button
                            v-for="metric in ['free', 'used', 'both'] as const"
                            :key="metric"
                            type="button"
                            class="inline-flex h-6 items-center rounded px-2 transition-colors"
                            :class="
                                diskDisplayFor(entry.path) === metric
                                    ? 'bg-accent text-accent-foreground'
                                    : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground'
                            "
                            :data-disk-metric="metric"
                            @click="setDiskDisplay(entry.path, metric)"
                        >
                            {{ metric }}
                        </button>
                    </div>
                </div>
            </div>

            <div
                v-if="diskMode === 'sum'"
                class="mt-3 flex flex-wrap items-center gap-3 border-t border-border pt-3 text-sm"
            >
                <span class="font-medium">Sum row display</span>
                <div
                    class="ml-auto flex items-center gap-1 rounded-md border border-border bg-card p-0.5 text-xs"
                >
                    <button
                        v-for="metric in ['free', 'used', 'both'] as const"
                        :key="metric"
                        type="button"
                        class="inline-flex h-6 items-center rounded px-2 transition-colors"
                        :class="
                            diskDisplayFor('sum') === metric
                                ? 'bg-accent text-accent-foreground'
                                : 'text-muted-foreground hover:bg-bg-hover hover:text-foreground'
                        "
                        :data-disk-sum-metric="metric"
                        @click="setDiskDisplay('sum', metric)"
                    >
                        {{ metric }}
                    </button>
                </div>
            </div>
        </div>

        <input type="hidden" name="disk_mode" :value="diskMode" />
        <input
            v-for="path in selectedDiskPaths"
            :key="path"
            type="hidden"
            name="disk_paths[]"
            :value="path"
        />
        <input
            v-for="entry in diskDisplayEntries"
            :key="`display-${entry[0]}`"
            type="hidden"
            :name="`disk_display[${entry[0]}]`"
            :value="entry[1]"
        />
    </div>
</template>
