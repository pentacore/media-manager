<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    data: number[][];
    rowLabels: string[];
    colLabels: string[];
}>();

const max = computed(() =>
    props.data.reduce(
        (acc, row) =>
            row.reduce((rowAcc, value) => Math.max(rowAcc, value), acc),
        0,
    ),
);

const colCount = computed(() => props.colLabels.length);

function opacityFor(value: number): number {
    if (max.value <= 0 || value <= 0) {
        return 0;
    }

    return 0.12 + (value / max.value) * 0.88;
}

/**
 * Show the column label only at sparse positions (0, 6, 12, 18) so the
 * header stays legible for a 24-hour axis.
 */
function showColLabel(index: number): boolean {
    return index % 6 === 0;
}
</script>

<template>
    <div class="w-full overflow-x-auto">
        <div class="inline-block min-w-full">
            <div
                class="grid gap-1"
                :style="{
                    gridTemplateColumns: `auto repeat(${colCount}, minmax(10px, 1fr))`,
                }"
            >
                <div />
                <div
                    v-for="(col, colIndex) in colLabels"
                    :key="`col-${colIndex}`"
                    class="text-center text-[10px] text-muted-foreground"
                >
                    <span v-if="showColLabel(colIndex)">{{ col }}</span>
                </div>

                <template
                    v-for="(row, rowIndex) in data"
                    :key="`row-${rowIndex}`"
                >
                    <div class="pr-2 text-right text-xs text-muted-foreground">
                        {{ rowLabels[rowIndex] }}
                    </div>
                    <div
                        v-for="(value, colIndex) in row"
                        :key="`cell-${rowIndex}-${colIndex}`"
                        class="aspect-square min-h-[10px] rounded"
                        :style="{ opacity: opacityFor(value) || undefined }"
                        :class="value > 0 ? 'bg-accent' : 'bg-muted'"
                        :title="`${rowLabels[rowIndex]} ${colLabels[colIndex]}: ${value}`"
                    />
                </template>
            </div>
        </div>
    </div>
</template>
