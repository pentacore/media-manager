<script setup lang="ts">
import { computed } from 'vue';
import { Poster, StatusPill } from '@/components/mm';
import { titleStatusPill } from '@/lib/seerr';
import { tmdbPosterUrl } from '@/lib/tmdb';
import type { DiscoverTitle } from '@/types';

const props = defineProps<{ item: DiscoverTitle }>();

const emit = defineEmits<{ open: [] }>();

const pill = computed(() => titleStatusPill(props.item.status));
</script>

<template>
    <button
        type="button"
        class="flex w-[132px] shrink-0 cursor-pointer flex-col gap-1.5 rounded-md text-left outline-none focus-visible:ring-2 focus-visible:ring-ring"
        :data-title-card="`${item.media_type}-${item.tmdb_id}`"
        @click="emit('open')"
    >
        <Poster
            :hint="item.title.toLowerCase().slice(0, 12)"
            size="full"
            :src="tmdbPosterUrl(item.poster_path)"
        />
        <span class="line-clamp-2 text-[13px] leading-snug font-medium">
            {{ item.title }}
        </span>
        <span class="flex flex-wrap items-center gap-1.5 text-[11.5px] text-muted-foreground">
            <span class="font-mono-tabular">{{ item.year ?? '—' }}</span>
            <StatusPill v-if="pill" :status="pill.status" :label="pill.label" />
        </span>
    </button>
</template>
