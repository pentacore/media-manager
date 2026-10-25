<script setup lang="ts">
import { useDebounceFn } from '@vueuse/core';
import { computed, onMounted, ref, watch } from 'vue';
import BenchmarkDecisionPromptController from '@/actions/App/Http/Controllers/Admin/BenchmarkDecisionPromptController';
import InputError from '@/components/InputError.vue';
import { jsonRequest } from '@/lib/http';
import { parseJsonObject, PAYLOAD_MESSAGES } from './jsonObject';
import type {
    BenchmarkActionKindOption,
    BenchmarkDecisionEvent,
} from './types';

const props = defineProps<{
    services: string[];
    eventKeys: string[];
    actionKinds: BenchmarkActionKindOption[];
    /** Server validation errors keyed like `input.event.service`. */
    errors: Record<string, string | undefined>;
}>();

const event = defineModel<BenchmarkDecisionEvent>('event', { required: true });
const actionKind = defineModel<string>('actionKind', { required: true });

const datalistId = 'benchmark-event-types';

/** The event types the catalog lists for the chosen service. */
const eventTypes = computed(() =>
    props.eventKeys
        .filter((key) => key.startsWith(`${event.value.service}:`))
        .map((key) => key.slice(event.value.service.length + 1)),
);

const payloadError = computed(
    () =>
        parseJsonObject(event.value.payload_json, PAYLOAD_MESSAGES).error ??
        props.errors['input.event.payload_json'],
);

const preview = ref('');
const previewError = ref<string | null>(null);
let previewRequest = 0;

const refreshPreview = useDebounceFn(async (): Promise<void> => {
    const request = ++previewRequest;

    if (
        event.value.event_type.trim() === '' ||
        parseJsonObject(event.value.payload_json, PAYLOAD_MESSAGES).error
    ) {
        preview.value = '';
        previewError.value = null;

        return;
    }

    try {
        const response = await jsonRequest<{ prompt: string }>(
            'POST',
            BenchmarkDecisionPromptController.url(),
            {
                service: event.value.service,
                event_type: event.value.event_type,
                payload_json:
                    event.value.payload_json.trim() === ''
                        ? '{}'
                        : event.value.payload_json,
            },
        );

        if (request === previewRequest) {
            preview.value = response.prompt;
            previewError.value = null;
        }
    } catch (error) {
        if (request === previewRequest) {
            preview.value = '';
            previewError.value =
                error instanceof Error
                    ? error.message
                    : 'Could not render the prompt.';
        }
    }
}, 300);

// The first preview waits for the browser: during SSR there is no server to reach.
onMounted(() => {
    void refreshPreview();
});

watch(
    event,
    () => {
        void refreshPreview();
    },
    { deep: true },
);

const selectClass =
    'h-9 rounded-md border border-input bg-background px-2 text-sm text-foreground';
const textareaClass =
    'rounded-md border border-input bg-background px-3 py-2 font-mono text-[12.5px] text-foreground';
</script>

<template>
    <div class="grid gap-3" data-benchmark-decision-event>
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="grid gap-1.5 text-[12.5px] font-medium">
                Service
                <select
                    v-model="event.service"
                    :class="selectClass"
                    data-benchmark-event-service
                >
                    <option
                        v-for="service in services"
                        :key="service"
                        :value="service"
                    >
                        {{ service }}
                    </option>
                </select>
                <InputError :message="errors['input.event.service']" />
            </label>
            <label class="grid gap-1.5 text-[12.5px] font-medium">
                Event type
                <input
                    v-model="event.event_type"
                    type="text"
                    :list="datalistId"
                    :class="selectClass"
                    data-benchmark-event-type
                />
                <datalist :id="datalistId">
                    <option
                        v-for="type in eventTypes"
                        :key="type"
                        :value="type"
                    />
                </datalist>
                <InputError :message="errors['input.event.event_type']" />
            </label>
        </div>

        <label class="grid gap-1.5 text-[12.5px] font-medium">
            Payload (JSON object)
            <textarea
                v-model="event.payload_json"
                rows="8"
                :class="textareaClass"
                data-benchmark-event-payload
            />
            <span class="block min-h-5" data-benchmark-event-payload-error>
                <InputError :message="payloadError" />
            </span>
        </label>

        <label class="grid gap-1.5 text-[12.5px] font-medium sm:max-w-md">
            Action scope
            <select
                v-model="actionKind"
                :class="selectClass"
                data-benchmark-action-kind
            >
                <option value="">Full toolset</option>
                <option
                    v-for="kind in actionKinds"
                    :key="kind.value"
                    :value="kind.value"
                >
                    {{ kind.label }}
                </option>
            </select>
            <InputError :message="errors['input.action_kind']" />
        </label>

        <div class="grid gap-1.5">
            <span class="text-[12.5px] font-medium"
                >Prompt the agent will see</span
            >
            <pre
                class="max-h-80 min-h-16 overflow-auto rounded-md border border-border bg-card p-3 font-mono text-[12px] whitespace-pre-wrap text-foreground"
                data-benchmark-prompt-preview
                >{{ preview }}</pre>
            <p
                v-if="previewError"
                class="text-xs text-destructive"
                data-benchmark-prompt-preview-error
            >
                {{ previewError }}
            </p>
        </div>
    </div>
</template>
