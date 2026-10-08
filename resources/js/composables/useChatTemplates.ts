import { computed, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import ChatTemplateOptionsController from '@/actions/App/Http/Controllers/AI/ChatTemplateOptionsController';
import ChatTemplateRenderController from '@/actions/App/Http/Controllers/AI/ChatTemplateRenderController';
import type { ChatTemplate } from '@/components/chat-templates';
import { jsonRequest } from '@/lib/http';

/** How many pinned templates the empty chat shows. */
const MAX_CHIPS = 6;

// Module-level so the page panel, the sheet panel and the picker share one list.
const templates = ref<ChatTemplate[]>([]);
const loaded = ref(false);
const loadFailed = ref(false);
let inflight: Promise<void> | null = null;

async function refreshTemplates(): Promise<void> {
    try {
        const response = await jsonRequest<{ templates: ChatTemplate[] }>(
            'GET',
            ChatTemplateOptionsController.url(),
        );
        templates.value = response.templates;
        loadFailed.value = false;
    } catch {
        loadFailed.value = true;
    } finally {
        loaded.value = true;
    }
}

function ensureTemplatesLoaded(): Promise<void> {
    if (loaded.value) {
        return Promise.resolve();
    }

    inflight ??= refreshTemplates().finally(() => {
        inflight = null;
    });

    return inflight;
}

/**
 * Render a template that has no variables. Goes through the server so the
 * text matches every other path and last use is stamped. Throws an Error
 * with a readable message on failure.
 */
async function renderWithoutValues(template: ChatTemplate): Promise<string> {
    const response = await jsonRequest<{ text: string }>(
        'POST',
        ChatTemplateRenderController.url(template.id),
        { values: {} },
    );

    void refreshTemplates();

    return response.text;
}

export interface UseChatTemplatesReturn {
    templates: Ref<ChatTemplate[]>;
    pinnedTemplates: ComputedRef<ChatTemplate[]>;
    loaded: Ref<boolean>;
    loadFailed: Ref<boolean>;
    ensureTemplatesLoaded: () => Promise<void>;
    refreshTemplates: () => Promise<void>;
    renderWithoutValues: (template: ChatTemplate) => Promise<string>;
}

export function useChatTemplates(): UseChatTemplatesReturn {
    return {
        templates,
        pinnedTemplates: computed(() =>
            templates.value.filter((t) => t.pinned).slice(0, MAX_CHIPS),
        ),
        loaded,
        loadFailed,
        ensureTemplatesLoaded,
        refreshTemplates,
        renderWithoutValues,
    };
}
