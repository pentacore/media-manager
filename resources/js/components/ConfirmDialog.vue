<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useConfirm } from '@/composables/useConfirm';

const page = usePage();
const { isOpen, request, settle } = useConfirm();

function onOpenChange(open: boolean): void {
    if (!open) {
        settle(false);
    }
}

/**
 * Starts on Cancel, so a reflexive Enter never confirms a destructive action.
 */
function focusCancel(event: Event): void {
    event.preventDefault();
    document.querySelector<HTMLButtonElement>('[data-confirm-cancel]')?.focus();
}

// A question belongs to the page that asked it: leaving that page (browser
// back/forward, a command-palette jump) cancels it rather than letting it
// confirm against a page the user has left.
watch(
    () => page.url,
    () => {
        if (isOpen.value) {
            settle(false);
        }
    },
);
</script>

<template>
    <Dialog :open="isOpen" @update:open="onOpenChange">
        <DialogContent
            class="max-w-md"
            :show-close-button="false"
            data-confirm-dialog
            @open-auto-focus="focusCancel"
        >
            <DialogHeader>
                <DialogTitle>{{ request?.title }}</DialogTitle>
                <DialogDescription
                    :class="request?.description ? undefined : 'sr-only'"
                >
                    {{ request?.description ?? request?.title }}
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button
                    variant="outline"
                    data-confirm-cancel
                    @click="settle(false)"
                >
                    {{ request?.cancelLabel }}
                </Button>
                <Button
                    :variant="request?.destructive ? 'destructive' : 'default'"
                    data-confirm-accept
                    @click="settle(true)"
                >
                    {{ request?.confirmLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
