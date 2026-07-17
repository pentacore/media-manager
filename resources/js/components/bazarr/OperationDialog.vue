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
    title: string;
    description: string;
    confirmLabel: string;
    processing?: boolean;
    destructive?: boolean;
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
    confirm: [];
}>();
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>
            <slot />
            <DialogFooter>
                <Button
                    variant="outline"
                    :disabled="processing"
                    @click="emit('update:open', false)"
                >
                    Cancel
                </Button>
                <Button
                    :variant="destructive ? 'destructive' : 'default'"
                    :disabled="processing"
                    data-test="confirm-subtitle-operation"
                    @click="emit('confirm')"
                >
                    {{ processing ? 'Submitting…' : confirmLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
