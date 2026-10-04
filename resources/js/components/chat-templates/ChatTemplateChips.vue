<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { onMounted } from 'vue';
import ChatTemplateController from '@/actions/App/Http/Controllers/AI/ChatTemplateController';
import { useChatTemplates } from '@/composables/useChatTemplates';
import type { ChatTemplate } from './types';

const emit = defineEmits<{
    (e: 'pick', template: ChatTemplate): void;
}>();

const {
    pinnedTemplates,
    loaded,
    loadFailed,
    ensureTemplatesLoaded,
    refreshTemplates,
} = useChatTemplates();

onMounted(() => {
    // The list is shared across pages, so revalidate it when the empty
    // state reappears: a template pinned elsewhere shows up right away.
    void (loaded.value ? refreshTemplates() : ensureTemplatesLoaded());
});
</script>

<template>
    <div class="flex max-w-[520px] flex-wrap justify-center gap-2">
        <button
            v-for="template in pinnedTemplates"
            :key="template.id"
            type="button"
            class="rounded-full border border-border bg-card px-3 py-1.5 text-[12.5px] text-foreground hover:bg-bg-hover"
            :data-template-chip="template.id"
            @click="emit('pick', template)"
        >
            {{ template.name }}
        </button>
        <Link
            v-if="loaded && !loadFailed && pinnedTemplates.length === 0"
            :href="ChatTemplateController.index.url()"
            class="text-[12.5px] text-muted-foreground hover:text-foreground"
            data-template-hint
            >Save prompts you reuse as templates →</Link
        >
    </div>
</template>
