<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import MediaReplacementSettingsController from '@/actions/App/Http/Controllers/Admin/MediaReplacementSettingsController';
import MediaReplacementSettings from '@/components/media-replacement/MediaReplacementSettings.vue';
import type { MediaReplacementConfiguration } from '@/components/media-replacement/MediaReplacementSettings.vue';
import MediaReplacementTabs from '@/components/media-replacement/MediaReplacementTabs.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

interface EnumOption {
    value: string;
    label: string;
}

const props = defineProps<{
    settings: {
        media_replacement: MediaReplacementConfiguration;
    };
    seasonPackPolicies: EnumOption[];
    subtitleRuleStrengths: EnumOption[];
    conditionFields: EnumOption[];
    attentionCount: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard().url },
            {
                title: 'Media Replacement',
                href: MediaReplacementSettingsController.index.url(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Media Replacement" />

    <div class="flex max-w-3xl flex-col gap-4 p-5">
        <!-- Hero -->
        <div>
            <div class="mb-1.5 text-[13px] text-muted-foreground">
                Admin <span class="text-fg-subtle">/</span> Media replacement
            </div>
            <h1 class="text-[22px] leading-tight font-semibold tracking-tight">
                Media replacement
            </h1>
            <p class="mt-1 max-w-[640px] text-[13px] text-muted-foreground">
                Preferred subtitle languages, automatic candidate selection,
                season-pack policy, and scoped guidance rules used when
                replacing installed media.
            </p>
        </div>

        <MediaReplacementTabs :attention-count="props.attentionCount" />

        <Form
            v-bind="MediaReplacementSettingsController.update.form()"
            v-slot="{ errors, processing }"
            class="rounded-xl border border-border bg-card p-6"
        >
            <div class="flex flex-col gap-5">
                <MediaReplacementSettings
                    :configuration="props.settings.media_replacement"
                    :season-pack-policies="props.seasonPackPolicies"
                    :subtitle-rule-strengths="props.subtitleRuleStrengths"
                    :condition-fields="props.conditionFields"
                    :errors="errors"
                />

                <div class="flex justify-end pt-2">
                    <Button
                        size="sm"
                        type="submit"
                        :disabled="processing"
                        class="h-8 text-xs"
                    >
                        Save settings
                    </Button>
                </div>
            </div>
        </Form>
    </div>
</template>
