<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Pin, PinOff, Plus, Trash2 } from '@lucide/vue';
import AIChatController from '@/actions/App/Http/Controllers/AI/ChatController';
import ChatTemplateController from '@/actions/App/Http/Controllers/AI/ChatTemplateController';
import type { ChatTemplate } from '@/components/chat-templates';
import { Pill, TimeStamp } from '@/components/mm';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

defineProps<{
    templates: ChatTemplate[];
}>();

/** Token examples live in script: a literal "{{" inside a template interpolation breaks Vue's parser. */
const SIMPLE_TOKEN = '{{name}}';
const LIBRARY_TOKEN = '{{name:title,year,id}}';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Assistant', href: dashboard().url },
            { title: 'AI Assistant', href: AIChatController.index.url() },
            { title: 'Templates', href: ChatTemplateController.index.url() },
        ],
    },
});

function togglePin(template: ChatTemplate): void {
    router.patch(
        ChatTemplateController.pin.url(template.id),
        {},
        { preserveScroll: true },
    );
}

function remove(template: ChatTemplate): void {
    if (!confirm(`Delete "${template.name}"? This cannot be undone.`)) {
        return;
    }

    router.delete(ChatTemplateController.destroy.url(template.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Assistant templates" />

    <div class="space-y-6 p-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-[20px] font-semibold">Assistant templates</h1>
                <p
                    class="mt-1 max-w-[620px] text-[12.5px] text-muted-foreground"
                >
                    Prompts you reuse. Pinned templates show on new chats; all
                    of them are in the composer's template menu.
                </p>
            </div>
            <Button size="sm" class="gap-1.5" as-child data-template-new>
                <Link :href="ChatTemplateController.create.url()">
                    <Plus class="size-3.5" />New template
                </Link>
            </Button>
        </div>

        <div
            v-if="templates.length === 0"
            class="rounded-xl border border-border bg-card p-6 text-[13px] text-muted-foreground"
            data-template-empty
        >
            <p>No templates yet.</p>
            <p class="mt-2">
                Mark the parts that change with
                <code>{{ SIMPLE_TOKEN }}</code
                >. Series and movie variables can choose what to include:
                <code>{{ LIBRARY_TOKEN }}</code
                >.
            </p>
        </div>

        <div
            v-else
            class="overflow-hidden rounded-xl border border-border bg-card"
        >
            <div
                v-for="template in templates"
                :key="template.id"
                class="flex items-center gap-3 border-b border-border px-4 py-3 last:border-b-0 hover:bg-bg-hover"
                :data-template-row="template.id"
            >
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span class="text-[13.5px] font-medium">{{
                            template.name
                        }}</span>
                        <Pill v-if="template.auto_send">Sends right away</Pill>
                    </div>
                    <p class="truncate text-[12.5px] text-muted-foreground">
                        {{ template.body }}
                    </p>
                </div>
                <span class="shrink-0 text-[12px] text-fg-subtle">
                    <TimeStamp
                        v-if="template.last_used_at"
                        :iso="template.last_used_at"
                    />
                    <template v-else>Never used</template>
                </span>
                <div class="flex shrink-0 gap-1">
                    <Button
                        variant="ghost"
                        size="sm"
                        class="h-7 gap-1 text-xs"
                        :title="template.pinned ? 'Unpin' : 'Pin to new chats'"
                        data-template-pin
                        @click="togglePin(template)"
                    >
                        <PinOff v-if="template.pinned" class="size-3.5" />
                        <Pin v-else class="size-3.5" />
                        {{ template.pinned ? 'Unpin' : 'Pin' }}
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="h-7 gap-1 text-xs"
                        as-child
                    >
                        <Link
                            :href="ChatTemplateController.edit.url(template.id)"
                        >
                            <Pencil class="size-3.5" />Edit
                        </Link>
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="h-7 gap-1 text-xs text-destructive"
                        data-template-delete
                        @click="remove(template)"
                    >
                        <Trash2 class="size-3.5" />Delete
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
