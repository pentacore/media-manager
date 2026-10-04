<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { BookText, Pin } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ChatTemplateController from '@/actions/App/Http/Controllers/AI/ChatTemplateController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useChatTemplates } from '@/composables/useChatTemplates';
import type { ChatTemplate } from './types';

const emit = defineEmits<{
    (e: 'pick', template: ChatTemplate): void;
}>();

const {
    templates,
    loaded,
    loadFailed,
    ensureTemplatesLoaded,
    refreshTemplates,
} = useChatTemplates();

const open = ref(false);
const filter = ref('');

const visible = computed(() => {
    const term = filter.value.trim().toLowerCase();

    return term === ''
        ? templates.value
        : templates.value.filter((t) => t.name.toLowerCase().includes(term));
});

watch(open, (isOpen) => {
    if (isOpen) {
        filter.value = '';
        // The list is shared across pages, so revalidate it on every open:
        // a template saved elsewhere shows up without a full reload.
        void (loaded.value ? refreshTemplates() : ensureTemplatesLoaded());
    }
});

function pick(template: ChatTemplate): void {
    open.value = false;
    emit('pick', template);
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                class="size-7 p-0 text-muted-foreground"
                title="Templates"
                data-template-picker
            >
                <BookText class="size-3.5" />
            </Button>
        </PopoverTrigger>
        <PopoverContent align="start" side="top" class="w-80 p-2">
            <Input
                v-model="filter"
                class="mb-2 h-8 text-sm"
                placeholder="Filter templates…"
                data-template-filter
            />
            <div class="max-h-64 overflow-y-auto">
                <p
                    v-if="!loaded"
                    class="px-2 py-1.5 text-[12.5px] text-fg-subtle"
                >
                    Loading…
                </p>
                <p
                    v-else-if="loadFailed"
                    class="px-2 py-1.5 text-[12.5px] text-destructive"
                >
                    Templates could not be loaded.
                </p>
                <p
                    v-else-if="visible.length === 0"
                    class="px-2 py-1.5 text-[12.5px] text-fg-subtle"
                >
                    {{
                        templates.length === 0
                            ? 'No templates yet.'
                            : 'No matches.'
                    }}
                </p>
                <button
                    v-for="template in visible"
                    :key="template.id"
                    type="button"
                    class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-[13px] hover:bg-bg-hover"
                    :data-template-option="template.id"
                    @click="pick(template)"
                >
                    <Pin
                        v-if="template.pinned"
                        class="size-3 shrink-0 text-muted-foreground"
                    />
                    <span class="truncate">{{ template.name }}</span>
                </button>
            </div>
            <Link
                :href="ChatTemplateController.index.url()"
                class="mt-2 block border-t border-border px-2 pt-2 text-[12.5px] text-muted-foreground hover:text-foreground"
                data-template-manage
                >Manage templates…</Link
            >
        </PopoverContent>
    </Popover>
</template>
