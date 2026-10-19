<script setup lang="ts">
import { Field } from '@/components/mm';
import { cn } from '@/lib/utils';
import { pipelineState } from './actionRow';
import type { ActionRequestRow } from './types';

defineProps<{
    request: ActionRequestRow;
}>();
</script>

<template>
    <Field label="Pipeline">
        <ol
            class="font-mono-tabular flex flex-col gap-1 rounded-md border border-border bg-bg-elev p-2.5 text-[11px]"
        >
            <li
                v-for="stage in [
                    { id: 'created', label: 'created' },
                    { id: 'approved', label: 'approved' },
                    {
                        id: 'executing',
                        label: 'executing',
                    },
                    { id: 'done', label: 'completed' },
                ]"
                :key="stage.id"
                :class="
                    cn(
                        'flex items-center gap-2',
                        pipelineState(
                            request,
                            stage.id as
                                'created' | 'approved' | 'executing' | 'done',
                        ) === 'done'
                            ? 'text-accent'
                            : pipelineState(
                                    request,
                                    stage.id as
                                        | 'created'
                                        | 'approved'
                                        | 'executing'
                                        | 'done',
                                ) === 'failed'
                              ? 'text-destructive'
                              : 'text-fg-subtle',
                    )
                "
            >
                <span>
                    <template
                        v-if="
                            pipelineState(
                                request,
                                stage.id as
                                    | 'created'
                                    | 'approved'
                                    | 'executing'
                                    | 'done',
                            ) === 'done'
                        "
                        >●</template
                    >
                    <template
                        v-else-if="
                            pipelineState(
                                request,
                                stage.id as
                                    | 'created'
                                    | 'approved'
                                    | 'executing'
                                    | 'done',
                            ) === 'failed'
                        "
                        >✕</template
                    >
                    <template v-else>○</template>
                </span>
                <span>{{
                    stage.id === 'done' && request.status === 'failed'
                        ? 'failed'
                        : stage.label
                }}</span>
            </li>
        </ol>
    </Field>
</template>
