<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, onMounted, ref, watch } from 'vue';
import BenchmarkVerifierPromptController from '@/actions/App/Http/Controllers/Admin/BenchmarkVerifierPromptController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { jsonRequest } from '@/lib/http';
import {
    EVENT_PAYLOAD_MESSAGES,
    parseJsonObject,
    PAYLOAD_MESSAGES,
} from './jsonObject';
import type {
    BenchmarkVerifierActionType,
    BenchmarkVerifierForm,
} from './types';
import { verifierBody, verifierPayloadsInvalid } from './verifierCase';

const props = defineProps<{
    actionTypes: BenchmarkVerifierActionType[];
    /** Source service suggestions. */
    services: string[];
    /** Server validation errors keyed like `input.verifier.title`. */
    errors: Record<string, string | undefined>;
}>();

const verifier = defineModel<BenchmarkVerifierForm>('verifier', {
    required: true,
});

/** The approval card shows at most this many detail rows. */
const DETAIL_LIMIT = 20;

const servicesListId = 'benchmark-verifier-services';

/** A saved action type outside the supported list stays selectable. */
const actionTypeOptions = computed<BenchmarkVerifierActionType[]>(() => {
    const type = verifier.value.action_type;

    return type === '' ||
        props.actionTypes.some((option) => option.value === type)
        ? props.actionTypes
        : [...props.actionTypes, { value: type, label: type }];
});

function catalogueLabel(type: string): string | undefined {
    return props.actionTypes.find((option) => option.value === type)?.label;
}

// A label the admin left blank or kept from the catalogue follows the type.
watch(
    () => verifier.value.action_type,
    (type, previous) => {
        const label = verifier.value.action_label;
        const next = catalogueLabel(type);

        if (
            next !== undefined &&
            (label.trim() === '' || label === catalogueLabel(previous))
        ) {
            verifier.value.action_label = next;
        }
    },
);

function addDetail(): void {
    verifier.value.details = [
        ...verifier.value.details,
        { label: '', value: '' },
    ];
}

function removeDetail(index: number): void {
    verifier.value.details = verifier.value.details.filter(
        (_, i) => i !== index,
    );
}

const payloadError = computed(
    () =>
        parseJsonObject(verifier.value.payload_json, PAYLOAD_MESSAGES).error ??
        props.errors['input.verifier.payload_json'],
);

const eventPayloadError = computed(
    () =>
        parseJsonObject(
            verifier.value.event_payload_json,
            EVENT_PAYLOAD_MESSAGES,
        ).error ?? props.errors['input.verifier.event_payload_json'],
);

const preview = ref('');
const previewError = ref<string | null>(null);
let previewRequest = 0;

const refreshPreview = useDebounceFn(async (): Promise<void> => {
    const request = ++previewRequest;

    if (
        verifier.value.title.trim() === '' ||
        verifier.value.description.trim() === '' ||
        verifierPayloadsInvalid(verifier.value)
    ) {
        preview.value = '';
        previewError.value = null;

        return;
    }

    try {
        const response = await jsonRequest<{ prompt: string }>(
            'POST',
            BenchmarkVerifierPromptController.url(),
            verifierBody(verifier.value),
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
                    : 'Could not render the case file.';
        }
    }
}, 300);

// The first preview waits for the browser: during SSR there is no server to reach.
onMounted(() => {
    void refreshPreview();
});

watch(
    verifier,
    () => {
        void refreshPreview();
    },
    { deep: true },
);

const inputClass =
    'h-9 rounded-md border border-input bg-background px-2 text-sm text-foreground';
const textareaClass =
    'rounded-md border border-input bg-background px-3 py-2 text-[13px] text-foreground';
const jsonTextareaClass =
    'rounded-md border border-input bg-background px-3 py-2 font-mono text-[12.5px] text-foreground';
</script>

