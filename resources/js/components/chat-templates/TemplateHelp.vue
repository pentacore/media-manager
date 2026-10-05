<script setup lang="ts">
import { ChevronRight, CircleHelp } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { EXAMPLE_TEMPLATE_BODY, EXAMPLE_TEMPLATE_RESULT } from './tokens';

const props = defineProps<{
    /** Used until the viewer has opened or closed the panel themselves. */
    defaultOpen: boolean;
}>();

const STORAGE_KEY = 'mm.chat-templates.help';

/** Token examples live in script: a literal "{{" inside a template interpolation breaks Vue's parser. */
const SEASON_TOKEN = '{{season}}';
const PART_ROWS = [
    { token: '{{anime}}', result: 'Frieren' },
    { token: '{{anime:title,year}}', result: 'Frieren (2023)' },
    {
        token: '{{anime:id}}',
        result: '(Sonarr series id 42)',
        note: 'so it can find it directly',
    },
];

const open = ref(props.defaultOpen);

// Read only after mount, so the server-rendered markup matches the first
// client render.
onMounted(() => {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);

        if (stored === 'open' || stored === 'closed') {
            open.value = stored === 'open';
        }
    } catch {
        // localStorage disabled — keep the page default.
    }
});

function setOpen(value: boolean): void {
    open.value = value;

    try {
        localStorage.setItem(STORAGE_KEY, value ? 'open' : 'closed');
    } catch {
        // localStorage full or disabled — the choice lasts for this visit.
    }
}
</script>

<template>
    <Collapsible
        :open="open"
        class="rounded-lg border border-border bg-card"
        data-template-help
        @update:open="setOpen"
    >
        <CollapsibleTrigger as-child>
            <button
                type="button"
                class="flex w-full items-center gap-2 px-3 py-2 text-left text-[13px] font-medium"
                data-template-help-toggle
            >
                <CircleHelp class="size-4 text-muted-foreground" />
                How templates work
                <ChevronRight
                    class="ml-auto size-4 text-muted-foreground transition-transform"
                    :class="{ 'rotate-90': open }"
                />
            </button>
        </CollapsibleTrigger>
        <CollapsibleContent
            class="grid gap-4 border-t border-border px-3 py-3 text-[12.5px] text-muted-foreground"
        >
            <ol class="grid list-decimal gap-1.5 pl-5">
                <li>
                    Write the message the way you'd type it to the assistant.
                </li>
                <li>
                    Put a placeholder like
                    <code class="text-foreground">{{ SEASON_TOKEN }}</code>
                    wherever something changes. Each placeholder becomes a field
                    you fill in when you use the template.
                </li>
                <li>
                    Choose a type for each variable:
                    <strong class="text-foreground">Text</strong>,
                    <strong class="text-foreground">Number</strong>,
                    <strong class="text-foreground">Choice</strong> (from a list
                    you define), or
                    <strong class="text-foreground">Series / Movie</strong>
                    (picked from your library).
                </li>
            </ol>

            <div class="grid gap-1.5">
                <p>A series or movie placeholder can pick what to include:</p>
                <table class="w-full max-w-[560px] text-left text-[12.5px]">
                    <thead>
                        <tr class="border-b border-border text-fg-subtle">
                            <th class="py-1 pr-4 font-medium">You write</th>
                            <th class="py-1 font-medium">The assistant gets</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in PART_ROWS"
                            :key="row.token"
                            class="border-b border-border last:border-b-0"
                        >
                            <td class="py-1 pr-4">
                                <code class="text-foreground">{{
                                    row.token
                                }}</code>
                            </td>
                            <td class="py-1">
                                <code class="text-foreground">{{
                                    row.result
                                }}</code>
                                <template v-if="row.note"
                                    >, {{ row.note }}</template
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="grid gap-1">
                <p>For example, the template</p>
                <code class="text-foreground">{{ EXAMPLE_TEMPLATE_BODY }}</code>
                <p>sends</p>
                <code class="text-foreground">{{
                    EXAMPLE_TEMPLATE_RESULT
                }}</code>
            </div>
        </CollapsibleContent>
    </Collapsible>
</template>
