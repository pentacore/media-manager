<script setup lang="ts">
import { Label } from '@/components/ui/label';
import type { ArrTag } from './types';

/**
 * Tag picker for the automatic subtitle check. `arrTags` is a deferred prop:
 * undefined while it loads, null when the instance could not be reached, and
 * a (possibly empty) list otherwise.
 */
defineProps<{
    arrTags?: ArrTag[] | null;
    selectedTags?: string[];
}>();
</script>

<template>
    <div class="space-y-3 pt-2" data-subtitle-check-tags>
        <div>
            <Label>Automatic subtitle check</Label>
            <p class="text-sm text-muted-foreground">
                Completed downloads for series or movies carrying one of these
                tags are checked for the required subtitle languages, and a
                replacement is proposed when a language is missing.
            </p>
        </div>

        <div
            v-if="arrTags === undefined"
            class="rounded-md border border-border bg-bg-elev px-3 py-2 text-sm text-muted-foreground"
        >
            Loading tags…
        </div>
        <div
            v-else-if="arrTags === null"
            class="rounded-md border border-border bg-bg-elev px-3 py-2 text-sm text-muted-foreground"
            data-testid="subtitle-check-tags-unavailable"
        >
            Tags could not be loaded. Check that this connection is enabled and
            that its URL and API key are correct, then revisit this page.
        </div>
        <div
            v-else-if="arrTags.length === 0"
            class="rounded-md border border-dashed border-border px-3 py-2 text-sm text-muted-foreground"
        >
            No tags are defined on this instance yet.
        </div>
        <div v-else class="space-y-2">
            <!--
                Keeps the field present when every box is
                unticked: an unticked checkbox group submits
                nothing, and an absent field means "keep the
                stored tags", so without this the selection
                could never be cleared. The backend receives
                it as null and drops it, leaving an empty
                list.

                Deliberately inside this branch only — while
                tags are loading or unavailable the form must
                not claim an empty selection and wipe the
                stored one.
            -->
            <input type="hidden" name="subtitle_check_tags[]" value="" />
            <label
                v-for="tag in arrTags"
                :key="tag.id"
                class="flex items-center gap-2 text-sm"
            >
                <input
                    type="checkbox"
                    name="subtitle_check_tags[]"
                    :value="tag.label"
                    :checked="
                        (selectedTags ?? []).includes(tag.label.toLowerCase())
                    "
                    class="size-4 rounded border-input"
                />
                <span class="font-mono text-xs">
                    {{ tag.label }}
                </span>
            </label>
        </div>
    </div>
</template>
