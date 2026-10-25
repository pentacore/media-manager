<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import ActionRequestController from '@/actions/App/Http/Controllers/Actions/ActionRequestController';

defineProps<{
    batchId: number;
    /** The page's `filters.status`: kept when the batch filter is cleared. */
    status: string;
}>();
</script>

<template>
    <div class="flex items-center">
        <span
            class="inline-flex h-7 items-center gap-1.5 rounded-md bg-accent px-2.5 text-xs font-medium text-accent-foreground"
            data-action-batch-filter
        >
            Subtitle audit batch #{{ batchId }}
            <Link
                :href="
                    ActionRequestController.index.url({
                        query: status === '' ? {} : { status },
                    })
                "
                class="inline-flex items-center opacity-70 hover:opacity-100"
                aria-label="Clear the batch filter"
                preserve-scroll
                data-action-batch-filter-clear
            >
                <X class="size-3" />
            </Link>
        </span>
    </div>
</template>
