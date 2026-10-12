<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';

defineProps<{
    /** The built-in rubric for the task, shown as help text. */
    defaultRubric?: string;
    errors: Record<string, string | undefined>;
}>();

const rubricNote = defineModel<string | null>('rubricNote', {
    required: true,
});
const passThreshold = defineModel<number | string>('passThreshold', {
    required: true,
});
</script>

<template>
    <div class="grid gap-3" data-benchmark-judge>
        <p
            v-if="defaultRubric"
            class="rounded-lg border border-border bg-bg-elev p-3 text-[12.5px] whitespace-pre-line text-muted-foreground"
            data-benchmark-default-rubric
        >
            <span class="mb-1 block font-medium text-foreground"
                >Default rubric</span
            >{{ defaultRubric }}
        </p>
        <label class="grid gap-1.5 text-[12.5px] font-medium">
            Extra rubric note
            <textarea
                v-model="rubricNote"
                rows="3"
                class="rounded-md border border-input bg-background px-3 py-2 text-[13px] text-foreground"
                data-benchmark-rubric-note
            />
            <InputError :message="errors['scoring.rubric_note']" />
        </label>
        <label class="grid gap-1.5 text-[12.5px] font-medium sm:max-w-[160px]">
            Pass threshold (1-10)
            <Input
                v-model="passThreshold"
                type="number"
                min="1"
                max="10"
                class="h-9"
                data-benchmark-pass-threshold
            />
            <InputError :message="errors['scoring.pass_threshold']" />
        </label>
    </div>
</template>
