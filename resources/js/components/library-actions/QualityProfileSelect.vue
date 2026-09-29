<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import MediaActionController from '@/actions/App/Http/Controllers/Library/MediaActionController';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';

const props = defineProps<{
    service: 'sonarr' | 'radarr';
    connectionId: number;
    itemId: number;
    profiles?: { id: number; name: string }[];
    currentId: number | null;
}>();

const value = ref<string | undefined>(props.currentId ? String(props.currentId) : undefined);

watch(
    () => props.currentId,
    (id) => {
        value.value = id ? String(id) : undefined;
    },
);

function change(next: unknown): void {
    const id = Number(next);

    if (!id || id === props.currentId) {
        return;
    }

    value.value = String(id);
    router.post(
        MediaActionController.qualityProfile.url(),
        {
            service: props.service,
            service_connection_id: props.connectionId,
            item_id: props.itemId,
            quality_profile_id: id,
        },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Skeleton v-if="profiles === undefined" class="h-7 w-44" />
    <Select v-else :model-value="value" @update:model-value="change">
        <SelectTrigger class="h-7 w-44 text-xs" data-quality-profile-trigger>
            <SelectValue placeholder="Quality profile" />
        </SelectTrigger>
        <SelectContent>
            <SelectItem
                v-for="profile in profiles"
                :key="profile.id"
                :value="String(profile.id)"
                :data-quality-profile-option="profile.id"
            >
                {{ profile.name }}
            </SelectItem>
        </SelectContent>
    </Select>
</template>
