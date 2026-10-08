<script setup lang="ts">
import { ArrowDown, ArrowUp, Plus, Trash2 } from '@lucide/vue';
import ModelSelect from '@/components/ai/ModelSelect.vue';
import ReasoningSelect from '@/components/ai/ReasoningSelect.vue';
import InputError from '@/components/InputError.vue';
import { Pill } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { AiReasoningLevel } from '@/typefinder';

export type TierForm = {
    provider: string | null;
    model: string | null;
    reasoning: AiReasoningLevel | null;
    min_pool_percent: number | null;
    min_pool_tokens: number | null;
};

export type ModelPool = {
    name: string;
    percent_left: number;
    tokens_left: number;
};

const props = withDefaults(
    defineProps<{
        models: Record<string, string[]>;
        reasoningLevels: Array<{ label: string; value: AiReasoningLevel }>;
        modelCapabilities: Record<
            string,
            {
                supports_reasoning: boolean | null;
                levels: AiReasoningLevel[] | null;
            }
        >;
        reasoningProviders: string[];
        modelPools: Record<string, ModelPool>;
        /** What an inherit tier runs on (the server's resolved pair). */
        inheritedProvider: string;
        inheritedModel: string;
        inheritLabel?: string;
        reasoningInheritLabel?: string;
        hasReasoning?: boolean;
        allowAuto?: boolean;
        /** Error key prefix, e.g. `tasks.chat.tiers`. */
        errorPrefix: string;
        errors: Record<string, string | undefined>;
    }>(),
    {
        inheritLabel: undefined,
        reasoningInheritLabel: 'Default',
        hasReasoning: true,
        allowAuto: false,
    },
);

const tiers = defineModel<TierForm[]>({ required: true });

const compact = new Intl.NumberFormat(undefined, { notation: 'compact' });

function blankTier(): TierForm {
    return {
        provider: null,
        model: null,
        reasoning: null,
        min_pool_percent: null,
        min_pool_tokens: null,
    };
}

function isLast(index: number): boolean {
    return index === tiers.value.length - 1;
}

/** New tiers go directly above the last one, so the fallback stays last. */
function addTier(): void {
    tiers.value.splice(tiers.value.length - 1, 0, blankTier());
}

function removeTier(index: number): void {
    tiers.value.splice(index, 1);
    clearLastConditions();
}

function moveTier(index: number, offset: -1 | 1): void {
    const [tier] = tiers.value.splice(index, 1);

    if (tier === undefined) {
        return;
    }

    tiers.value.splice(index + offset, 0, tier);
    clearLastConditions();
}

/** The last tier always runs, so it never keeps conditions. */
function clearLastConditions(): void {
    const last = tiers.value[tiers.value.length - 1];

    if (last) {
        last.min_pool_percent = null;
        last.min_pool_tokens = null;
    }
}

function setPair(
    tier: TierForm,
    field: 'provider' | 'model',
    value: string,
): void {
    tier[field] = value === '' ? null : value;
}

function setCondition(
    tier: TierForm,
    field: 'min_pool_percent' | 'min_pool_tokens',
    value: string | number,
): void {
    const parsed = Number.parseInt(String(value), 10);
    tier[field] = Number.isNaN(parsed) ? null : parsed;
}

function pairOf(tier: TierForm): { provider: string; model: string } {
    return tier.model
        ? { provider: tier.provider ?? '', model: tier.model }
        : { provider: props.inheritedProvider, model: props.inheritedModel };
}

function poolOf(tier: TierForm): ModelPool | null {
    const { provider, model } = pairOf(tier);

    return props.modelPools[`${provider}|${model}`] ?? null;
}

/** Conditions apply only to a named model (not auto) that has a free pool. */
function conditionsEnabled(tier: TierForm): boolean {
    return (
        Boolean(tier.model) && tier.model !== 'auto' && poolOf(tier) !== null
    );
}

function poolLine(tier: TierForm): string | null {
    if (!tier.model || tier.model === 'auto') {
        return null;
    }

    const pool = poolOf(tier);

    if (pool === null) {
        return 'No free pool — always eligible';
    }

    return `${pool.name} · ${Math.floor(pool.percent_left)}% left · ${compact.format(pool.tokens_left)} tokens`;
}

