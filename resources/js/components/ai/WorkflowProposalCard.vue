<script setup lang="ts">
import { Cpu } from '@lucide/vue';
import { Pill } from '@/components/mm';
import { Button } from '@/components/ui/button';
import type { WorkflowProposal } from './types';

defineProps<{
    workflow: WorkflowProposal;
    resolved?: 'approved' | 'declined' | null;
    disabled: boolean;
}>();

const emit = defineEmits<{
    (e: 'approve'): void;
    (e: 'decline'): void;
}>();
</script>

<template>
    <div
        class="mb-2 rounded-lg border border-border bg-bg-elev p-2.5"
        data-workflow-proposal
    >
        <div class="mb-1.5 flex items-center justify-between">
            <span class="flex items-center gap-1.5">
                <Cpu class="size-3.5 text-fg-subtle" />
                <span class="text-[12px] font-medium">Proposed workflow</span>
                <Pill
                    :variant="
                        resolved === 'approved'
                            ? 'ok'
                            : resolved === 'declined'
                              ? 'danger'
                              : 'warn'
                    "
                    class="text-[10px]"
                >
                    {{ resolved ?? 'awaiting' }}
                </Pill>
            </span>
        </div>
        <p class="mb-2 text-[12.5px] text-muted-foreground">
            {{ workflow.rationale }}
        </p>
        <ol
            class="mb-3 list-inside list-decimal space-y-1 text-[11.5px] text-muted-foreground"
        >
            <li v-for="(step, i) in workflow.steps" :key="i">
                <span class="font-mono-tabular text-foreground">{{
                    step.action
                }}</span>
                on
                <span class="font-medium">{{ step.target }}</span>
                — {{ step.reason }}
            </li>
        </ol>
        <div v-if="!resolved" class="flex gap-2">
            <Button
                size="sm"
                class="h-7 text-xs"
                :disabled="disabled"
                @click="emit('approve')"
            >
                Approve
            </Button>
            <Button
                size="sm"
                variant="outline"
                class="h-7 text-xs"
                :disabled="disabled"
                @click="emit('decline')"
            >
                Decline
            </Button>
        </div>
        <p v-else class="text-[11.5px] text-muted-foreground">
            {{ resolved === 'approved' ? 'Approved.' : 'Declined.' }}
        </p>
    </div>
</template>
