<script setup lang="ts">
import { useMediaQuery } from '@vueuse/core';
import { computed } from 'vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useDateTime } from '@/composables/useDateTime';
import { cn } from '@/lib/utils';
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

/**
 * Below `sm` the day popover spans the screen with a 16 px gutter. The query
 * only feeds the popover, which SSR never renders (it opens on a tap).
 */
const isPhone = useMediaQuery('(max-width: 639.98px)');

const { formatDate } = useDateTime();

const cells = computed(() => props.weeks.flat());

function itemsOn(day: string): CalendarItemType[] {
    return props.itemsByDay[day] ?? [];
}

function dayHeading(day: string): string {
    return formatDate(`${day}T12:00:00Z`);
}

function dayLabel(day: string): string {
    const count = itemsOn(day).length;

    return `${dayHeading(day)}: ${count} ${count === 1 ? 'item' : 'items'}`;
}
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
                    'flex min-h-24 flex-col border-r border-b border-border p-1 max-sm:min-h-14',
                    !day.startsWith(month) &&
                        'bg-bg-hover/30 text-muted-foreground',
                ]"
                :data-calendar-day="day"
            >
                <Popover v-if="itemsOn(day).length > 0">
                    <PopoverTrigger as-child>
                        <button
                            type="button"
                            class="flex w-full flex-1 cursor-pointer flex-col rounded text-left outline-none hover:bg-bg-hover focus-visible:ring-2 focus-visible:ring-ring/50"
                            :aria-label="dayLabel(day)"
                            :data-calendar-day-open="day"
                        >
                            <span
                                :class="[
                                    'font-mono-tabular mb-0.5 block text-right text-[11px]',
                                    day === today && 'font-semibold text-info',
                                ]"
                            >
                                {{ Number(day.slice(8)) }}
                            </span>
                            <span
                                class="contents max-sm:hidden"
                                data-calendar-day-rows
                            >
                                <CalendarItem
                                    v-for="item in itemsOn(day).slice(
                                        0,
                                        MAX_PER_DAY,
                                    )"
                                    :key="item.key"
                                    :item="item"
                                    compact
                                />
                                <span
                                    v-if="itemsOn(day).length > MAX_PER_DAY"
                                    class="block px-1 text-[11px] text-muted-foreground"
                                    data-calendar-day-more
                                >
                                    +{{ itemsOn(day).length - MAX_PER_DAY }}
                                    more
                                </span>
                            </span>
                            <span
                                class="flex flex-wrap items-center justify-end gap-1 px-0.5 sm:hidden"
                                aria-hidden="true"
                                data-calendar-day-dots
                            >
                                <span
                                    v-for="item in itemsOn(day).slice(
                                        0,
                                        MAX_PER_DAY,
                                    )"
                                    :key="item.key"
                                    :class="
                                        cn(
                                            'size-1.5 rounded-full',
                                            item.service === 'sonarr'
                                                ? 'bg-svc-sonarr'
                                                : 'bg-svc-radarr',
                                        )
                                    "
                                />
                                <span
                                    v-if="itemsOn(day).length > MAX_PER_DAY"
                                    class="text-[10px] leading-none text-muted-foreground"
                                    >+{{
                                        itemsOn(day).length - MAX_PER_DAY
                                    }}</span
                                >
                            </span>
                        </button>
                    </PopoverTrigger>
                    <PopoverContent
                        align="start"
                        :collision-padding="isPhone ? 16 : 0"
                        class="w-96 max-w-[calc(100vw-2rem)] p-0 max-sm:flex max-sm:max-h-(--reka-popover-content-available-height) max-sm:w-[calc(100vw-2rem)] max-sm:flex-col"
                        :data-calendar-day-popover="day"
                    >
                        <h3
                            class="border-b border-border px-3 py-2 text-[12px] font-semibold text-muted-foreground"
                        >
                            {{ dayHeading(day) }}
                        </h3>
                        <div class="max-h-96 overflow-y-auto max-sm:min-h-0">
                            <CalendarItem
                                v-for="item in itemsOn(day)"
                                :key="item.key"
                                :item="item"
                            />
                        </div>
                    </PopoverContent>
                </Popover>
                <span
                    v-else
                    :class="[
                        'font-mono-tabular mb-0.5 block text-right text-[11px]',
                        day === today && 'font-semibold text-info',
                    ]"
                >
                    {{ Number(day.slice(8)) }}
                </span>
            </div>
        </div>
    </div>
</template>
