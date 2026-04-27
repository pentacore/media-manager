<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import AiSettingsController from '@/actions/App/Http/Controllers/Admin/AiSettingsController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

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
            { title: 'Admin', href: '#' },
            { title: 'AI Settings', href: AiSettingsController.index.url() },
        ],
    },
});

const selectedMode = ref(props.settings.mode);
</script>

<template>
    <Head title="AI Settings" />

    <div class="max-w-2xl p-6">
        <Card>
            <CardHeader>
                <CardTitle>AI Settings</CardTitle>
                <CardDescription>
                    Choose the operating mode and which model the AI agent
                    uses. The model is a free-form string — it must match a
                    model identifier supported by a configured laravel/ai
                    provider (e.g.
                    <code>gpt-5-mini</code>, <code>claude-haiku-4-5</code>,
                    <code>gemini-3-flash-preview</code>).
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="AiSettingsController.update.form()"
                    class="space-y-6"
                    v-slot="{ errors, processing }"
                >
                    <div class="space-y-2">
                        <Label for="mode">Mode</Label>
                        <Select
                            name="mode"
                            v-model="selectedMode"
                            :default-value="settings.mode"
                        >
                            <SelectTrigger>
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
                        <p class="text-sm text-muted-foreground">
                            <strong>Executive</strong>: the agent can create
                            and (when auto-approve is configured)
                            auto-execute ActionRequests.<br />
                            <strong>Advisory</strong>: destructive tools
                            refuse to queue and every ActionRequest stays
                            Pending regardless of the per-type rule. Use
                            this while evaluating the AI before shipping
                            full auto.
                        </p>
                        <InputError :message="errors.mode" />
                    </div>

                    <div class="space-y-2">
                        <Label for="model">Model</Label>
                        <Input
                            id="model"
                            name="model"
                            :default-value="settings.model"
                        />
                        <p class="text-sm text-muted-foreground">
                            The model used by MediaAgent for all chat
                            interactions.
                        </p>
                        <InputError :message="errors.model" />
                    </div>

                    <div class="flex gap-2 pt-4">
                        <Button type="submit" :disabled="processing">
                            Save Settings
                        </Button>
                    </div>
                </Form>
            </CardContent>
        </Card>
    </div>
</template>
