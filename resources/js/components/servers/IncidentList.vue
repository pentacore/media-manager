<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue';
import { Pill, TimeStamp } from '@/components/mm';
import { cn } from '@/lib/utils';
import { incidentPillVariant } from './incident';
import type { ServerIncidentRow } from './types';

defineProps<{
    incidents: ServerIncidentRow[];
}>();

/**
 * The incident a notification link (`#incident-{id}`) points at. The browser's
 * own fragment scroll and `:target` miss a row the client rendered after the
 * load, so the list highlights and scrolls to it itself.
 */
const linkedIncidentId = ref<number | null>(null);

onMounted(async () => {
    const match = /^#incident-(\d+)$/.exec(window.location.hash);

    if (!match) {
        return;
    }

    linkedIncidentId.value = Number(match[1]);
    await nextTick();
    document
        .getElementById(`incident-${linkedIncidentId.value}`)
        ?.scrollIntoView({ block: 'start' });
});
</script>

<template>
    <section
        class="rounded-xl border border-border bg-card p-5"
        data-incident-list
    >
        <h2 class="mb-3 text-sm font-semibold">Incidents</h2>

        <p
            v-if="incidents.length === 0"
            class="text-[13px] text-muted-foreground"
            data-incidents-empty
        >
            No incidents on this server.
        </p>
        <ul v-else class="divide-y divide-border">
            <li
                v-for="incident in incidents"
                :id="`incident-${incident.id}`"
                :key="incident.id"
                :class="
                    cn(
                        '-mx-2 scroll-mt-20 rounded-md px-2 py-3 text-[13px]',
                        incident.status === 'resolved' &&
                            'text-muted-foreground',
                        incident.id === linkedIncidentId &&
                            'bg-bg-hover ring-2 ring-ring',
                    )
                "
                :data-incident-row="incident.id"
                :data-incident-status="incident.status"
                :data-incident-linked="
                    incident.id === linkedIncidentId ? '' : undefined
                "
            >
                <div class="flex flex-wrap items-center gap-2">
                    <Pill
                        :variant="
                            incidentPillVariant(
                                incident.status,
                                incident.severity,
                            )
                        "
                        dot
                        :data-incident-severity="incident.severity"
                    >
                        {{
                            incident.severity === 'critical'
                                ? 'Critical'
                                : 'Warning'
                        }}
                    </Pill>
                    <Pill
                        v-if="incident.status === 'resolved'"
                        data-incident-resolved
                    >
                        Resolved
                    </Pill>
                    <span
                        :class="
                            cn(
                                'min-w-0 font-medium break-words',
                                incident.status === 'open' && 'text-foreground',
                            )
                        "
                        data-incident-title
                    >
                        {{ incident.title }}
                    </span>
                </div>
                <p
                    v-if="incident.detail"
                    class="mt-1 break-words text-muted-foreground"
                    data-incident-detail
                >
                    {{ incident.detail }}
                </p>
                <p
                    class="mt-1 flex flex-wrap gap-x-3 text-xs text-muted-foreground"
                >
                    <span
                        >Opened
                        <TimeStamp :iso="incident.opened_at" mode="datetime"
                    /></span>
                    <span v-if="incident.status === 'open'"
                        >Last seen <TimeStamp :iso="incident.last_seen_at" live
                    /></span>
                    <span v-else-if="incident.resolved_at"
                        >Resolved
                        <TimeStamp :iso="incident.resolved_at" mode="datetime"
                    /></span>
                </p>
            </li>
        </ul>
    </section>
</template>
