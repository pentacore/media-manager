<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import AiFreeUsagePoolController from '@/actions/App/Http/Controllers/Admin/AiFreeUsagePoolController';
import { PoolFormFields } from '@/components/mm';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { PoolRow } from './types';

/** A copy of the pool being edited; null closes the dialog. */
const pool = defineModel<PoolRow | null>('pool', { required: true });

function close(): void {
    pool.value = null;
}
</script>

<template>
    <Dialog :open="pool !== null" @update:open="(v) => !v && close()">
        <DialogContent v-if="pool" data-edit-pool-dialog>
            <DialogHeader>
                <DialogTitle>Edit {{ pool.name }}</DialogTitle>
            </DialogHeader>
            <Form
                v-bind="AiFreeUsagePoolController.update.form(pool.id)"
                class="space-y-4"
                v-slot="{ errors, processing }"
                @success="close"
            >
                <PoolFormFields
                    :key="pool.id"
                    id-prefix="edit_pool"
                    :pool="pool"
                    :errors="errors"
                />
                <DialogFooter>
                    <Button type="submit" :disabled="processing"> Save </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
