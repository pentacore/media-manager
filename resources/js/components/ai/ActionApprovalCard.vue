<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import { computed } from 'vue';
import ActionRequestController from '@/actions/App/Http/Controllers/Actions/ActionRequestController';
import { Pill } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { useAiChat } from '@/composables/useAiChat';
import type { ActionRequestStatus } from '@/typefinder';
import type { ActionRequestCard } from './types';

const props = defineProps<{
    card: ActionRequestCard;
    /** The approve/reject request for this card is in flight. */
    busy: boolean;
}>();

const emit = defineEmits<{
    (e: 'approve'): void;
    (e: 'reject'): void;
}>();

const { closeChat } = useAiChat();

const STATUS_VARIANTS: Record<ActionRequestStatus, 'ok' | 'warn' | 'danger'> = {
    pending: 'warn',
    approved: 'ok',
    executing: 'ok',
    completed: 'ok',
    rejected: 'danger',
    failed: 'danger',
};

/** 'changed' (reviewed elsewhere first) and unknown statuses stay neutral. */
const variant = computed(
    () =>
        STATUS_VARIANTS[props.card.status as ActionRequestStatus] ?? 'default',
);
</script>

<template>
    <div
        class="mb-2 rounded-lg border border-border bg-bg-elev p-2.5"
        data-action-approval-card
    >
        <div class="mb-2 flex items-center justify-between gap-2">
            <span class="flex min-w-0 items-center gap-1.5">
                <ShieldCheck class="size-3.5 shrink-0 text-fg-subtle" />
                <span class="truncate text-[12.5px] font-medium">{{
                    card.title ?? `Queued action #${card.id}`
                }}</span>
            </span>
            <Pill
                :variant="variant"
                class="shrink-0 text-[10px]"
                data-action-status
            >
                {{
                    card.status === 'changed'
                        ? 'Changed elsewhere'
                        : card.status
                }}
            </Pill>
        </div>
        <div class="flex items-center gap-2">
            <template v-if="card.status === 'pending'">
                <Button
                    size="sm"
                    class="h-7 text-xs"
                    :disabled="busy"
                    data-action-approve
                    @click="emit('approve')"
                >
                    Approve
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    class="h-7 text-xs"
                    :disabled="busy"
                    data-action-reject
                    @click="emit('reject')"
                >
                    Reject
                </Button>
            </template>
            <Link
                :href="
                    ActionRequestController.index.url({
                        query: { request: card.id },
                    })
                "
                class="text-[11.5px] text-muted-foreground hover:text-foreground"
                data-action-queue-link
                @click="closeChat()"
                >Open in Action Queue</Link
            >
        </div>
    </div>
</template>
