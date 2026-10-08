<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import AiFreeUsagePoolController from '@/actions/App/Http/Controllers/Admin/AiFreeUsagePoolController';
import { Pill, PoolFormFields } from '@/components/mm';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { useConfirm } from '@/composables/useConfirm';
import type { PoolRow } from './types';

defineProps<{
    pools: PoolRow[];
}>();

const emit = defineEmits<{ edit: [pool: PoolRow] }>();

const showPoolCreateDialog = ref(false);

const { confirm } = useConfirm();

async function destroyPool(pool: PoolRow): Promise<void> {
    const confirmed = await confirm({
        title: `Remove pool "${pool.name}"?`,
        description: 'Member models keep their pricing but lose the free tier.',
        confirmLabel: 'Remove',
        destructive: true,
    });

    if (!confirmed) {
        return;
    }

    router.visit(AiFreeUsagePoolController.destroy.url(pool.id), {
        method: 'delete',
        preserveScroll: true,
    });
}

function formatTokens(value: number | null): string {
    return value === null ? '—' : new Intl.NumberFormat().format(value);
}
</script>

<template>
    <div
        class="overflow-hidden rounded-xl border border-border bg-card"
        data-pools-card
    >
        <div
            class="flex items-center justify-between gap-3 border-b border-border px-4 py-3"
        >
            <span
                class="text-[12px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
            >
                Free usage pools
            </span>
            <Dialog v-model:open="showPoolCreateDialog">
                <DialogTrigger as-child>
                    <Button
                        variant="outline"
                        size="sm"
                        class="h-7 gap-1.5 text-xs"
                    >
                        <Plus class="size-3.5" />Add pool
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add free usage pool</DialogTitle>
                    </DialogHeader>
                    <Form
                        v-bind="AiFreeUsagePoolController.store.form()"
                        class="space-y-4"
                        v-slot="{ errors, processing }"
                        @success="showPoolCreateDialog = false"
                    >
                        <PoolFormFields id-prefix="pool" :errors="errors" />
                        <DialogFooter>
                            <Button type="submit" :disabled="processing"
                                >Save</Button
                            >
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        <th
                            v-for="h in [
                                'Pool',
                                'Period',
                                'Budget',
                                'Models',
                                'Docs',
                                '',
                            ]"
                            :key="h"
                            class="border-b border-border bg-card px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                        >
                            {{ h }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="pool in pools"
                        :key="pool.id"
                        :data-pool-row="pool.id"
                        class="border-b border-border last:border-b-0 hover:bg-bg-hover"
                    >
                        <td class="px-3 py-2.5 font-medium">
                            {{ pool.name }}
                        </td>
                        <td class="px-3 py-2.5">
                            <Pill>{{ pool.period }}</Pill>
                        </td>
                        <td class="font-mono-tabular px-3 py-2.5">
                            <template v-if="pool.unified">
                                {{ formatTokens(pool.free_total_tokens) }}
                                total
                            </template>
                            <template v-else>
                                {{ formatTokens(pool.free_input_tokens) }}
                                in /
                                {{ formatTokens(pool.free_output_tokens) }}
                                out
                            </template>
                        </td>
                        <td class="px-3 py-2.5">
                            {{ pool.prices_count }}
                        </td>
                        <td class="px-3 py-2.5">
                            <a
                                v-if="pool.documentation_url"
                                :href="pool.documentation_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="underline hover:text-foreground"
                                >docs</a
                            >
                            <span v-else class="text-fg-subtle">—</span>
                        </td>
                        <td class="px-3 py-2.5 text-right">
                            <div class="flex justify-end gap-1">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="h-7 px-2 text-xs"
                                    data-pool-edit
                                    @click="emit('edit', pool)"
                                >
                                    Edit
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="size-7 p-0 text-destructive hover:text-destructive"
                                    data-pool-delete
                                    @click="destroyPool(pool)"
                                >
                                    <Trash2 class="size-3.5" />
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="pools.length === 0">
                        <td
                            colspan="6"
                            class="px-3 py-6 text-center text-sm text-fg-subtle"
                        >
                            No pools yet. Pools let several models share one
                            free-usage budget.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
