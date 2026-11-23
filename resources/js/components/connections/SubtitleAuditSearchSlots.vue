<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * How many subtitle repair release searches may run against this Sonarr
 * connection at once. Blank saves no value, so the default applies.
 */
defineProps<{
    slots: number | null;
    defaultSlots: number;
    error?: string;
}>();
</script>

<template>
    <div class="space-y-2 pt-2" data-subtitle-audit-search-slots>
        <Label for="subtitle_audit_search_slots"
            >Subtitle repair searches at once</Label
        >
        <Input
            id="subtitle_audit_search_slots"
            name="subtitle_audit_search_slots"
            type="number"
            min="1"
            max="10"
            :default-value="slots ?? ''"
            :placeholder="String(defaultSlots)"
            aria-describedby="subtitle_audit_search_slots-hint"
            class="max-w-[120px]"
        />
        <p
            id="subtitle_audit_search_slots-hint"
            class="text-sm text-muted-foreground"
        >
            How many release searches a series subtitle repair may run against
            this Sonarr at the same time (1–10). More finishes a large batch
            sooner but loads Sonarr and its indexers harder. Leave blank for the
            default of {{ defaultSlots }}.
        </p>
        <InputError :message="error" />
    </div>
</template>
