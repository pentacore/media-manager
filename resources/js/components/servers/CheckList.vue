<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Pause, Pencil, Play, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import ServerCheckController from '@/actions/App/Http/Controllers/Admin/ServerCheckController';
import { Pill, TimeStamp } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { useConfirm } from '@/composables/useConfirm';
import type { DbPingEngine } from '@/typefinder';
import CheckFormDialog from './CheckFormDialog.vue';
import type {
    EnumOption,
    HostCheckStatus,
    HostCheckType,
    ServerCheckRow,
} from './types';

const props = defineProps<{
    serverId: number;
    checks: ServerCheckRow[];
    checkTypes: EnumOption<HostCheckType>[];
    dbEngines: EnumOption<DbPingEngine>[];
}>();

const dialogOpen = ref(false);

/** The check the dialog edits; null while it adds one. */
const editing = ref<ServerCheckRow | null>(null);

function add(): void {
    editing.value = null;
    dialogOpen.value = true;
}

function edit(check: ServerCheckRow): void {
    editing.value = check;
    dialogOpen.value = true;
}

function routeArgs(check: ServerCheckRow): {
    server: number;
    serverCheck: number;
} {
    return { server: props.serverId, serverCheck: check.id };
}

function toggle(check: ServerCheckRow): void {
    router.patch(
        ServerCheckController.toggle.url(routeArgs(check)),
        { is_enabled: !check.is_enabled },
        { preserveScroll: true },
    );
}

const { confirm } = useConfirm();

async function remove(check: ServerCheckRow): Promise<void> {
    const confirmed = await confirm({
        title: `Remove "${check.label}"?`,
        description:
            'The agent stops running it after its next report, and its open incident resolves.',
        confirmLabel: 'Remove',
        destructive: true,
    });

    if (!confirmed) {
        return;
    }

    router.delete(ServerCheckController.destroy.url(routeArgs(check)), {
        preserveScroll: true,
    });
}

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
        <div class="mb-3 flex items-center justify-between gap-2">
            <h2 class="text-sm font-semibold">Checks</h2>
            <Button
                type="button"
                size="sm"
                variant="outline"
                class="h-8 gap-1.5 text-xs"
                data-check-add
                @click="add"
            >
                <Plus class="size-3.5" />Add check
            </Button>
        </div>

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
                <div class="flex items-start gap-2">
                    <div
                        class="flex min-w-0 flex-1 flex-wrap items-center gap-2"
                    >
                        <Pill
                            v-if="check.last_status"
                            :variant="STATUS_VARIANTS[check.last_status]"
                            dot
                            :data-check-status="check.last_status"
                        >
                            {{ check.last_status_label }}
                        </Pill>
                        <Pill v-else data-check-status="none">Not run yet</Pill>
                        <span
                            class="min-w-0 font-medium break-all"
                            data-check-row-label
                        >
                            {{ check.label }}
                        </span>
                        <Pill v-if="!check.is_enabled" data-check-disabled>
                            Paused
                        </Pill>
                    </div>
                    <div class="flex shrink-0 items-center gap-1 touch:gap-2">
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            class="size-7"
                            :aria-label="`Edit ${check.label}`"
                            data-check-edit
                            @click="edit(check)"
                        >
                            <Pencil class="size-3.5" />
                        </Button>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            class="size-7"
                            :aria-label="
                                check.is_enabled
                                    ? `Pause ${check.label}`
                                    : `Resume ${check.label}`
                            "
                            data-check-toggle
                            @click="toggle(check)"
                        >
                            <Pause v-if="check.is_enabled" class="size-3.5" />
                            <Play v-else class="size-3.5" />
                        </Button>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            class="size-7 text-destructive hover:text-destructive"
                            :aria-label="`Remove ${check.label}`"
                            data-check-delete
                            @click="remove(check)"
                        >
                            <Trash2 class="size-3.5" />
                        </Button>
                    </div>
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

        <CheckFormDialog
            v-model:open="dialogOpen"
            :server-id="serverId"
            :check="editing"
            :check-types="checkTypes"
            :db-engines="dbEngines"
        />
    </section>
</template>
