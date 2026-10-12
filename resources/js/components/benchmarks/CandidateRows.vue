<script setup lang="ts">
import { Layers, Plus, Trash2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import type { AiReasoningLevel } from '@/typefinder';
import SelectionRow from './SelectionRow.vue';
import type {
    BenchmarkModelCapabilities,
    BenchmarkSelectionForm,
} from './types';

const MAX_CANDIDATES = 8;

defineProps<{
    models: Record<string, string[]>;
    reasoningLevels: Array<{ label: string; value: AiReasoningLevel }>;
    modelCapabilities: BenchmarkModelCapabilities;
    reasoningProviders: string[];
    canAddTiers: boolean;
    error?: string;
}>();

const emit = defineEmits<{ addTiers: [] }>();

const candidates = defineModel<BenchmarkSelectionForm[]>({ required: true });

function addCandidate(): void {
    if (candidates.value.length < MAX_CANDIDATES) {
        candidates.value.push({
            provider: '',
            model: '',
            reasoning: 'provider_default',
        });
    }
}
</script>

<template>
    <section class="grid gap-3" data-benchmark-candidates>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-[13px] font-semibold">
                Candidates
                <span class="font-normal text-muted-foreground"
                    >({{ candidates.length }} / {{ MAX_CANDIDATES }})</span
                >
            </h2>
            <div class="flex gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="gap-1.5"
                    :disabled="!canAddTiers"
                    data-benchmark-add-tiers
                    @click="emit('addTiers')"
                >
                    <Layers class="size-3.5" />Add current tiers
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="gap-1.5"
                    :disabled="candidates.length >= MAX_CANDIDATES"
                    data-benchmark-add-candidate
                    @click="addCandidate"
                >
                    <Plus class="size-3.5" />Add candidate
                </Button>
            </div>
        </div>
        <p
            v-if="candidates.length === 0"
            class="text-[12.5px] text-muted-foreground"
        >
            Add at least one candidate model.
        </p>
        <SelectionRow
            v-for="(candidate, index) in candidates"
            :key="index"
            v-model="candidates[index]"
            class="rounded-lg border border-border bg-card p-3"
            :models="models"
            :reasoning-levels="reasoningLevels"
            :model-capabilities="modelCapabilities"
            :reasoning-providers="reasoningProviders"
            :data-benchmark-candidate="index"
        >
            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="ml-auto"
                :aria-label="`Remove candidate ${index + 1}`"
                data-benchmark-remove-candidate
                @click="candidates.splice(index, 1)"
            >
                <Trash2 class="size-4" />
            </Button>
        </SelectionRow>
        <InputError :message="error" />
    </section>
</template>
