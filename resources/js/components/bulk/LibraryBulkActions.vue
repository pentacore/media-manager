<script setup lang="ts">
import { Bookmark, BookmarkX, Search, Trash2 } from '@lucide/vue';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { submitBulk } from '@/lib/bulk';
import type { BulkSummary, QualityProfileOption } from '@/types';

/** Above this many titles a bulk search asks first (spec: "warns when more than 25"). */
const SEARCH_WARNING_THRESHOLD = 25;

const props = defineProps<{
    endpoint: string;
    /** The pinned target the page was rendered from (service, service_connection_id). */
    target: Record<string, unknown>;
    ids: number[];
    disabled: boolean;
    qualityProfiles?: QualityProfileOption[];
    /** Why the profiles could not be loaded; shown in place of the menu. */
    qualityProfilesError?: string | null;
    /** Plural noun for the dialogs: "series", "movies", "titles". */
    noun: string;
}>();

const emit = defineEmits<{
    done: [summary: BulkSummary];
}>();

/**
 * True while a request is in flight. Bound by the page (v-model:busy) so it
 * can freeze Clear and the row checkboxes too.
 */
const busy = defineModel<boolean>('busy', { default: false });
const profileValue = ref<string | undefined>(undefined);
const searchConfirmOpen = ref(false);
const deleteConfirmOpen = ref(false);
const deleteFiles = ref(false);

// A destructive choice never carries over: every opening starts unticked.
watch(deleteConfirmOpen, (open) => {
    if (open) {
        deleteFiles.value = false;
    }
});

async function run(
    action: string,
    extra: Record<string, unknown> = {},
): Promise<void> {
    if (busy.value || props.ids.length === 0) {
        return;
    }

    busy.value = true;
    const summary = await submitBulk(props.endpoint, {
        ...props.target,
        ids: props.ids,
        action,
        ...extra,
    });
    busy.value = false;

    if (summary) {
        emit('done', summary);
    }
}

function changeProfile(value: unknown): void {
    const id = Number(value);

    // Reset so the select is ready for the next bulk change.
    profileValue.value = undefined;

    if (!id) {
        return;
    }

    void run('quality_profile', { quality_profile_id: id });
}

function search(): void {
    if (props.ids.length > SEARCH_WARNING_THRESHOLD) {
        searchConfirmOpen.value = true;

        return;
    }

    void run('search');
}

async function confirmSearch(): Promise<void> {
    searchConfirmOpen.value = false;
    await run('search');
}

async function confirmDelete(): Promise<void> {
    deleteConfirmOpen.value = false;
    await run('delete', { delete_files: deleteFiles.value });
    deleteFiles.value = false;
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <Button
            variant="outline"
            size="sm"
            class="h-7 gap-1.5 text-xs"
            :disabled="disabled || busy"
            data-bulk-action="monitor"
            @click="run('monitor')"
        >
            <Bookmark class="size-3.5" />Monitor
        </Button>
        <Button
            variant="outline"
            size="sm"
            class="h-7 gap-1.5 text-xs"
            :disabled="disabled || busy"
            data-bulk-action="unmonitor"
            @click="run('unmonitor')"
        >
            <BookmarkX class="size-3.5" />Unmonitor
        </Button>
        <span
            v-if="qualityProfilesError"
            class="text-[12px] text-destructive"
            data-bulk-quality-profile-error
            >{{ qualityProfilesError }}</span
        >
        <Select
            v-else-if="qualityProfiles && qualityProfiles.length > 0"
            :model-value="profileValue"
            :disabled="disabled || busy"
            @update:model-value="changeProfile"
        >
            <SelectTrigger
                class="h-7 w-44 text-xs"
                data-bulk-quality-profile-trigger
            >
                <SelectValue placeholder="Set quality profile…" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem
                    v-for="profile in qualityProfiles"
                    :key="profile.id"
                    :value="String(profile.id)"
                    :data-bulk-quality-profile-option="profile.id"
                >
                    {{ profile.name }}
                </SelectItem>
            </SelectContent>
        </Select>
        <Button
            variant="outline"
            size="sm"
            class="h-7 gap-1.5 text-xs"
            :disabled="disabled || busy"
            data-bulk-action="search"
            @click="search"
        >
            <Search class="size-3.5" />Search
        </Button>
        <Button
            variant="destructive"
            size="sm"
            class="h-7 gap-1.5 text-xs"
            :disabled="disabled || busy"
            data-bulk-action="delete"
            @click="deleteConfirmOpen = true"
        >
            <Trash2 class="size-3.5" />Delete…
        </Button>

        <Dialog v-model:open="searchConfirmOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle
                        >Search {{ ids.length }} {{ noun }}?</DialogTitle
                    >
                    <DialogDescription data-bulk-search-warning>
                        This sends {{ ids.length }} separate searches to your
                        indexers at once.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="searchConfirmOpen = false"
                        >Cancel</Button
                    >
                    <Button data-bulk-search-confirm @click="confirmSearch"
                        >Search {{ ids.length }}</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="deleteConfirmOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle
                        >Delete {{ ids.length }} {{ noun }}?</DialogTitle
                    >
                    <DialogDescription data-bulk-delete-description>
                        Deletes {{ ids.length }} {{ noun }}. Each one goes
                        through the Action Queue and may need approval before
                        anything is removed.
                    </DialogDescription>
                </DialogHeader>
                <div class="flex items-center gap-2 py-2">
                    <Checkbox
                        id="bulk_delete_files"
                        v-model="deleteFiles"
                        data-bulk-delete-files
                    />
                    <Label for="bulk_delete_files"
                        >Also delete their files on disk</Label
                    >
                </div>
                <DialogFooter>
                    <Button
                        variant="outline"
                        data-bulk-delete-cancel
                        @click="deleteConfirmOpen = false"
                        >Cancel</Button
                    >
                    <Button
                        variant="destructive"
                        data-bulk-delete-confirm
                        @click="confirmDelete"
                        >Delete {{ ids.length }}</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
