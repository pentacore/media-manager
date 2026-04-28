<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import AiSettingsController from '@/actions/App/Http/Controllers/Admin/AiSettingsController';
import InputError from '@/components/InputError.vue';
import { Field } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { dashboard } from '@/routes';

interface ModeOption {
    value: string;
    label: string;
}

interface AiSettingsState {
    mode: string;
    model: string;
}

const props = defineProps<{
    settings: AiSettingsState;
    modes: ModeOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            { title: 'AI Settings', href: AiSettingsController.index.url() },
        ],
    },
});

const selectedMode = ref(props.settings.mode);
</script>

<template>
    <Head title="AI Settings" />

    <div class="flex max-w-3xl flex-col gap-4 p-5">
        <!-- Hero -->
        <div>
            <div class="mb-1.5 text-[13px] text-muted-foreground">
                Admin <span class="text-fg-subtle">/</span> AI settings
            </div>
            <h1
                class="text-[22px] leading-tight font-semibold tracking-tight"
            >
                AI settings
            </h1>
            <p class="mt-1 max-w-[640px] text-[13px] text-muted-foreground">
                Toggle the assistant, choose a model, set the execution mode.
                Overrides
                <span class="font-mono-tabular">.env</span> at runtime.
            </p>
        </div>

        <Form
            v-bind="AiSettingsController.update.form()"
            v-slot="{ errors, processing }"
            class="rounded-xl border border-border bg-card p-6"
        >
            <div class="flex flex-col gap-5">
                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="Mode"
                        hint="Executive routes destructive tool calls through the approval pipeline. Advisory short-circuits them."
                    >
                        <span />
                    </Field>
                    <div>
                        <Select
                            name="mode"
                            v-model="selectedMode"
                            :default-value="settings.mode"
                        >
                            <SelectTrigger class="h-8 w-48 text-sm">
                                <SelectValue placeholder="Select a mode" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="mode in modes"
                                    :key="mode.value"
                                    :value="mode.value"
                                >
                                    {{ mode.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="errors.mode" class="mt-1" />
                    </div>
                </div>

                <Separator />

                <div
                    class="grid items-start gap-6"
                    style="grid-template-columns: 200px 1fr"
                >
                    <Field
                        label="Model"
                        hint="Free-form string — must match a model id supported by a configured laravel/ai provider (e.g. claude-sonnet-4-6, gpt-5-mini, gemini-3-flash)."
                    >
                        <span />
                    </Field>
                    <div>
                        <Input
                            id="model"
                            name="model"
                            class="h-8 max-w-[320px] text-sm"
                            :default-value="settings.model"
                        />
                        <InputError :message="errors.model" class="mt-1" />
                    </div>
                </div>

                <div class="flex justify-end pt-2">
                    <Button
                        size="sm"
                        type="submit"
                        :disabled="processing"
                        class="h-8 text-xs"
                    >
                        Save settings
                    </Button>
                </div>
            </div>
        </Form>
    </div>
</template>
