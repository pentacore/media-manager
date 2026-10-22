<script setup lang="ts">
import { Pill, TimeStamp } from '@/components/mm';
import type { HostCheckStatus, ServerCheckRow } from './types';

defineProps<{
    checks: ServerCheckRow[];
}>();

const STATUS_VARIANTS: Record<
    HostCheckStatus,
    'ok' | 'warn' | 'danger' | 'default'
> = {
    ok: 'ok',
    warn: 'warn',
    crit: 'danger',
    error: 'danger',
    unsupported: 'default',
};

/** "mounted: false · fstype: nfs4" — the agent's evidence for the result. */
function observedText(observed: Record<string, unknown> | null): string {
    if (!observed) {
        return '';
    }

    return Object.entries(observed)
        .map(
            ([key, value]) =>
                `${key}: ${typeof value === 'object' ? JSON.stringify(value) : String(value)}`,
        )
        .join(' · ');
}
</script>

<template>
    <section
        class="rounded-xl border border-border bg-card p-5"
        data-check-list
    >
        <h2 class="mb-3 text-sm font-semibold">Checks</h2>

        <p
            v-if="checks.length === 0"
            class="text-[13px] text-muted-foreground"
            data-checks-empty
        >
            No checks on this server yet.
        </p>
        <ul v-else class="divide-y divide-border">
            <li
                v-for="check in checks"
                :key="check.id"
                class="py-3 text-[13px]"
                :data-check-row="check.id"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <Pill
                        v-if="check.last_status"
                        :variant="STATUS_VARIANTS[check.last_status]"
                        dot
                        :data-check-status="check.last_status"
                    >
                        {{ check.last_status_label }}
                    </Pill>
                    <Pill v-else data-check-status="none">Not run yet</Pill>
                    <span class="min-w-0 font-medium break-all">
                        {{ check.label }}
                    </span>
                    <Pill v-if="!check.is_enabled" data-check-disabled>
                        Disabled
                    </Pill>
                </div>
                <p
                    v-if="check.last_message"
                    class="mt-1 break-words text-muted-foreground"
                    data-check-message
                >
                    {{ check.last_message }}
                </p>
                <p
                    v-if="observedText(check.last_observed)"
                    class="font-mono-tabular mt-1 text-xs break-all text-muted-foreground"
                    data-check-observed
                >
                    {{ observedText(check.last_observed) }}
                </p>
                <p
                    v-if="check.last_evaluated_at"
                    class="mt-1 text-xs text-muted-foreground"
                >
                    Checked <TimeStamp :iso="check.last_evaluated_at" live />
                </p>
            </li>
        </ul>
    </section>
</template>
