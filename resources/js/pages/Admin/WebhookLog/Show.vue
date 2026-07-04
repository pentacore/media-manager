<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed } from 'vue';
import WebhookLogController from '@/actions/App/Http/Controllers/Admin/WebhookLogController';
import { Pill, SvcChip } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

interface WebhookEvent {
    id: number;
    service_name: string | null;
    service_type: string | null;
    event_type: string | null;
    created_at: string | null;
    processed_at: string | null;
    payload: Record<string, unknown> | unknown[];
    payload_hash: string | null;
}

const props = defineProps<{
    event: WebhookEvent;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            { title: 'Webhook log', href: WebhookLogController.index.url() },
        ],
    },
});

const payloadJson = computed(() =>
    JSON.stringify(props.event.payload, null, 2),
);

function formatTime(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString();
}
</script>

<template>
    <Head :title="`Webhook #${event.id}`" />

    <div class="flex flex-col gap-4 p-5">
        <div>
            <Link :href="WebhookLogController.index.url()">
                <Button variant="ghost" size="sm" class="h-8 text-xs">
                    <ArrowLeft class="size-3.5" />
                    Back to webhook log
                </Button>
            </Link>
        </div>

        <div class="rounded-xl border border-border bg-card p-6">
            <div class="flex items-center gap-3">
                <SvcChip v-if="event.service_type" :id="event.service_type" />
                <h1
                    class="font-mono-tabular text-[15px] font-semibold tracking-tight"
                >
                    {{ event.event_type ?? 'unknown' }}
                </h1>
                <Pill
                    :variant="event.processed_at ? 'ok' : 'warn'"
                    :dot="!!event.processed_at"
                >
                    {{ event.processed_at ? 'processed' : 'pending' }}
                </Pill>
            </div>

            <div class="mt-4 grid gap-x-6 gap-y-2 text-[13px] sm:grid-cols-2">
                <div>
                    <div
                        class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                    >
                        Service
                    </div>
                    <div>{{ event.service_name ?? '—' }}</div>
                </div>
                <div>
                    <div
                        class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                    >
                        ID
                    </div>
                    <div class="font-mono-tabular">{{ event.id }}</div>
                </div>
                <div>
                    <div
                        class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                    >
                        Received
                    </div>
                    <div class="font-mono-tabular">
                        {{ formatTime(event.created_at) }}
                    </div>
                </div>
                <div>
                    <div
                        class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                    >
                        Processed
                    </div>
                    <div class="font-mono-tabular">
                        {{ formatTime(event.processed_at) }}
                    </div>
                </div>
                <div class="sm:col-span-2">
                    <div
                        class="text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
                    >
                        Payload hash
                    </div>
                    <div
                        class="font-mono-tabular text-[11.5px] break-all text-muted-foreground"
                    >
                        {{ event.payload_hash ?? '—' }}
                    </div>
                </div>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <div
                class="border-b border-border px-4 py-3 text-[11.5px] font-semibold tracking-[0.05em] text-muted-foreground uppercase"
            >
                Payload
            </div>
            <pre
                class="max-h-[60vh] overflow-auto p-4 font-mono text-[12px] leading-relaxed text-foreground"
                >{{ payloadJson }}</pre>
        </div>
    </div>
</template>
