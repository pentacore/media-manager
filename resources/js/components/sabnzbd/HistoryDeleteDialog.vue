<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

defineProps<{
    open: boolean;
    name: string | null;
    processing: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    confirm: [withFiles: boolean];
}>();
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="sm:max-w-md" data-history-delete-dialog>
            <DialogHeader>
                <DialogTitle>Remove from history</DialogTitle>
                <DialogDescription>
                    Remove "{{ name ?? 'this job' }}" from SABnzbd's history.
                    Removing the files as well deletes whatever SABnzbd
                    downloaded for it.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button
                    variant="outline"
                    :disabled="processing"
                    data-history-delete-entry
                    @click="emit('confirm', false)"
                >
                    Remove entry
                </Button>
                <Button
                    variant="destructive"
                    :disabled="processing"
                    data-history-delete-files
                    @click="emit('confirm', true)"
                >
                    Remove entry and files
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
