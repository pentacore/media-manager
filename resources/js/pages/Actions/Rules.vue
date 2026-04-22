<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Shield } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import ActionTypeConfigController from '@/actions/App/Http/Controllers/Actions/ActionTypeConfigController';
import type { ActionTypeConfigResource } from '@/typefinder/resources/ActionTypeConfigResource';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { dashboard } from '@/routes';

type RuleRow = ActionTypeConfigResource;

const props = defineProps<{ rules: RuleRow[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
            {
                title: 'Action Rules',
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

function onApprovalChange(rule: RuleRow, value: boolean | 'indeterminate') {
    rule.requires_approval = value === true;
    persist(rule);
}

function onEnabledChange(rule: RuleRow, value: boolean | 'indeterminate') {
    rule.is_enabled = value === true;
    persist(rule);
}
</script>

<template>
    <Head title="Action Rules" />

    <div class="space-y-6 p-6">
        <div>
            <h2
                class="flex items-center gap-2 text-2xl font-bold tracking-tight"
            >
                <Shield class="size-6" />
                Action Rules
            </h2>
            <p class="text-muted-foreground">
                Configure which automated actions require approval and which are
                enabled.
            </p>
        </div>

        <div
            v-if="localRules.length === 0"
            class="rounded-md border p-8 text-center text-muted-foreground"
        >
            No action rules configured.
        </div>

        <div v-else class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <Card v-for="rule in localRules" :key="rule.id">
                <CardHeader>
                    <CardTitle>{{ rule.label }}</CardTitle>
                    <p class="font-mono text-xs text-muted-foreground">
                        {{ rule.type }}
                    </p>
                </CardHeader>
                <CardContent class="space-y-4">
                    <p
                        v-if="rule.description"
                        class="text-sm text-muted-foreground"
                    >
                        {{ rule.description }}
                    </p>

                    <div class="flex items-center justify-between">
                        <label
                            :for="`approval-${rule.id}`"
                            class="text-sm font-medium"
                        >
                            Requires approval
                        </label>
                        <Checkbox
                            :id="`approval-${rule.id}`"
                            :model-value="rule.requires_approval"
                            @update:model-value="
                                (v) => onApprovalChange(rule, v)
                            "
                        />
                    </div>

                    <div class="flex items-center justify-between">
                        <label
                            :for="`enabled-${rule.id}`"
                            class="text-sm font-medium"
                        >
                            Enabled
                        </label>
                        <Checkbox
                            :id="`enabled-${rule.id}`"
                            :model-value="rule.is_enabled"
                            @update:model-value="
                                (v) => onEnabledChange(rule, v)
                            "
                        />
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
