<script setup lang="ts">
import { FileText, X } from '@lucide/vue';

interface ChipItem {
    key: string;
    name: string;
    mime: string;
    url?: string;
    previewUrl?: string;
}

defineProps<{ items: ChipItem[]; removable?: boolean }>();
const emit = defineEmits<{ remove: [key: string] }>();
</script>

<template>
    <div
        v-if="items.length"
        class="flex flex-wrap gap-1.5"
        data-attachment-chips
    >
        <component
            :is="item.url ? 'a' : 'span'"
            v-for="item in items"
            :key="item.key"
            :href="item.url"
            :target="item.url ? '_blank' : undefined"
            class="inline-flex items-center gap-1.5 rounded-md border border-border bg-card px-1.5 py-1 text-[11.5px]"
            data-attachment-chip
        >
            <img
                v-if="
                    item.mime.startsWith('image/') &&
                    (item.previewUrl || item.url)
                "
                :src="item.previewUrl ?? item.url"
                alt=""
                class="size-6 rounded object-cover"
            />
            <FileText v-else class="size-3.5 text-muted-foreground" />
            <span class="max-w-[160px] truncate">{{ item.name }}</span>
            <button
                v-if="removable"
                type="button"
                class="text-muted-foreground hover:text-foreground"
                :title="`Remove ${item.name}`"
                data-attachment-remove
                @click.prevent="emit('remove', item.key)"
            >
                <X class="size-3" />
            </button>
        </component>
    </div>
</template>
