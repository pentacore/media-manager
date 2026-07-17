<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { Toggle } from '@/components/mm';
import ActionTypeConfigController from '@/actions/App/Http/Controllers/Actions/ActionTypeConfigController';
import { dashboard } from '@/routes';
import type { ActionTypeConfigResource } from '@/typefinder/resources/ActionTypeConfigResource';

type RuleRow = ActionTypeConfigResource;

const props = defineProps<{ rules: RuleRow[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Automation', href: dashboard().url },
            {
                title: 'Approval Rules',
                href: ActionTypeConfigController.index.url(),
            },
        ],
    },
});

const localRules = ref<RuleRow[]>(props.rules.map((r) => ({ ...r })));

watch(
    () => props.rules,
    (next) => {
        localRules.value = next.map((r) => ({ ...r }));
    },
    { deep: true },
);

function persist(rule: RuleRow) {
    router.patch(
        ActionTypeConfigController.update.url(rule.id),
        {
            requires_approval: rule.requires_approval,
            is_enabled: rule.is_enabled,
        },
        { preserveScroll: true, preserveState: true },
    );
}

function setEnabled(rule: RuleRow, value: boolean) {
    rule.is_enabled = value;
    persist(rule);
}

function setApproval(rule: RuleRow, value: boolean) {
    rule.requires_approval = value;
    persist(rule);
}
</script>

<template>
    <Head title="Approval rules" />

    <div class="flex flex-col gap-4 p-5">
        <!-- Hero -->
        <div>
            <div class="mb-1.5 text-[13px] text-muted-foreground">
                Actions <span class="text-fg-subtle">/</span> Approval rules
            </div>
            <h1 class="text-[22px] leading-tight font-semibold tracking-tight">
                Approval rules
            </h1>
            <p class="mt-1 max-w-[640px] text-[13px] text-muted-foreground">
                Per-action toggles. Disabled rules are silently dropped at the
                orchestrator. Approval-gated actions wait for an admin/member to
                confirm.
            </p>
        </div>

        <div
            v-if="localRules.length === 0"
            class="rounded-xl border border-border bg-card p-8 text-center text-sm text-fg-subtle"
        >
            No action rules configured.
        </div>

        <div
            v-else
            class="overflow-hidden rounded-xl border border-border bg-card"
        >
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            <th
                                v-for="h in [
                                    'Action type',
                                    'Description',
                                    'Enabled',
                                    'Requires approval',
                                ]"
                                :key="h"
                                class="border-b border-border bg-card px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                {{ h }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="rule in localRules"
                            :key="rule.id"
                            class="border-b border-border last:border-b-0 hover:bg-bg-hover"
                        >
                            <td class="px-3 py-3">
                                <div
                                    class="font-mono-tabular text-[12.5px] font-medium"
                                >
                                    {{ rule.type }}
                                </div>
                                <div
                                    class="mt-0.5 text-[11.5px] text-fg-subtle"
                                >
                                    {{ rule.label }}
                                </div>
                            </td>
                            <td class="px-3 py-3 text-muted-foreground">
                                {{ rule.description ?? '—' }}
                            </td>
                            <td class="px-3 py-3">
                                <Toggle
                                    :model-value="rule.is_enabled"
                                    :label="rule.is_enabled ? 'on' : 'off'"
                                    @update:model-value="
                                        (v) => setEnabled(rule, v)
                                    "
                                />
                            </td>
                            <td class="px-3 py-3">
                                <Toggle
                                    :model-value="rule.requires_approval"
                                    :label="
                                        rule.requires_approval
                                            ? 'required'
                                            : 'auto'
                                    "
                                    @update:model-value="
                                        (v) => setApproval(rule, v)
                                    "
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
