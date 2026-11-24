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
/** True only while no list has ever loaded: a failed refresh keeps the last list. */
const loadFailed = ref(false);
let inflight: Promise<void> | null = null;
/** Bumped per refresh; only the newest one applies its answer. */
let latestRefresh = 0;
let hasList = false;

async function refreshTemplates(): Promise<void> {
    const refresh = ++latestRefresh;

    try {
        const response = await jsonRequest<{ templates: ChatTemplate[] }>(
            'GET',
            ChatTemplateOptionsController.url(),
        );

        // An older refresh that answers last must not overwrite a newer list.
        if (refresh !== latestRefresh) {
            return;
        }

        templates.value = response.templates;
        hasList = true;
        loadFailed.value = false;
    } catch {
        if (refresh !== latestRefresh) {
            return;
        }

        loadFailed.value = !hasList;
    } finally {
        if (refresh === latestRefresh) {
            loaded.value = true;
        }
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
