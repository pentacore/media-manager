<script setup lang="ts">
import ModelSelect from '@/components/ai/ModelSelect.vue';
import ReasoningSelect from '@/components/ai/ReasoningSelect.vue';
import type { AiReasoningLevel } from '@/typefinder';
import type {
    BenchmarkModelCapabilities,
    BenchmarkSelectionForm,
} from './types';

const props = defineProps<{
    models: Record<string, string[]>;
    reasoningLevels: Array<{ label: string; value: AiReasoningLevel }>;
    modelCapabilities: BenchmarkModelCapabilities;
    reasoningProviders: string[];
}>();

const selection = defineModel<BenchmarkSelectionForm>({ required: true });

function reasoningHint(): string | null {
    const { provider, model } = selection.value;

    if (model === '') {
        return null;
    }

    if (!props.reasoningProviders.includes(provider)) {
        return 'Not supported by this provider';
    }

    if (
        props.modelCapabilities[`${provider}|${model}`]?.supports_reasoning ===
        false
    ) {
        return "Model doesn't reason";
    }

    return null;
}

function acceptedLevels(): AiReasoningLevel[] | null {
    const { provider, model } = selection.value;

    return props.modelCapabilities[`${provider}|${model}`]?.levels ?? null;
}
</script>

<template>
    <div class="flex flex-wrap items-start gap-2">
        <div data-benchmark-model-select>
            <ModelSelect
                v-model:provider="selection.provider"
                v-model:model="selection.model"
                :models="models"
            />
        </div>
        <ReasoningSelect
            v-model="selection.reasoning"
            :levels="reasoningLevels"
            :accepted="acceptedLevels()"
            :disabled-hint="reasoningHint()"
        />
        <slot />
    </div>
</template>
