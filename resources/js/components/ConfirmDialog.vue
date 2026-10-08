<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, useTemplateRef, watch } from 'vue';
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
const cancelButton =
    useTemplateRef<InstanceType<typeof Button>>('cancelButton');

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
    (cancelButton.value?.$el as HTMLButtonElement | undefined)?.focus();
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

// A layout swap (e.g. a login redirect) unmounts this component without ever
// running the url watcher above, which would otherwise leave a stale, still-
// confirmable question in the module-level state for the next ConfirmDialog
// that mounts. Settling here guarantees every question this instance showed
// is answered by the time it's gone.
onBeforeUnmount(() => settle(false));
</script>

<template>
    <Dialog :open="isOpen" @update:open="onOpenChange">
        <DialogContent
            class="sm:max-w-md"
            :show-close-button="false"
            data-confirm-dialog
            v-bind="
                request?.description ? {} : { 'aria-describedby': undefined }
            "
            @open-auto-focus="focusCancel"
        >
            <DialogHeader>
                <DialogTitle>{{ request?.title }}</DialogTitle>
                <DialogDescription v-if="request?.description">
                    {{ request.description }}
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button
                    ref="cancelButton"
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
