<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import type { AcceptableValue } from 'reka-ui';
import { computed, watch } from 'vue';
import ServerCheckController from '@/actions/App/Http/Controllers/Admin/ServerCheckController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { DbPingEngine, StoreServerCheckRequest } from '@/typefinder';
import { CHECK_FIELDS, checkParams } from './checkFields';
import type { CheckField } from './checkFields';
import type { EnumOption, HostCheckType, ServerCheckRow } from './types';

const props = defineProps<{
    serverId: number;
    /** The check being edited; null adds a new one. */
    check: ServerCheckRow | null;
    checkTypes: EnumOption<HostCheckType>[];
    dbEngines: EnumOption<DbPingEngine>[];
}>();

const open = defineModel<boolean>('open', { required: true });

const form = useForm<Required<StoreServerCheckRequest>>({
    type: 'mount_present',
    is_enabled: true,
    params: {},
});

const fields = computed(() => CHECK_FIELDS[form.type]);

const errors = computed(() => form.errors as Partial<Record<string, string>>);

// Re-seeded on every open, so a second check never inherits the first one's values or errors.
watch(
    open,
    (isOpen) => {
        if (!isOpen) {
            return;
        }

        const type = props.check?.type ?? props.checkTypes[0]?.value;

        form.clearErrors();
        form.type = type ?? 'mount_present';
        form.is_enabled = props.check?.is_enabled ?? true;
        form.params = checkParams(form.type, props.check?.params);
    },
    { immediate: true },
);

/** A new check's fields follow its type; an existing check's type is locked. */
function changeType(value: AcceptableValue): void {
    if (props.check !== null || typeof value !== 'string') {
        return;
    }

    form.type = value as HostCheckType;
    form.params = checkParams(form.type);
    form.clearErrors();
}

function changeEngine(value: AcceptableValue): void {
    form.params.engine = typeof value === 'string' ? value : null;
}

function numberInputMode(field: CheckField): 'numeric' | 'decimal' | undefined {
    if (field.kind !== 'number') {
        return undefined;
    }

    return field.integer ? 'numeric' : 'decimal';
}

function numberStep(field: CheckField): '1' | 'any' | undefined {
    if (field.kind !== 'number') {
        return undefined;
    }

    return field.integer ? '1' : 'any';
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };

    if (props.check === null) {
        form.post(ServerCheckController.store.url(props.serverId), options);

        return;
    }

    form.put(
        ServerCheckController.update.url({
            server: props.serverId,
            serverCheck: props.check.id,
        }),
        options,
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent data-check-dialog>
            <DialogHeader>
                <DialogTitle>{{
                    check ? 'Edit check' : 'Add check'
                }}</DialogTitle>
                <DialogDescription>
                    The agent picks up the change after its next report.
                </DialogDescription>
            </DialogHeader>
            <form class="min-w-0 space-y-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="check-type">Type</Label>
                    <Select
                        :model-value="form.type"
                        :disabled="check !== null"
                        @update:model-value="changeType"
                    >
                        <SelectTrigger
                            id="check-type"
                            class="h-9 w-full text-sm"
                            data-check-type
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="option in checkTypes"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="errors.type" />
                </div>

                <div
                    v-for="field in fields"
                    :key="`${form.type}-${field.key}`"
                    class="grid gap-2"
                    :data-check-field="field.key"
                >
                    <Label :for="`check-param-${field.key}`">
                        {{ field.label }}
                        <span
                            v-if="!field.required"
                            class="font-normal text-muted-foreground"
                        >
                            (optional)
                        </span>
                    </Label>
                    <Select
                        v-if="field.kind === 'engine'"
                        :model-value="form.params[field.key] ?? undefined"
                        @update:model-value="changeEngine"
                    >
                        <SelectTrigger
                            :id="`check-param-${field.key}`"
                            class="h-9 w-full text-sm"
                        >
                            <SelectValue placeholder="Choose an engine" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="engine in dbEngines"
                                :key="engine.value"
                                :value="engine.value"
                            >
                                {{ engine.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <Input
                        v-else
                        :id="`check-param-${field.key}`"
                        :model-value="form.params[field.key] ?? ''"
                        :type="field.kind === 'number' ? 'number' : 'text'"
                        :inputmode="numberInputMode(field)"
                        :step="numberStep(field)"
                        :placeholder="field.placeholder"
                        :required="field.required"
                        autocomplete="off"
                        spellcheck="false"
                        @update:model-value="
                            (value) => (form.params[field.key] = value)
                        "
                    />
                    <p
                        v-if="field.hint"
                        class="text-[11px] text-muted-foreground"
                    >
                        {{ field.hint }}
                    </p>
                    <InputError :message="errors[`params.${field.key}`]" />
                </div>

                <Label class="flex items-center gap-2 font-normal">
                    <Checkbox
                        :model-value="form.is_enabled"
                        data-check-enabled
                        @update:model-value="
                            (value) => (form.is_enabled = value === true)
                        "
                    />
                    Run this check
                </Label>

                <DialogFooter>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        data-check-save
                    >
                        {{ check ? 'Save check' : 'Add check' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