<template>
    <div class="grid gap-3" data-verifier-case>
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="grid gap-1.5 text-[12.5px] font-medium">
                Action type
                <select
                    v-model="verifier.action_type"
                    :class="inputClass"
                    data-verifier-action-type
                >
                    <option
                        v-for="option in actionTypeOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{
                            option.label === option.value
                                ? option.value
                                : `${option.label} (${option.value})`
                        }}
                    </option>
                </select>
                <InputError :message="errors['input.verifier.action_type']" />
            </label>
            <label class="grid gap-1.5 text-[12.5px] font-medium">
                Action label
                <input
                    v-model="verifier.action_label"
                    type="text"
                    :class="inputClass"
                    data-verifier-action-label
                />
                <InputError :message="errors['input.verifier.action_label']" />
            </label>
        </div>

        <label class="grid gap-1.5 text-[12.5px] font-medium">
            Title
            <input
                v-model="verifier.title"
                type="text"
                :class="inputClass"
                data-verifier-title
            />
            <InputError :message="errors['input.verifier.title']" />
        </label>

        <label class="flex items-center gap-1.5 text-[13px]">
            <input
                v-model="verifier.verified"
                type="checkbox"
                data-verifier-verified
            />
            Target name verified by the server
        </label>

        <label class="grid gap-1.5 text-[12.5px] font-medium">
            Description
            <textarea
                v-model="verifier.description"
                rows="3"
                :class="textareaClass"
                data-verifier-description
            />
            <InputError :message="errors['input.verifier.description']" />
        </label>

        <div class="grid gap-2">
            <div class="flex items-center justify-between">
                <span class="text-[12.5px] font-medium">Details</span>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="h-7 gap-1 text-xs"
                    :disabled="verifier.details.length >= DETAIL_LIMIT"
                    data-verifier-detail-add
                    @click="addDetail"
                >
                    <Plus class="size-3.5" />Add detail
                </Button>
            </div>
            <div
                v-for="(detail, index) in verifier.details"
                :key="index"
                class="grid gap-2 sm:grid-cols-[1fr_2fr_auto]"
                data-verifier-detail-row
            >
                <div class="grid gap-1">
                    <input
                        v-model="detail.label"
                        type="text"
                        placeholder="Label"
                        aria-label="Detail label"
                        :class="inputClass"
                        data-verifier-detail-label
                    />
                    <InputError
                        :message="
                            errors[`input.verifier.details.${index}.label`]
                        "
                    />
                </div>
                <div class="grid gap-1">
                    <input
                        v-model="detail.value"
                        type="text"
                        placeholder="Value"
                        aria-label="Detail value"
                        :class="inputClass"
                        data-verifier-detail-value
                    />
                    <InputError
                        :message="
                            errors[`input.verifier.details.${index}.value`]
                        "
                    />
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="size-9 text-destructive"
                    aria-label="Remove detail"
                    data-verifier-detail-remove
                    @click="removeDetail(index)"
                >
                    <Trash2 class="size-3.5" />
                </Button>
            </div>
            <InputError :message="errors['input.verifier.details']" />
        </div>

        <label class="grid gap-1.5 text-[12.5px] font-medium">
            Payload (JSON object)
            <textarea
                v-model="verifier.payload_json"
                rows="5"
                :class="jsonTextareaClass"
                data-verifier-payload
            />
            <span class="block min-h-5" data-verifier-payload-error>
                <InputError :message="payloadError" />
            </span>
        </label>

        <label class="grid gap-1.5 text-[12.5px] font-medium">
            Rationale
            <textarea
                v-model="verifier.rationale"
                rows="3"
                :class="textareaClass"
                data-verifier-rationale
            />
            <InputError :message="errors['input.verifier.rationale']" />
        </label>

        <div class="grid gap-3 sm:grid-cols-2">
            <label class="grid gap-1.5 text-[12.5px] font-medium">
                Source service
                <input
                    v-model="verifier.source_service"
                    type="text"
                    :list="servicesListId"
                    :class="inputClass"
                    data-verifier-source-service
                />
                <datalist :id="servicesListId">
                    <option
                        v-for="service in services"
                        :key="service"
                        :value="service"
                    />
                </datalist>
                <InputError
                    :message="errors['input.verifier.source_service']"
                />
            </label>
            <label class="grid gap-1.5 text-[12.5px] font-medium">
                Event type
                <input
                    v-model="verifier.event_type"
                    type="text"
                    :class="inputClass"
                    data-verifier-event-type
                />
                <InputError :message="errors['input.verifier.event_type']" />
            </label>
        </div>

        <label class="grid gap-1.5 text-[12.5px] font-medium">
            Event payload (JSON object)
            <textarea
                v-model="verifier.event_payload_json"
                rows="5"
                :class="jsonTextareaClass"
                data-verifier-event-payload
            />
            <span class="block min-h-5" data-verifier-event-payload-error>
                <InputError :message="eventPayloadError" />
            </span>
        </label>

        <div class="grid gap-1.5">
            <span class="text-[12.5px] font-medium"
                >Case file the verifier will see</span
            >
            <pre
                class="max-h-80 min-h-16 overflow-auto rounded-md border border-border bg-card p-3 font-mono text-[12px] whitespace-pre-wrap text-foreground"
                data-verifier-prompt-preview
                >{{ preview }}</pre>
            <p
                v-if="previewError"
                class="text-xs text-destructive"
                data-verifier-prompt-preview-error
            >
                {{ previewError }}
            </p>
        </div>
    </div>
</template>
