<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import MediaReplacementAttemptController from '@/actions/App/Http/Controllers/Admin/MediaReplacementAttemptController';
import MediaReplacementSettingsController from '@/actions/App/Http/Controllers/Admin/MediaReplacementSettingsController';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { cn } from '@/lib/utils';

withDefaults(defineProps<{ attentionCount?: number }>(), {
    attentionCount: 0,
});

const { isCurrentOrParentUrl } = useCurrentUrl();

const attemptsUrl = MediaReplacementAttemptController.index.url();
const settingsUrl = MediaReplacementSettingsController.index.url();

// The settings URL is a prefix of the attempts URL, so "settings is active"
// must be derived as "attempts is not", never by prefix match on its own.
const attemptsActive = computed(() => isCurrentOrParentUrl(attemptsUrl));

const tabClass = (active: boolean): string =>
    cn(
        'inline-flex items-center gap-2 border-b-2 px-3 py-2 text-[13px] font-medium transition-colors',
        active
            ? 'border-accent text-foreground'
            : 'border-transparent text-muted-foreground hover:text-foreground',
    );
</script>

<template>
    <nav
        role="tablist"
        aria-label="Media replacement sections"
        class="flex gap-1 border-b border-border"
    >
        <Link
            :href="settingsUrl"
            role="tab"
            data-tab="settings"
            :aria-selected="!attemptsActive"
            :class="tabClass(!attemptsActive)"
        >
            Settings
        </Link>
        <Link
            :href="attemptsUrl"
            role="tab"
            data-tab="attempts"
            :aria-selected="attemptsActive"
            :class="tabClass(attemptsActive)"
        >
            Attempts
            <span
                v-if="attentionCount > 0"
                data-attempts-tab-badge
                class="font-mono-tabular rounded-full bg-warning/15 px-1.5 text-[10.5px] text-warning"
            >
                {{ attentionCount }}
            </span>
        </Link>
    </nav>
</template>
