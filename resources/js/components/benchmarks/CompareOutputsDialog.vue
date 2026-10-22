<script setup lang="ts">
import { Pill } from '@/components/mm';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { formatScore } from './format';
import type { BenchmarkCompareOutput } from './types';

defineProps<{
    outputs: BenchmarkCompareOutput[] | null;
    loading: boolean;
}>();

const open = defineModel<boolean>('open', { default: false });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="max-h-[90vh] min-w-0 overflow-y-auto sm:max-w-5xl"
            data-compare-outputs
        >
            <DialogHeader>
                <DialogTitle>Baseline vs this run</DialogTitle>
                <DialogDescription>
                    The same case and model, side by side.
                </DialogDescription>
            </DialogHeader>
            <div v-if="loading || outputs === null" class="space-y-3">
                <Skeleton class="h-4 w-3/4" />
                <Skeleton class="h-24 w-full" />
            </div>
            <div v-else class="grid min-w-0 gap-4 md:grid-cols-2">
                <article
                    v-for="(output, index) in outputs"
                    :key="output.result_id"
                    class="flex min-w-0 flex-col gap-2 rounded-lg border border-border p-3"
                    :data-compare-output-side="
                        index === 0 ? 'baseline' : 'other'
                    "
                >
                    <header class="flex flex-wrap items-center gap-2">
                        <span class="text-[13px] font-semibold">{{
                            output.run_name
                        }}</span>
                        <span class="text-[12px] text-muted-foreground">{{
                            output.candidate_label
                        }}</span>
                        <Pill
                            v-if="output.score !== null"
                            :variant="output.passed ? 'ok' : 'danger'"
                            >{{ formatScore(output.score) }} / 10</Pill
                        >
                        <Pill
                            v-else-if="output.status !== 'ok'"
                            variant="danger"
                            >{{ output.status }}</Pill
                        >
                    </header>
                    <p
                        v-if="output.error"
                        class="rounded-md border border-destructive/30 bg-destructive/10 p-2 text-[12.5px] text-destructive"
                    >
                        {{ output.error }}
                    </p>
                    <pre
                        v-if="output.output !== null"
                        data-compare-output-text
                        class="max-h-72 overflow-auto rounded-md border border-border bg-bg-elev p-2 font-mono text-[12px] break-words whitespace-pre-wrap"
                        >{{ output.output }}</pre>
                    <p v-if="output.rationale" class="text-[12.5px]">
                        <span class="font-semibold"
                            >Judge<template v-if="output.judge_label">
                                ({{ output.judge_label }})</template
                            >:</span
                        >
                        {{ output.rationale }}
                    </p>
                </article>
            </div>
        </DialogContent>
    </Dialog>
</template>
