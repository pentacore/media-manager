<script setup lang="ts">
import { computed } from 'vue';
import type { CalendarItem as CalendarItemType } from '@/types';
import CalendarItem from './CalendarItem.vue';

const props = defineProps<{
    weeks: string[][];
    month: string;
    itemsByDay: Record<string, CalendarItemType[]>;
    today: string;
    weekdayLabels: string[];
}>();

const MAX_PER_DAY = 3;

const cells = computed(() => props.weeks.flat());
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-border bg-card">
        <div
            class="grid grid-cols-7 border-b border-border text-[11px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
        >
            <div
                v-for="label in weekdayLabels"
                :key="label"
                class="px-2 py-1.5"
            >
                {{ label }}
            </div>
        </div>
        <div class="grid grid-cols-7">
            <div
                v-for="day in cells"
                :key="day"
                :class="[
                    'min-h-24 border-r border-b border-border p-1',
                    !day.startsWith(month) &&
                        'bg-bg-hover/30 text-muted-foreground',
                ]"
                :data-calendar-day="day"
            >
                <div
                    :class="[
                        'font-mono-tabular mb-0.5 text-right text-[11px]',
                        day === today && 'font-semibold text-info',
                    ]"
                >
                    {{ Number(day.slice(8)) }}
                </div>
                <CalendarItem
                    v-for="item in (itemsByDay[day] ?? []).slice(
                        0,
                        MAX_PER_DAY,
                    )"
                    :key="item.key"
                    :item="item"
                    compact
                />
                <div
                    v-if="(itemsByDay[day] ?? []).length > MAX_PER_DAY"
                    class="px-1 text-[11px] text-muted-foreground"
                >
                    +{{ (itemsByDay[day] ?? []).length - MAX_PER_DAY }} more
                </div>
            </div>
        </div>
    </div>
</template>