function reasoningHint(tier: TierForm): string | null {
    const { provider, model } = pairOf(tier);

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

function acceptedLevels(tier: TierForm): AiReasoningLevel[] | null {
    const { provider, model } = pairOf(tier);

    return props.modelCapabilities[`${provider}|${model}`]?.levels ?? null;
}

function errorFor(index: number): string | undefined {
    return [
        'model',
        'provider',
        'reasoning',
        'min_pool_percent',
        'min_pool_tokens',
    ]
        .map((field) => props.errors[`${props.errorPrefix}.${index}.${field}`])
        .find(Boolean);
}
</script>

<template>
    <div class="flex min-w-0 flex-col gap-2">
        <div
            v-for="(tier, index) in tiers"
            :key="index"
            :class="[
                'flex min-w-0 flex-col gap-2',
                tiers.length > 1 && 'rounded-lg border border-border p-3',
            ]"
            :data-tier-row="index"
        >
            <div v-if="tiers.length > 1" class="flex items-center gap-2">
                <span class="text-[12px] font-semibold text-muted-foreground">
                    Tier {{ index + 1 }}
                </span>
                <Pill v-if="isLast(index)" data-tier-always>Always</Pill>
                <div class="ml-auto flex items-center">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :disabled="index === 0"
                        :aria-label="`Move tier ${index + 1} up`"
                        data-tier-up
                        @click="moveTier(index, -1)"
                    >
                        <ArrowUp class="size-4" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :disabled="isLast(index)"
                        :aria-label="`Move tier ${index + 1} down`"
                        data-tier-down
                        @click="moveTier(index, 1)"
                    >
                        <ArrowDown class="size-4" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :aria-label="`Remove tier ${index + 1}`"
                        data-tier-remove
                        @click="removeTier(index)"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </div>
            </div>
            <div data-model-select>
                <ModelSelect
                    :models="models"
                    :allow-auto="allowAuto"
                    :inherit-label="isLast(index) ? inheritLabel : undefined"
                    :provider="tier.provider ?? ''"
                    :model="tier.model ?? ''"
                    @update:provider="
                        (value: string) => setPair(tier, 'provider', value)
                    "
                    @update:model="
                        (value: string) => setPair(tier, 'model', value)
                    "
                />
            </div>
            <ReasoningSelect
                v-if="hasReasoning"
                v-model="tier.reasoning"
                :levels="reasoningLevels"
                :inherit-label="reasoningInheritLabel"
                :accepted="acceptedLevels(tier)"
                :disabled-hint="reasoningHint(tier)"
            />
            <p
                v-if="poolLine(tier)"
                class="font-mono-tabular text-[12px] text-muted-foreground"
                data-tier-pool
            >
                {{ poolLine(tier) }}
            </p>
            <div
                v-if="!isLast(index)"
                class="flex flex-wrap items-center gap-2 text-[12px] text-muted-foreground"
            >
                <span>Only while</span>
                <Input
                    type="number"
                    min="1"
                    max="100"
                    class="h-8 w-20 text-sm"
                    placeholder="—"
                    :aria-label="`Tier ${index + 1} minimum percent left`"
                    :disabled="!conditionsEnabled(tier)"
                    :model-value="tier.min_pool_percent ?? ''"
                    data-tier-min-percent
                    @update:model-value="
                        (value) => setCondition(tier, 'min_pool_percent', value)
                    "
                />
                <span>% and</span>
                <Input
                    type="number"
                    min="1"
                    class="h-8 w-32 text-sm"
                    placeholder="—"
                    :aria-label="`Tier ${index + 1} minimum tokens left`"
                    :disabled="!conditionsEnabled(tier)"
                    :model-value="tier.min_pool_tokens ?? ''"
                    data-tier-min-tokens
                    @update:model-value="
                        (value) => setCondition(tier, 'min_pool_tokens', value)
                    "
                />
                <span>tokens are left in its pool</span>
            </div>
            <InputError :message="errorFor(index)" />
        </div>
        <Button
            type="button"
            variant="outline"
            size="sm"
            class="self-start"
            data-tier-add
            @click="addTier"
        >
            <Plus class="size-4" />
            Add tier
        </Button>
    </div>
</template>
