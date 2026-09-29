<script setup lang="ts">
import { computed } from 'vue';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

const props = withDefaults(
    defineProps<{
        models: Record<string, string[]>;
        name?: string;
        inheritLabel?: string;
        allowAuto?: boolean;
        placeholder?: string;
    }>(),
    {
        name: undefined,
        inheritLabel: undefined,
        allowAuto: false,
        placeholder: 'Select a model',
    },
);

const provider = defineModel<string>('provider', { default: '' });
const model = defineModel<string>('model', { default: '' });

/**
 * Select items cannot carry an empty value, so "inherit" uses a sentinel and
 * each option encodes its provider + model pair as JSON.
 */
const INHERIT = '__inherit__';
const AUTO_MODEL = 'auto';

function encode(providerKey: string, modelId: string): string {
    return JSON.stringify([providerKey, modelId]);
}

const selected = computed<string>({
    get() {
        if (provider.value === '' && model.value === '') {
            return props.inheritLabel ? INHERIT : '';
        }

        return encode(provider.value, model.value);
    },
    set(value: string) {
        if (value === INHERIT) {
            provider.value = '';
            model.value = '';

            return;
        }

        const [providerKey, modelId] = JSON.parse(value) as [string, string];
        provider.value = providerKey;
        model.value = modelId;
    },
});

/**
 * Catalog models per provider, plus the current selection when it is not
 * (or no longer) in the catalog, so a saved value always renders.
 */
const groups = computed<Record<string, string[]>>(() => {
    const result: Record<string, string[]> = {};

    for (const [providerKey, modelList] of Object.entries(props.models)) {
        result[providerKey] = [...modelList];
    }

    if (
        provider.value !== '' &&
        model.value !== '' &&
        model.value !== AUTO_MODEL &&
        !(result[provider.value] ?? []).includes(model.value)
    ) {
        result[provider.value] = [
            ...(result[provider.value] ?? []),
            model.value,
        ];
    }

    if (provider.value !== '' && result[provider.value] === undefined) {
        result[provider.value] = [];
    }

    return result;
});
</script>

<template>
    <div>
        <Select v-model="selected">
            <SelectTrigger class="h-8 max-w-[320px] text-sm">
                <SelectValue :placeholder="placeholder" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem v-if="inheritLabel" :value="INHERIT">
                    {{ inheritLabel }}
                </SelectItem>
                <SelectGroup
                    v-for="(modelList, providerKey) in groups"
                    :key="providerKey"
                >
                    <SelectLabel class="capitalize">
                        {{ providerKey }}
                    </SelectLabel>
                    <SelectItem
                        v-if="allowAuto"
                        :value="encode(String(providerKey), AUTO_MODEL)"
                    >
                        auto (cheapest)
                    </SelectItem>
                    <SelectItem
                        v-for="modelId in modelList"
                        :key="modelId"
                        :value="encode(String(providerKey), modelId)"
                    >
                        {{ modelId }}
                    </SelectItem>
                </SelectGroup>
            </SelectContent>
        </Select>
        <template v-if="name">
            <input type="hidden" :name="`${name}_provider`" :value="provider" />
            <input type="hidden" :name="name" :value="model" />
        </template>
    </div>
</template>
