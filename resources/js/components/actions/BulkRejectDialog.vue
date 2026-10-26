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
import { returnFocusToBulkMenu } from '@/lib/bulk';

defineProps<{
    /** Requests in the bulk selection. */
    count: number;
    /** A bulk run is in flight. */
    busy: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });
/** The shared rejection reason; the page clears it after a run. */
const reason = defineModel<string>('reason', { required: true });

const emit = defineEmits<{
    confirm: [];
}>();
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            @close-auto-focus="
                (event) => returnFocusToBulkMenu(event, '[data-bulk-reject]')
            "
        >
            <DialogHeader>
                <DialogTitle
                    >Reject {{ count }}
                    {{ count === 1 ? 'request' : 'requests' }}?</DialogTitle
                >
                <DialogDescription>
                    One reason is saved on every rejected request and in the
                    activity log. Requests that are no longer pending are
                    skipped.
                </DialogDescription>
            </DialogHeader>
            <textarea
                v-model="reason"
                maxlength="500"
                rows="3"
                placeholder="Reason (optional)"
                class="w-full rounded-md border border-border bg-bg-elev p-2 text-[13px] outline-none placeholder:text-fg-subtle"
                data-bulk-reject-reason
            />
            <DialogFooter>
                <Button variant="outline" @click="open = false">Cancel</Button>
                <Button
                    variant="destructive"
                    :disabled="busy"
                    data-bulk-reject-confirm
                    @click="emit('confirm')"
                    >Reject {{ count }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
