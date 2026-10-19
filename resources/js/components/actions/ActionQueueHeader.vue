<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { RefreshCcw, Settings as SettingsIcon, Sparkles } from '@lucide/vue';
import ActionTypeConfigController from '@/actions/App/Http/Controllers/Actions/ActionTypeConfigController';
import { Button } from '@/components/ui/button';
import { useCan } from '@/composables/useCan';

defineProps<{
    /** Requests created live that the paused list has not shown yet. */
    staleCount: number;
    refreshing: boolean;
}>();

const emit = defineEmits<{
    refresh: [];
}>();

const { isAdmin } = useCan();
</script>

<template>
    <div class="flex items-end justify-between gap-3">
        <div>
            <h1 class="text-[22px] font-semibold tracking-tight">
                Action queue
            </h1>
            <p class="mt-1 text-[13px] text-muted-foreground">
                <span class="font-mono-tabular">ActionRequest</span>
                state machine — pending → approved → executing →
                completed/failed
            </p>
        </div>
        <div class="flex items-center gap-2">
            <div
                v-if="staleCount > 0"
                class="flex items-center gap-2 rounded-md border border-accent/40 bg-accent/10 px-3 py-1 text-sm text-accent"
                data-action-stale
            >
                <Sparkles class="size-4" />
                <span>{{ staleCount }} new</span>
                <Button
                    size="sm"
                    variant="ghost"
                    class="h-6 px-2 text-accent hover:bg-accent/20"
                    @click="emit('refresh')"
                >
                    Refresh
                </Button>
            </div>
            <Link
                v-if="isAdmin"
                :href="ActionTypeConfigController.index.url()"
                class="inline-flex h-7 items-center gap-1.5 rounded-md border border-border bg-card px-2 text-xs font-medium text-foreground transition-colors hover:bg-bg-hover"
            >
                <SettingsIcon class="size-3.5" />Approval rules
            </Link>
            <Button
                variant="outline"
                size="sm"
                class="h-7 gap-1.5 px-2 text-xs"
                data-action-refresh
                :disabled="refreshing"
                @click="emit('refresh')"
            >
                <RefreshCcw
                    class="size-3.5"
                    :class="{ 'animate-spin': refreshing }"
                />Refresh
            </Button>
        </div>
    </div>
</template>
