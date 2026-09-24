<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { Pencil, Plus, Send, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import NotificationDestinationController from '@/actions/App/Http/Controllers/Admin/NotificationDestinationController';
import InputError from '@/components/InputError.vue';
import { Pill } from '@/components/mm';
import DestinationFormFields from '@/components/notifications/DestinationFormFields.vue';
import type { DestinationOption } from '@/components/notifications/DestinationFormFields.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { dashboard } from '@/routes';

interface DestinationRow {
    id: number;
    channel: string;
    label: string;
    is_enabled: boolean;
    min_severity: string;
    config_hint: string | null;
    editable_config: Record<string, string | null>;
    updated_at: string | null;
}

const props = defineProps<{
    destinations: DestinationRow[];
    channels: DestinationOption[];
    severities: DestinationOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            {
                title: 'Notification destinations',
                href: NotificationDestinationController.index.url(),
            },
        ],
    },
});

const showCreate = ref(false);
const editing = ref<DestinationRow | null>(null);
const testingId = ref<number | null>(null);

function channelLabel(channel: string): string {
    return (
        props.channels.find((option) => option.value === channel)?.label ??
        channel
    );
}

function sendTest(row: DestinationRow): void {
    testingId.value = row.id;
    router.post(
        NotificationDestinationController.test.url(row.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                testingId.value = null;
            },
        },
    );
}

function remove(row: DestinationRow): void {
    if (!confirm(`Remove "${row.label}"? This cannot be undone.`)) {
        return;
    }

    router.visit(NotificationDestinationController.destroy.url(row.id), {
        method: 'delete',
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Notification destinations" />

    <div class="space-y-6 p-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-[20px] font-semibold">
                    Notification destinations
                </h1>
                <p
                    class="mt-1 max-w-[620px] text-[12.5px] text-muted-foreground"
                >
                    Global mirrors for admin notifications. Every notification
                    that reaches admins is also pushed once to each enabled
                    destination whose minimum severity it meets.
                </p>
            </div>
            <Dialog v-model:open="showCreate">
                <DialogTrigger as-child>
                    <Button size="sm" class="gap-1.5" data-destination-add>
                        <Plus class="size-3.5" />Add destination
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add destination</DialogTitle>
                    </DialogHeader>
                    <Form
                        v-bind="NotificationDestinationController.store.form()"
                        class="space-y-4"
                        v-slot="{ errors, processing }"
                        @success="showCreate = false"
                    >
                        <DestinationFormFields
                            id-prefix="create"
                            :errors="errors"
                            :channels="channels"
                            :severities="severities"
                        />
                        <DialogFooter>
                            <Button type="submit" :disabled="processing"
                                >Save</Button
                            >
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>

        <InputError :message="$page.props.errors.test" />

        <div class="overflow-hidden rounded-xl border border-border bg-card">
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            <th
                                v-for="h in [
                                    'Label',
                                    'Channel',
                                    'Target',
                                    'Min severity',
                                    'Status',
                                    '',
                                ]"
                                :key="h"
                                class="border-b border-border bg-card px-3 py-2 text-left text-[11.5px] font-medium tracking-[0.05em] text-muted-foreground uppercase"
                            >
                                {{ h }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="destinations.length === 0">
                            <td
                                colspan="6"
                                class="px-3 py-6 text-center text-muted-foreground"
                                data-destination-empty
                            >
                                No destinations yet.
                            </td>
                        </tr>
                        <tr
                            v-for="row in destinations"
                            :key="row.id"
                            class="border-b border-border last:border-b-0 hover:bg-bg-hover"
                            :data-destination-row="row.id"
                        >
                            <td class="px-3 py-2.5 font-medium">
                                {{ row.label }}
                            </td>
                            <td class="px-3 py-2.5">
                                <Pill>{{ channelLabel(row.channel) }}</Pill>
                            </td>
                            <td
                                class="font-mono-tabular px-3 py-2.5 text-muted-foreground"
                            >
                                {{ row.config_hint ?? '—' }}
                            </td>
                            <td class="px-3 py-2.5 capitalize">
                                {{ row.min_severity }}
                            </td>
                            <td class="px-3 py-2.5">
                                <Pill
                                    :variant="row.is_enabled ? 'ok' : 'default'"
                                    data-destination-status
                                    >{{
                                        row.is_enabled ? 'Enabled' : 'Disabled'
                                    }}</Pill
                                >
                            </td>
                            <td class="px-3 py-2.5">
                                <div class="flex justify-end gap-1">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="h-7 gap-1 text-xs"
                                        :disabled="testingId !== null"
                                        @click="sendTest(row)"
                                    >
                                        <Send class="size-3.5" />{{
                                            testingId === row.id
                                                ? 'Sending…'
                                                : 'Test'
                                        }}
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="h-7 gap-1 text-xs"
                                        @click="editing = row"
                                    >
                                        <Pencil class="size-3.5" />Edit
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="h-7 gap-1 text-xs text-destructive"
                                        @click="remove(row)"
                                    >
                                        <Trash2 class="size-3.5" />Remove
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Dialog
            :open="editing !== null"
            @update:open="
                (open: boolean) => {
                    if (!open) {
                        editing = null;
                    }
                }
            "
        >
            <DialogContent v-if="editing">
                <DialogHeader>
                    <DialogTitle>Edit destination</DialogTitle>
                </DialogHeader>
                <Form
                    :key="editing.id"
                    v-bind="
                        NotificationDestinationController.update.form(
                            editing.id,
                        )
                    "
                    class="space-y-4"
                    v-slot="{ errors, processing }"
                    @success="editing = null"
                >
                    <DestinationFormFields
                        id-prefix="edit"
                        :errors="errors"
                        :channels="channels"
                        :severities="severities"
                        :initial-channel="editing.channel"
                        :initial-label="editing.label"
                        :initial-enabled="editing.is_enabled"
                        :initial-severity="editing.min_severity"
                        :initial-config="editing.editable_config"
                        editing
                    />
                    <DialogFooter>
                        <Button type="submit" :disabled="processing"
                            >Save</Button
                        >
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>
    </div>
</template>
