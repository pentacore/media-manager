<script setup lang="ts">
import { Trash2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';

defineProps<{
    count: number;
}>();

const emit = defineEmits<{ edit: []; delete: []; clear: [] }>();
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-2 border-b border-border bg-bg-elev px-4 py-2 text-[12.5px]"
        data-bulk-bar
    >
        <span class="font-medium" data-bulk-count> {{ count }} selected </span>
        <Button
            variant="outline"
            size="sm"
            class="h-7 px-2.5 text-xs"
            data-bulk-edit
            @click="emit('edit')"
        >
            Edit selected
        </Button>
        <Button
            variant="outline"
            size="sm"
            class="h-7 px-2.5 text-xs text-destructive hover:text-destructive"
            data-bulk-delete
            @click="emit('delete')"
        >
            <Trash2 class="size-3.5" />
            Delete selected
        </Button>
        <Button
            variant="ghost"
            size="sm"
            class="h-7 px-2.5 text-xs"
            data-bulk-clear
            @click="emit('clear')"
        >
            Clear
        </Button>
    </div>
</template>
