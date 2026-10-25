<script setup lang="ts">
import InputError from '@/components/InputError.vue';

defineProps<{
    errors: Record<string, string | undefined>;
}>();

const expectedAgrees = defineModel<boolean | null>('expectedAgrees', {
    required: true,
});
</script>

<template>
    <fieldset class="grid gap-1.5" data-verdict-scoring>
        <legend class="mb-1.5 text-[12.5px] font-medium">
            Expected verdict
        </legend>
        <div class="flex flex-wrap gap-x-4 gap-y-1.5">
            <label class="flex items-center gap-1.5 text-[13px]">
                <input
                    type="radio"
                    name="verdict-expected"
                    :checked="expectedAgrees === true"
                    data-verdict-expected="agree"
                    @change="expectedAgrees = true"
                />
                Should agree (let it run)
            </label>
            <label class="flex items-center gap-1.5 text-[13px]">
                <input
                    type="radio"
                    name="verdict-expected"
                    :checked="expectedAgrees === false"
                    data-verdict-expected="disagree"
                    @change="expectedAgrees = false"
                />
                Should disagree (stop it)
            </label>
        </div>
        <InputError :message="errors['scoring.expected_agrees']" />
    </fieldset>
</template>
