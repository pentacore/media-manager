<script setup lang="ts">
import { computed, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        hint: string;
        size?: 'sm' | 'md' | 'lg' | 'xl' | 'full';
        src?: string | null;
        /** Blur the image until hover, keyboard focus or a first tap (Whisparr). */
        blurred?: boolean;
    }>(),
    {
        size: 'md',
        src: null,
        blurred: false,
    },
);

const widthClass = computed(() => {
    switch (props.size) {
        case 'sm':
            return 'w-9';
        case 'md':
            return 'w-14';
        case 'lg':
            return 'w-[110px]';
        case 'xl':
            return 'w-[120px]';
        case 'full':
            return 'w-full';
        default:
            return 'w-14';
    }
});

const failed = ref(false);
const hovered = ref(false);
const focused = ref(false);
const tapRevealed = ref(false);
let lastPointerType = '';

// A new URL deserves a fresh attempt even if the previous one 404'd, and a
// fresh blur.
watch(
    () => props.src,
    () => {
        failed.value = false;
        tapRevealed.value = false;
    },
);

const showImage = computed(() => Boolean(props.src) && !failed.value);

const concealable = computed(() => props.blurred && showImage.value);

const concealed = computed(
    () =>
        concealable.value &&
        !hovered.value &&
        !focused.value &&
        !tapRevealed.value,
);

function onPointerEnter(event: PointerEvent): void {
    if (event.pointerType !== 'touch') {
        hovered.value = true;
    }
}

function onPointerDown(event: PointerEvent): void {
    lastPointerType = event.pointerType;
}

// Touch has no hover: the first tap on a blurred poster reveals it instead of
// following the surrounding link; the next tap navigates.
function onClickCapture(event: MouseEvent): void {
    if (
        !concealable.value ||
        tapRevealed.value ||
        lastPointerType !== 'touch'
    ) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();
    tapRevealed.value = true;
}

const hue = computed(
    () =>
        [...(props.hint || 'media')].reduce((a, c) => a + c.charCodeAt(0), 0) %
        360,
);

const styleVars = computed(() => ({
    background: `repeating-linear-gradient(135deg, oklch(0.34 0.06 ${hue.value}) 0 6px, oklch(0.30 0.05 ${hue.value}) 6px 12px)`,
    color: `oklch(0.78 0.06 ${hue.value})`,
}));
</script>

<template>
    <div
        :class="[
            'relative flex aspect-[2/3] items-end overflow-hidden rounded-md border border-border p-2 font-mono text-[10px]',
            widthClass,
            concealable
                ? 'outline-none focus-visible:ring-2 focus-visible:ring-ring'
                : '',
        ]"
        :style="styleVars"
        :tabindex="concealable ? 0 : undefined"
        data-poster
        :data-blurred="concealed ? 'true' : 'false'"
        @pointerenter="onPointerEnter"
        @pointerleave="hovered = false"
        @pointerdown="onPointerDown"
        @focusin="focused = true"
        @focusout="focused = false"
        @click.capture="onClickCapture"
    >
        <img
            v-if="showImage"
            :src="src!"
            :alt="hint"
            loading="lazy"
            :class="[
                'absolute inset-0 size-full object-cover',
                concealed ? 'scale-110 blur-xl' : '',
            ]"
            @error="failed = true"
        />
        <template v-else>
            <span class="absolute top-2 right-2 left-2 truncate opacity-60">{{
                hint
            }}</span>
            <span class="font-serif text-[14px] text-foreground italic"
                >poster</span
            >
        </template>
    </div>
</template>
