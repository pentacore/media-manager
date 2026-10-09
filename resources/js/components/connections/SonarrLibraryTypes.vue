<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import type { SonarrRootFolder } from './types';

defineProps<{
    rootFolders?: SonarrRootFolder[];
    errors: Record<string, string>;
}>();
</script>

<template>
    <div class="space-y-3 pt-2" data-sonarr-library-types>
        <div>
            <Label>Sonarr library types</Label>
            <p class="text-sm text-muted-foreground">
                Classify this connection's root folders by content. Sonarr's
                series type describes episode numbering and does not reliably
                identify anime.
            </p>
        </div>

        <div
            v-if="!rootFolders"
            class="rounded-md border border-border bg-bg-elev px-3 py-2 text-sm text-muted-foreground"
        >
            Loading root folders from Sonarr…
        </div>
        <div
            v-else-if="rootFolders.length === 0"
            class="rounded-md border border-dashed border-border px-3 py-2 text-sm text-muted-foreground"
        >
            No root folders could be imported. Save a working URL and API key,
            then revisit this connection.
        </div>
        <div v-else class="space-y-2">
            <div
                v-for="(rootFolder, index) in rootFolders"
                :key="rootFolder.root_folder_id"
                class="grid gap-3 rounded-md border border-border px-3 py-2.5 sm:grid-cols-[minmax(0,1fr)_180px] sm:items-start"
            >
                <div
                    class="min-w-0 self-center truncate font-mono text-xs text-muted-foreground"
                    :title="rootFolder.path"
                >
                    {{ rootFolder.path }}
                </div>

                <div class="space-y-1">
                    <input
                        type="hidden"
                        :name="`sonarr_root_folders[${index}][root_folder_id]`"
                        :value="rootFolder.root_folder_id"
                    />
                    <input
                        type="hidden"
                        :name="`sonarr_root_folders[${index}][path]`"
                        :value="rootFolder.path"
                    />
                    <select
                        :id="`sonarr_root_scope_${rootFolder.root_folder_id}`"
                        :name="`sonarr_root_folders[${index}][scope]`"
                        :value="rootFolder.scope ?? ''"
                        class="h-8 w-full rounded-md border border-input bg-transparent px-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                    >
                        <option value="">Unassigned</option>
                        <option value="anime">Anime</option>
                        <option value="tv">TV</option>
                    </select>
                    <InputError
                        :message="errors[`sonarr_root_folders.${index}.scope`]"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
